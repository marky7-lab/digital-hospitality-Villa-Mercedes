<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Enums\Role;
use App\Models\Facility;
use App\Models\Reservation;
use App\Models\User;
use App\Notifications\Payment\PaymentStatusChangedNotification;
use App\Notifications\Payment\PaymentSubmittedNotification;
use App\Notifications\Reservation\ReservationCreatedMail;
use App\Notifications\Reservation\ReservationCreatedNotification;
use App\Notifications\Reservation\ReservationStatusChangedMail;
use App\Notifications\Reservation\ReservationStatusChangedNotification;
use App\Notifications\Reservation\ReviewInvitationMail;
use App\Observers\ReservationObserver;
use App\Services\PaymentService;
use App\Services\ReservationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class NotificationEngineTest extends TestCase
{
    use RefreshDatabase;

    private ReservationService $reservationService;

    private PaymentService $paymentService;

    private User $customer;

    private User $admin;

    private Facility $facility;

    protected function setUp(): void
    {
        parent::setUp();

        // Preserve the historical service transitions this notification suite
        // exercises; confirmed-policy notifications have dedicated workflow tests.
        $this->travelTo(Carbon::parse('2026-09-01 00:00:00'));
        config(['resort.policy.version' => '2026-09-v2']);
        $this->seedReservationCatalog();

        $this->reservationService = app(ReservationService::class);
        $this->paymentService = app(PaymentService::class);

        $this->customer = User::factory()->create();
        $this->customer->assignRole(Role::Customer->value);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Role::Administrator->value);

        $this->facility = Facility::where('slug', 'deluxe-room-26')->firstOrFail();
    }

    public function test_reservation_creation_triggers_notifications(): void
    {
        Notification::fake();

        $customerEmail = $this->customer->email;

        $reservation = $this->reservationService->createReservation(
            $this->facility,
            $this->customer,
            Carbon::parse('2026-10-01 14:00:00'),
            Carbon::parse('2026-10-03 12:00:00'),
            ReservationSource::Online,
            2
        );

        // Assert customer received it
        Notification::assertSentTo(
            $this->customer,
            ReservationCreatedNotification::class,
            function ($notification) use ($reservation) {
                return $notification->reservation->id === $reservation->id
                    && $notification->isAdminNotification === false;
            }
        );

        // Assert admin received it
        Notification::assertSentTo(
            $this->admin,
            ReservationCreatedNotification::class,
            function ($notification) use ($reservation) {
                return $notification->reservation->id === $reservation->id
                    && $notification->isAdminNotification === true;
            }
        );

        Notification::assertSentOnDemand(
            ReservationCreatedMail::class,
            function ($notification, $channels, $notifiable) use ($reservation, $customerEmail) {
                return $notification->reservation->id === $reservation->id
                    && in_array('mail', $channels, true)
                    && ($notifiable->routes['mail'] ?? null) === $customerEmail;
            }
        );

        $guestNotice = new ReservationCreatedNotification($reservation->fresh()->load('facility'), false);
        $this->assertSame(['database'], $guestNotice->via($this->customer));
        $mail = (new ReservationCreatedMail($reservation->fresh()->load('facility')))->toMail($this->customer)->render();
        $this->assertStringContainsString($reservation->reservation_code, $mail);
        // A request under review is copied as a request, not as a confirmed stay.
        $this->assertStringContainsString('Thanks for sending your booking request.', $mail);
        $this->assertStringContainsString('Our team is reviewing it.', $mail);
        $this->assertStringContainsString($this->facility->name, $mail);
        $this->assertStringContainsString('₱', $mail);

        $adminNotice = new ReservationCreatedNotification($reservation, true);
        $this->assertSame(['database'], $adminNotice->via($this->admin));
    }

    public function test_database_worker_renders_queued_reservation_emails(): void
    {
        config(['queue.default' => 'database']);

        $reservation = Reservation::withoutEvents(fn () => Reservation::factory()
            ->for($this->customer)
            ->for($this->facility)
            ->create());

        Notification::route('mail', $this->customer->email)
            ->notify(new ReservationCreatedMail($reservation));

        $this->assertDatabaseCount('jobs', 1);
        $sent = [];
        Event::listen(NotificationSent::class, function (NotificationSent $event) use (&$sent): void {
            $sent[] = $event;
        });
        $job = Queue::connection('database')->pop();
        $this->assertNotNull($job);
        $job->fire();
        $job->delete();

        $this->assertCount(1, $sent);
        $this->assertSame('mail', $sent[0]->channel);
        $this->assertInstanceOf(ReservationCreatedMail::class, $sent[0]->notification);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_reservation_email_transport_failure_does_not_escape_after_commit_observer(): void
    {
        // The guest email is now routed to the booking's address rather than to
        // the account's notifiable, so the failing transport is simulated at the
        // mail channel itself — the same way the review-invitation test does it.
        config(['mail.default' => 'smtp']);

        Notification::extend('mail', fn () => new class
        {
            public function send(object $notifiable, object $notification): void
            {
                throw new TransportException('Simulated SMTP DNS failure.');
            }
        });

        $reservation = Reservation::withoutEvents(fn () => Reservation::factory()
            ->for($this->customer)
            ->for($this->facility)
            ->create());

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'Reservation email could not be dispatched.'
                && $context['reservation_id'] === $reservation->id
                && $context['notification'] === ReservationCreatedMail::class
                && $context['exception'] === TransportException::class
            );

        (new ReservationObserver)->created($reservation);

        $this->assertDatabaseHas('reservations', ['id' => $reservation->id]);
    }

    public function test_checkout_survives_a_review_invitation_mail_transport_failure(): void
    {
        config(['mail.default' => 'smtp']);

        Notification::extend('mail', fn () => new class
        {
            public function send(object $notifiable, object $notification): void
            {
                throw new TransportException('Simulated SMTP transport failure.');
            }
        });

        $reservation = Reservation::withoutEvents(fn () => Reservation::factory()
            ->for($this->customer)
            ->for($this->facility)
            ->create([
                'status' => ReservationStatus::Approved->value,
                'checked_in_at' => now()->subDay(),
                'checked_out_at' => null,
            ]));

        Log::spy();

        $this->reservationService->checkOut($reservation, $this->admin);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => $message === 'Review invitation email could not be dispatched.'
                && $context['reservation_id'] === $reservation->id
                && $context['notification'] === ReviewInvitationMail::class
                && $context['exception'] === TransportException::class
            );

        // The review invitation is sent before the status notification, so a
        // mailer outage must not swallow the status email that follows it.
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message): bool => $message === 'Reservation email could not be dispatched.');

        $completed = $reservation->fresh();
        $this->assertSame(ReservationStatus::Completed, $completed->status);
        $this->assertNotNull($completed->checked_out_at);
        $this->assertNotNull($completed->review_invited_at);

        $this->actingAs($this->customer)
            ->get(route('customer.reservations.show', $reservation))
            ->assertOk()
            ->assertSee('Rate your experience');
    }

    public function test_local_log_mailer_keeps_review_email_enabled(): void
    {
        config(['mail.default' => 'log']);
        Notification::fake();

        $reservation = Reservation::withoutEvents(fn () => Reservation::factory()
            ->for($this->customer)
            ->for($this->facility)
            ->create([
                'status' => ReservationStatus::Approved->value,
                'checked_in_at' => now()->subDay(),
                'checked_out_at' => null,
            ]));

        $this->reservationService->checkOut($reservation, $this->admin);

        Notification::assertSentTo($this->customer, ReviewInvitationMail::class);
    }

    public function test_reservation_status_change_triggers_notification(): void
    {
        Notification::fake();

        $reservation = $this->reservationService->createReservation(
            $this->facility,
            $this->customer,
            Carbon::parse('2026-11-01 14:00:00'),
            Carbon::parse('2026-11-03 12:00:00'),
            ReservationSource::Online,
            2
        );

        // Clear fake to ignore the creation notifications
        Notification::fake();

        $customerEmail = $this->customer->email;

        $this->reservationService->changeStatus($reservation, ReservationStatus::Approved, $this->admin);

        Notification::assertSentTo(
            $this->customer,
            ReservationStatusChangedNotification::class,
            function ($notification) use ($reservation) {
                return $notification->reservation->id === $reservation->id;
            }
        );

        Notification::assertSentOnDemand(
            ReservationStatusChangedMail::class,
            function ($notification, $channels, $notifiable) use ($reservation, $customerEmail) {
                return $notification->reservation->id === $reservation->id
                    && in_array('mail', $channels, true)
                    && ($notifiable->routes['mail'] ?? null) === $customerEmail;
            }
        );

        $approvedMail = (new ReservationStatusChangedMail($reservation->fresh()->load('facility')))
            ->toMail($this->customer)
            ->render();
        $this->assertStringContainsString('Your booking is confirmed', $approvedMail);
        $this->assertStringContainsString($reservation->reservation_code, $approvedMail);
    }

    public function test_rejection_status_change_notifies_customer_by_email_with_reason(): void
    {
        Notification::fake();

        $reservation = $this->reservationService->createReservation(
            $this->facility,
            $this->customer,
            Carbon::parse('2026-11-05 14:00:00'),
            Carbon::parse('2026-11-07 12:00:00'),
            ReservationSource::Online,
            2
        );

        Notification::fake();
        $customerEmail = $this->customer->email;
        $this->reservationService->changeStatus($reservation, ReservationStatus::Rejected, $this->admin, 'The selected dates are no longer available.');

        Notification::assertSentTo($this->customer, ReservationStatusChangedNotification::class);
        Notification::assertSentOnDemand(
            ReservationStatusChangedMail::class,
            function ($notification, $channels, $notifiable) use ($reservation, $customerEmail) {
                return $notification->reservation->id === $reservation->id
                    && in_array('mail', $channels, true)
                    && ($notifiable->routes['mail'] ?? null) === $customerEmail;
            }
        );

        $rejectedMail = (new ReservationStatusChangedMail($reservation->fresh()->load('facility')))
            ->toMail($this->customer)
            ->render();
        $this->assertStringContainsString('We could not confirm this reservation.', $rejectedMail);
        $this->assertStringContainsString('The selected dates are no longer available.', $rejectedMail);
    }

    public function test_payment_submission_triggers_admin_notification(): void
    {
        $reservation = $this->reservationService->createReservation(
            $this->facility,
            $this->customer,
            Carbon::parse('2026-12-01 14:00:00'),
            Carbon::parse('2026-12-03 12:00:00'),
            ReservationSource::Online,
            2
        );

        $this->reservationService->changeStatus($reservation, ReservationStatus::Approved, $this->admin);

        Notification::fake();

        $payment = $this->paymentService->createPayment(
            $reservation,
            $this->customer,
            1500,
            PaymentMethod::GCash
        );

        Notification::assertSentTo(
            $this->admin,
            PaymentSubmittedNotification::class,
            function ($notification) use ($payment) {
                return $notification->payment->id === $payment->id;
            }
        );

        // Customers do not get a submitted notification, only admins
        Notification::assertNotSentTo($this->customer, PaymentSubmittedNotification::class);
    }

    public function test_payment_verification_triggers_customer_notification(): void
    {
        $reservation = $this->reservationService->createReservation(
            $this->facility,
            $this->customer,
            Carbon::parse('2027-01-01 14:00:00'),
            Carbon::parse('2027-01-03 12:00:00'),
            ReservationSource::Online,
            2
        );

        $this->reservationService->changeStatus($reservation, ReservationStatus::Approved, $this->admin);

        $payment = $this->paymentService->createPayment(
            $reservation,
            $this->customer,
            1500,
            PaymentMethod::GCash
        );

        Notification::fake();

        $this->paymentService->verifyPayment($payment, $this->admin);

        Notification::assertSentTo(
            $this->customer,
            PaymentStatusChangedNotification::class,
            function ($notification) use ($payment) {
                return $notification->payment->id === $payment->id;
            }
        );
    }

    public function test_payment_rejection_triggers_customer_notification_with_reason(): void
    {
        $reservation = $this->reservationService->createReservation(
            $this->facility,
            $this->customer,
            Carbon::parse('2027-02-01 14:00:00'),
            Carbon::parse('2027-02-03 12:00:00'),
            ReservationSource::Online,
            2
        );

        $this->reservationService->changeStatus($reservation, ReservationStatus::Approved, $this->admin);

        $payment = $this->paymentService->createPayment(
            $reservation,
            $this->customer,
            1500,
            PaymentMethod::GCash
        );

        Notification::fake();

        $this->paymentService->rejectPayment($payment, $this->admin, 'Blurry image');

        Notification::assertSentTo(
            $this->customer,
            PaymentStatusChangedNotification::class,
            function ($notification) use ($payment) {
                $arrayData = $notification->toArray($this->customer);

                return $notification->payment->id === $payment->id
                    && $arrayData['reason'] === 'Blurry image';
            }
        );
    }

    public function test_notification_payload_contains_no_sensitive_data(): void
    {
        $reservation = $this->reservationService->createReservation(
            $this->facility,
            $this->customer,
            Carbon::parse('2027-03-01 14:00:00'),
            Carbon::parse('2027-03-03 12:00:00'),
            ReservationSource::Online,
            2
        );

        $notification = new ReservationCreatedNotification($reservation, false);
        $arrayData = $notification->toArray($this->customer);

        $this->assertArrayHasKey('reservation_id', $arrayData);
        $this->assertArrayHasKey('reservation_code', $arrayData);

        // Assert no full model serialization or passwords
        $this->assertArrayNotHasKey('password', $arrayData);
        $this->assertArrayNotHasKey('remember_token', $arrayData);
        $this->assertArrayNotHasKey('user_id', $arrayData); // we didn't include it in toArray
    }

    public function test_rolled_back_reservation_does_not_notify(): void
    {
        Notification::fake();

        DB::beginTransaction();

        $this->reservationService->createReservation(
            $this->facility,
            $this->customer,
            Carbon::parse('2027-04-01 14:00:00'),
            Carbon::parse('2027-04-03 12:00:00'),
            ReservationSource::Online,
            2
        );

        Notification::assertNothingSent();

        DB::rollBack();

        Notification::assertNothingSent();
    }

    public function test_committed_reservation_notifies_after_outer_commit(): void
    {
        Notification::fake();

        DB::beginTransaction();

        $reservation = $this->reservationService->createReservation(
            $this->facility,
            $this->customer,
            Carbon::parse('2027-05-01 14:00:00'),
            Carbon::parse('2027-05-03 12:00:00'),
            ReservationSource::Online,
            2
        );

        Notification::assertNothingSent();

        DB::commit();

        Notification::assertSentTo(
            $this->customer,
            ReservationCreatedNotification::class,
            function ($notification) use ($reservation) {
                return $notification->reservation->id === $reservation->id
                    && $notification->isAdminNotification === false;
            }
        );
    }

    public function test_walk_in_guest_receives_a_reservation_copy_by_email(): void
    {
        Notification::fake();

        $reservation = $this->reservationService->createReservation(
            $this->facility,
            $this->admin,
            Carbon::parse('2027-06-01 14:00:00'),
            Carbon::parse('2027-06-03 12:00:00'),
            ReservationSource::WalkIn,
            2,
            [
                'guest_name' => 'Juan Dela Cruz',
                'guest_email' => 'juan.walkin@example.com',
            ]
        );

        Notification::assertSentOnDemand(
            ReservationCreatedMail::class,
            function ($notification, $channels, $notifiable) use ($reservation) {
                return $notification->reservation->id === $reservation->id
                    && in_array('mail', $channels, true)
                    && ($notifiable->routes['mail'] ?? null) === 'juan.walkin@example.com';
            }
        );

        $staffNotice = new ReservationCreatedNotification($reservation->fresh(), false);
        $this->assertSame(['database'], $staffNotice->via($this->admin));
    }
}
