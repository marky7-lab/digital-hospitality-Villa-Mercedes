<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\Facility;
use App\Models\Payment;
use App\Models\PaymentDestination;
use App\Models\Reservation;
use App\Models\ReservationGroup;
use App\Models\User;
use App\Services\AvailabilityService;
use App\Services\BookingPracticeService;
use App\Services\CloseOutdatedBookings;
use App\Services\GroupedBookingService;
use App\Services\PaymentService;
use App\Services\ReservationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ConfirmedBookingPracticesTest extends TestCase
{
    use RefreshDatabase;

    private User $guest;

    private User $staff;

    private User $owner;

    private Facility $room;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-12 00:00:00'));
        $this->seedReservationCatalog();
        Notification::fake();
        $this->guest = User::factory()->create();
        $this->guest->assignRole(Role::Customer->value);
        $this->staff = User::role(Role::Administrator->value)->firstOrFail();
        $this->owner = User::factory()->create();
        $this->owner->assignRole([Role::Administrator->value, Role::Owner->value]);
        $this->room = Facility::whereHas('facilityType', fn ($q) => $q->where('slug', 'room'))->firstOrFail();
    }

    private function booking(string $start = '2026-11-10 14:00', string $end = '2026-11-11 12:00'): Reservation
    {
        $line = app(ReservationService::class)->createReservation($this->room, $this->guest, Carbon::parse($start), Carbon::parse($end), ReservationSource::Online, 1);
        $line->update(['status' => ReservationStatus::Approved]);

        return $line;
    }

    public function test_same_day_rooms_use_the_full_base_rate_at_different_times_and_recheck_conflicts(): void
    {
        foreach ([['09:00', '17:00'], ['07:00', '11:00'], ['16:00', '22:00']] as [$start, $end]) {
            $line = $this->booking('2026-11-10 '.$start, '2026-11-10 '.$end);
            $this->assertEquals($line->base_price, $line->total_price);
            $this->assertEquals(0, $line->additional_charges);
            $this->assertFalse(app(AvailabilityService::class)->isAvailable($this->room, $line->check_in, $line->check_out)->isAvailable);
            $this->actingAs($this->guest)->postJson(route('facilities.availability.calculate', $this->room), [
                'check_in' => '2026-11-10 '.$start, 'check_out' => '2026-11-10 '.$end, 'guestCount' => 1,
            ])->assertUnprocessable()->assertJsonPath('availability.is_available', false);
            $line->delete();
        }
    }

    public function test_departure_and_automatic_departure_require_explicit_staff_cleaning_release(): void
    {
        foreach ([false, true] as $automatic) {
            $line = $this->booking();
            $line->update(['checked_in_at' => now()]);
            if ($automatic) {
                $this->travelTo(Carbon::parse('2026-11-11 04:00:00'));
                $this->assertTrue(app(ReservationService::class)->checkOutAutomatically($line));
            } else {
                app(ReservationService::class)->checkOut($line, $this->staff);
            }
            $room = $this->room->fresh();
            $availability = app(AvailabilityService::class)->isAvailable($room, Carbon::parse('2026-11-12 09:00'), Carbon::parse('2026-11-12 17:00'));
            $this->assertFalse($availability->isAvailable);
            $this->assertStringContainsString('awaiting cleaning', $availability->reason);
            $this->actingAs($this->staff)->patch(route('admin.facilities.cleaning-complete', $room))->assertSessionHasNoErrors();
            $this->assertNull($room->fresh()->cleaning_required_at);
            $this->assertTrue(app(AvailabilityService::class)->isAvailable($room->fresh(), Carbon::parse('2026-11-12 09:00'), Carbon::parse('2026-11-12 17:00'))->isAvailable);
            $this->assertSame(1, ActivityLog::where('subject_type', Facility::class)->where('subject_id', $room->id)->where('event', 'cleaning_completed')->count());
            $line->delete();
            ActivityLog::where('subject_type', Facility::class)->where('subject_id', $room->id)->delete();
            $this->travelTo(Carbon::parse('2026-10-12 00:00:00'));
        }
    }

    public function test_early_arrival_boundaries(): void
    {
        foreach ([19 => 0, 20 => 1, 60 => 1, 61 => 2] as $minutes => $hours) {
            $this->assertSame($hours, ReservationService::earlyArrivalHours($minutes));
            $line = $this->booking();
            $this->travelTo(Carbon::parse('2026-11-10 06:00:00')->subMinutes($minutes));
            app(ReservationService::class)->reviewArrival($line, $this->staff);
            $this->assertEquals($hours * 200, $line->fresh()->total_price - $line->base_price);
            app(ReservationService::class)->reviewArrival($line, $this->staff);
            $this->assertEquals($hours * 200, $line->fresh()->total_price - $line->base_price, 'Repeated check in review is idempotent.');
            $line->delete();
            $this->travelTo(Carbon::parse('2026-10-12 00:00:00'));
        }
    }

    public function test_no_show_refund_requires_owner_approval_then_separate_return_record(): void
    {
        $line = $this->booking();
        $originalPrice = $line->total_price;
        $policy = $line->policy_version;
        Payment::factory()->create(['reservation_id' => $line->id, 'amount' => 500, 'status' => PaymentStatus::Verified]);
        $this->travelTo(Carbon::parse('2026-11-12 00:00:00'));
        $this->actingAs($this->guest)->patch(route('customer.reservations.discussion', $line), ['action' => 'request_refund', 'reason' => 'Did not arrive'])->assertSessionHasNoErrors();
        $this->assertSame('requested', $line->fresh()->booking_workflow['refund']['status']);
        $this->actingAs($this->guest)->patch(route('admin.reservations.discussion', $line), ['action' => 'approve_refund', 'reason' => 'Unauthorized'])->assertRedirect(route('admin.login'));
        $this->assertSame('requested', $line->fresh()->booking_workflow['refund']['status']);
        $this->actingAs($this->staff)->patch(route('admin.reservations.discussion', $line), ['action' => 'approve_refund'])->assertSessionHasErrors('reason');
        $this->patch(route('admin.reservations.discussion', $line), ['action' => 'approve_refund', 'reason' => 'Owner instructed full return after no-show review.'])->assertSessionHasNoErrors();
        $this->assertSame('approved', $line->fresh()->booking_workflow['refund']['status']);
        $this->assertSame(500, $line->fresh()->booking_workflow['refund']['amount_cents'] / 100);
        $this->assertSame($this->staff->id, $line->fresh()->booking_workflow['refund']['approved_by']);
        $this->assertSame('Owner instructed full return after no-show review.', $line->fresh()->booking_workflow['refund']['owner_approval_note']);
        $this->patch(route('admin.reservations.discussion', $line), ['action' => 'return_funds', 'amount' => 500, 'reason' => 'Bank return REF-001'])->assertSessionHasNoErrors();
        $line->refresh();
        $this->assertSame('returned', $line->booking_workflow['refund']['status']);
        $this->assertEquals(0, $line->getRemainingBalance());
        $this->assertSame($originalPrice, $line->total_price);
        $this->assertSame($policy, $line->policy_version);
        $this->assertEquals(500, $line->getVerifiedTotalPaid());
        $this->assertSame(1, $line->activities()->where('event', 'funds_returned')->count());
        $this->actingAs($this->guest)->get(route('customer.reservations.show', $line))->assertOk()
            ->assertSee('cancelled, no check in balance collectible')->assertDontSee('total · fully paid');
        $this->get(route('customer.reservations.index', ['status' => 'cancelled']))->assertOk()
            ->assertSee('Funds returned')->assertSee('No check in balance collectible')->assertDontSee('Fully paid');
        $this->actingAs($this->staff)->get(route('admin.reservations.show', $line))->assertOk()
            ->assertSee('Cancelled — no check in balance collectible')->assertDontSee('Still to collect')->assertDontSee('Settled in full');
    }

    public function test_group_pet_fee_children_discussion_and_overpayment_remain_auditable(): void
    {
        $rooms = Facility::whereHas('facilityType', fn ($q) => $q->where('slug', 'room'))->take(2)->get();
        $lines = $rooms->map(fn ($room) => ['facility_id' => $room->id, 'check_in' => '2026-11-10 09:00', 'check_out' => '2026-11-10 17:00',
            'guest_count' => 1, 'infants_count' => 2, 'children_count' => 1])->all();
        $group = app(GroupedBookingService::class)->createGroup($this->guest, $lines, $this->guestInformationPayload(), ReservationSource::Online,
            ['pets_included' => true, 'policies_accepted' => true]);
        $this->assertEquals($group->reservations->sum('base_price') + 500, $group->total_amount);
        $this->assertEquals(ceil($group->total_amount * 100 / 2) / 100, $group->deposit_required);
        $this->assertSame(2, $group->booking_workflow['infants_count']);
        $this->assertSame(1, $group->booking_workflow['children_count']);
        $line = $group->reservations->first();
        $service = app(BookingPracticeService::class);
        $service->childCharge($line, $this->owner, 15000, 'Owner reviewed older child occupancy.');
        $this->assertEquals($group->total_amount + 150, $group->fresh()->total_amount);
        $this->actingAs($this->guest)->get(route('customer.reservations.show', $line))->assertOk()->assertSee('Owner decides')->assertSee('Dispute child charge');
        $service->discussChildCharge($line, $this->guest, 'dispute', 'Discuss the amount first.');
        $line->update(['status' => ReservationStatus::Approved]);
        $this->actingAs($this->staff)->patch(route('admin.reservations.check-in', $line))->assertSessionHas('error');
        $this->assertNull($line->fresh()->checked_in_at);
        $service->discussChildCharge($line, $this->staff, 'resolve', 'Guest agreed after discussion.');
        Payment::factory()->create(['reservation_id' => null, 'reservation_group_id' => $group->id, 'amount' => $group->fresh()->total_amount, 'status' => PaymentStatus::Verified]);
        $service->arrivalDiscount($line, $this->staff, 10000, 'Check in discount approved by desk.');
        $this->assertEquals(100, $group->fresh()->verifiedPaymentsTotal() - $group->fresh()->total_amount);
        $this->assertEquals(500, $line->fresh()->priceItems()['Pets (one fee per booking)'] / 100);
        $this->assertSame(1, ActivityLog::where('subject_type', ReservationGroup::class)->where('subject_id', $group->id)->where('event', 'arrival_discount_recorded')->count());
    }

    public function test_group_discount_can_exceed_one_room_without_negative_lines_or_changing_received_funds(): void
    {
        $rooms = Facility::whereHas('facilityType', fn ($q) => $q->where('slug', 'room'))->orderBy('id')->take(2)->get();
        $group = app(GroupedBookingService::class)->createGroup($this->guest,
            $rooms->map(fn ($room) => ['facility_id' => $room->id, 'check_in' => '2026-11-10 09:00', 'check_out' => '2026-11-10 17:00', 'guest_count' => 1])->all(),
            $this->guestInformationPayload(), ReservationSource::Online, ['policies_accepted' => true]);
        $first = $group->reservations()->orderBy('id')->firstOrFail();
        $originalTotal = (float) $group->total_amount;
        $discount = (float) $first->total_price + 100;
        Payment::factory()->create(['reservation_id' => null, 'reservation_group_id' => $group->id, 'amount' => $originalTotal, 'status' => PaymentStatus::Verified]);

        app(BookingPracticeService::class)->arrivalDiscount($first, $this->staff, (int) round($discount * 100), 'Group check in discount agreed with owner.');

        $group->refresh();
        $this->assertEquals($originalTotal - $discount, $group->total_amount);
        $this->assertEquals($group->total_amount, $group->reservations()->sum('total_price'));
        $this->assertEquals(0, $first->fresh()->total_price);
        $this->assertEquals(0, $group->reservations()->where('total_price', '<', 0)->count());
        $this->assertEquals($originalTotal, $group->verifiedPaymentsTotal());
        $this->assertEquals($discount, $group->verifiedPaymentsTotal() - $group->total_amount);
        $this->assertCount(1, $group->booking_workflow['adjustments']);
        $this->assertSame(1, ActivityLog::where('subject_type', ReservationGroup::class)->where('subject_id', $group->id)->where('event', 'arrival_discount_recorded')->count());

        try {
            app(BookingPracticeService::class)->arrivalDiscount($first, $this->staff, (int) round($group->total_amount * 100) + 1, 'Too large.');
            $this->fail('A discount above the remaining group total must be refused.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('booking', $exception->errors());
        }
        $this->assertEquals($originalTotal - $discount, $group->fresh()->total_amount);
        $this->assertCount(1, $group->fresh()->booking_workflow['adjustments']);
    }

    public function test_50_and_100_percent_online_group_payments_remain_pending_until_verified(): void
    {
        foreach ([50, 100] as $percent) {
            $group = app(GroupedBookingService::class)->createGroup($this->guest,
                [['facility_id' => $this->room->id, 'check_in' => '2026-11-10 09:00', 'check_out' => '2026-11-10 17:00', 'guest_count' => 1]],
                $this->guestInformationPayload(), ReservationSource::Online, ['payment_percent' => $percent, 'policies_accepted' => true, 'payment' => ['reference_number' => 'TEST-'.$percent]]);
            $payment = $group->directPayments()->firstOrFail();
            $this->assertEquals($percent === 100 ? $group->total_amount : $group->deposit_required, $payment->amount);
            $this->assertSame(PaymentStatus::Pending, $payment->status);
            $this->assertEquals(0, $group->verifiedPaymentsTotal());
            app(PaymentService::class)->verifyPayment($payment, $this->staff);
            $this->assertEquals($payment->amount, $group->verifiedPaymentsTotal());
            $this->assertEquals($group->total_amount - $payment->amount, $group->outstandingBalance());
            $group->reservations()->delete();
        }
    }

    public function test_unpaid_balance_blocks_physical_arrival_and_historical_prices_are_not_rewritten(): void
    {
        config(['payments.deposit_workflow' => true]);
        // Use an approved fixture because the production online path correctly requires configured receiving instructions.
        $line = Reservation::factory()->create(['user_id' => $this->guest->id, 'facility_id' => $this->room->id, 'status' => ReservationStatus::Approved,
            'check_in' => '2026-11-10 14:00', 'check_out' => '2026-11-11 12:00', 'total_price' => 1000, 'base_price' => 1000,
            'deposit_required' => 500, 'policy_version' => config('resort.policy.confirmed_version')]);
        Payment::factory()->create(['reservation_id' => $line->id, 'amount' => 500, 'status' => PaymentStatus::Verified]);
        $this->actingAs($this->staff)->patch(route('admin.reservations.check-in', $line))->assertSessionHas('error');
        $this->assertNull($line->fresh()->checked_in_at);
        $line->update(['policy_version' => '2026-09-v2']);
        $this->room->rates()->update(['amount' => 9999]);
        $this->assertEquals(1000, $line->fresh()->total_price);
        $this->assertSame('2026-09-v2', $line->fresh()->policy_version);
    }

    public function test_customer_staff_checkout_and_receipt_surfaces_render_saved_policy_and_refund_stages(): void
    {
        $line = $this->booking();
        $this->actingAs($this->guest)->get(route('customer.grouped-booking.workspace'))->assertOk()->assertSee('100% full online payment')->assertSee('Same-day check in')->assertDontSee('non-refundable');
        $this->get(route('terms'))->assertOk()->assertSee('full refund')->assertSee('2026-10-confirmed-v1')->assertDontSee('nonrefundable');
        $this->get(route('customer.reservations.show', $line))->assertOk()->assertSee('Booking discussions')->assertSee('Request cancellation and full refund');
        $this->actingAs($this->staff)->get(route('admin.reservations.show', $line))->assertOk()->assertSee('Review early check-in charge')->assertDontSee('Record owner-approved full refund');
        app(BookingPracticeService::class)->requestCancellation($line, $this->guest, 'Cannot travel');
        $this->actingAs($this->staff)->get(route('admin.reservations.show', $line))->assertOk()->assertSee('Record owner-approved full refund')->assertSee('Owner approval / instruction');
        $payment = Payment::factory()->create(['reservation_id' => $line->id, 'amount' => 500, 'status' => PaymentStatus::Verified]);
        $this->actingAs($this->guest)->get(route('customer.payments.receipt', $payment))->assertOk()->assertSee('Accepted policy')->assertSee('2026-10-confirmed-v1');
    }

    public function test_refund_guidance_shows_the_next_action_and_blocks_approval_until_proofs_are_reviewed(): void
    {
        $line = $this->booking();
        $payment = Payment::factory()->create(['reservation_id' => $line->id, 'amount' => 500, 'status' => PaymentStatus::Pending]);
        $service = app(BookingPracticeService::class);
        $service->requestCancellation($line, $this->guest, 'Cannot travel');
        $this->actingAs($this->staff)->get(route('admin.reservations.show', $line))
            ->assertOk()->assertSee('Refund progress')->assertSee('verify or reject all pending payment proofs')
            ->assertDontSee('name="action" value="approve_refund"', false);
        $this->actingAs($this->guest)->get(route('customer.reservations.show', $line))
            ->assertOk()->assertSee('Refund requested — awaiting owner approval')->assertSee('staff check your payment proofs')->assertSee('aria-current="step"', false);
        $payment->update(['status' => PaymentStatus::Verified]);
        $service->approveRefund($line->fresh(), $this->staff, 'Owner approved full return.');
        $this->actingAs($this->staff)->get(route('admin.reservations.show', $line))
            ->assertOk()->assertSee('This system does not transfer money.')->assertSee('Record returned funds');
        $this->actingAs($this->guest)->get(route('customer.reservations.show', $line))
            ->assertOk()->assertSee('Owner approved — awaiting return')->assertSee('Approval does not send money.')->assertDontSee('name="action" value="return_funds"', false);
        $service->recordReturnedFunds($line->fresh(), $this->staff, 50000, 'RETURN-500');
        $this->get(route('customer.reservations.show', $line))->assertOk()
            ->assertSee('Funds returned — refund complete')->assertSee('RETURN-500')->assertSee('Keep the return reference');
    }

    public function test_no_show_cleanup_never_invents_physical_check_in(): void
    {
        $line = $this->booking();
        Payment::factory()->create(['reservation_id' => $line->id, 'amount' => 500, 'status' => PaymentStatus::Verified]);
        $this->travelTo(Carbon::parse('2026-11-12 00:00'));
        app(CloseOutdatedBookings::class)->close();
        $this->assertNull($line->fresh()->checked_in_at);
        app(BookingPracticeService::class)->requestCancellation($line, $this->guest, 'No-show refund');
        $this->assertSame('requested', $line->fresh()->booking_workflow['refund']['status']);
    }

    public function test_staff_approval_rechecks_same_day_conflicts_and_requires_verified_deposit(): void
    {
        $line = $this->booking('2026-11-10 09:00', '2026-11-10 17:00');
        $line->update(['status' => ReservationStatus::Pending]);
        $this->actingAs($this->staff)->patch(route('admin.reservations.approve', $line))->assertSessionHas('error');
        Payment::factory()->create(['reservation_id' => $line->id, 'amount' => $line->requiredDepositAmount(), 'status' => PaymentStatus::Verified]);
        Reservation::factory()->create(['facility_id' => $this->room->id, 'status' => ReservationStatus::Approved,
            'check_in' => '2026-11-10 16:00', 'check_out' => '2026-11-10 20:00']);
        $this->patch(route('admin.reservations.approve', $line))->assertSessionHas('error');
        $this->assertSame(ReservationStatus::Pending, $line->fresh()->status);
    }

    public function test_a_conflict_or_changed_rate_after_guest_acceptance_does_not_replace_the_booking(): void
    {
        $line = $this->booking();
        $service = app(BookingPracticeService::class);
        $selection = ['facility_id' => $this->room->id, 'check_in' => '2026-11-12 09:00', 'check_out' => '2026-11-12 17:00', 'guest_count' => 1];
        $service->proposeChange($line, $this->staff, $selection);
        $service->acceptChange($line, $this->guest, $line->fresh()->booking_workflow['change']['quote_cents']);
        $this->room->rates()->where('type', 'base')->update(['amount' => 9999]);
        $this->actingAs($this->staff)->patch(route('admin.reservations.discussion', $line), ['action' => 'confirm_change'])->assertSessionHasErrors('booking');
        $this->assertSame('2026-11-10 14:00:00', $line->fresh()->check_in->toDateTimeString());
        Reservation::factory()->create(['facility_id' => $this->room->id, 'status' => ReservationStatus::Approved, 'check_in' => $selection['check_in'], 'check_out' => $selection['check_out']]);
        $this->patch(route('admin.reservations.discussion', $line), ['action' => 'confirm_change'])->assertSessionHas('error');
        $this->assertSame('2026-11-10 14:00:00', $line->fresh()->check_in->toDateTimeString());
    }

    public function test_full_online_payment_through_customer_payment_endpoint_and_minimum_deposit(): void
    {
        config(['payments.deposit_workflow' => true]);
        Storage::fake('uploads');
        $destination = PaymentDestination::create(['method' => 'gcash', 'account_name' => 'Synthetic account', 'account_number' => 'TEST', 'qr_path' => 'synthetic.png', 'is_active' => true]);
        $line = $this->booking();
        $data = ['amount' => 1, 'payment_method' => 'gcash', 'payment_destination_id' => $destination->id,
            'proof' => UploadedFile::fake()->image('proof.png')];
        $this->actingAs($this->guest)->post(route('customer.payments.store', $line), $data)->assertSessionHas('error');
        $this->assertDatabaseCount('payments', 0);
        $data['amount'] = $line->total_price;
        $this->post(route('customer.payments.store', $line), $data)->assertSessionHasNoErrors();
        $payment = $line->payments()->firstOrFail();
        $this->assertEquals($line->total_price, $payment->amount);
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        app(PaymentService::class)->verifyPayment($payment, $this->staff);
        $this->assertEquals(0, $line->fresh()->getRemainingBalance());
    }

    public function test_50_and_100_percent_choices_save_exact_pending_group_proofs_through_checkout(): void
    {
        Storage::fake('uploads');
        $destination = PaymentDestination::create(['method' => 'gcash', 'account_name' => 'Synthetic receiving account',
            'account_number' => 'TEST', 'qr_path' => 'synthetic.png', 'is_active' => true]);
        foreach ([50, 100] as $percent) {
            $response = $this->actingAs($this->guest)->post(route('customer.grouped-booking.store'), [
                ...$this->guestInformationPayload(), 'policy_version' => config('resort.policy.version'), 'payment_percent' => $percent,
                'lines' => [['facility_id' => $this->room->id, 'check_in' => '2026-11-10 09:00', 'check_out' => '2026-11-10 17:00', 'guest_count' => 1]],
                'payment_destination_id' => $destination->id, 'payment_method' => 'gcash', 'payment_date' => '2026-10-12',
                'reference_number' => 'GROUP-PERCENT-'.$percent, 'proof' => UploadedFile::fake()->image('proof.png'),
                'pets_included' => 1,
            ]);
            $response->assertSessionHasNoErrors();
            $group = ReservationGroup::latest('id')->firstOrFail();
            $payment = $group->directPayments()->firstOrFail();
            $this->assertEquals($group->reservations->sum('base_price') + 500, $group->total_amount);
            $this->assertEquals($percent === 100 ? $group->total_amount : $group->deposit_required, $payment->amount);
            $this->assertSame(PaymentStatus::Pending, $payment->status);
            $group->reservations()->delete();
        }
    }

    public function test_quotation_email_renders_saved_policy_and_distinct_refund_stages(): void
    {
        $group = ReservationGroup::create(['user_id' => $this->guest->id, 'group_code' => 'GRP-POLICY-MAIL',
            'source' => 'online', 'status' => 'pending', 'guest_name' => $this->guest->name,
            'guest_email' => $this->guest->email, 'guest_phone' => '09171234567',
            'total_amount' => 1000, 'deposit_required' => 500, 'policy_version' => config('resort.policy.confirmed_version')]);
        $mail = (new \App\Notifications\Reservation\GroupQuotationProvidedMail($group))->toMail($this->guest);
        $html = app(\Illuminate\Mail\Markdown::class)->render($mail->markdown, $mail->viewData)->toHtml();
        $this->assertStringContainsString('pay in full online', $html);
        $this->assertStringContainsString('ask for a full refund', $html);
        $this->assertStringContainsString('The resort owner approves refund requests.', $html);
        $this->assertStringContainsString('We record when your refund is sent.', $html);
        $group->update(['policy_version' => '2026-09-v2']);
        $mail = (new \App\Notifications\Reservation\GroupQuotationProvidedMail($group))->toMail($this->guest);
        $html = app(\Illuminate\Mail\Markdown::class)->render($mail->markdown, $mail->viewData)->toHtml();
        $this->assertStringContainsString('The prices and terms already agreed for your booking still apply.', $html);
        $this->assertStringNotContainsString('ask for a full refund', $html);
    }

    public function test_single_service_quote_and_submission_keep_child_counts_and_one_pet_fee(): void
    {
        $payload = [...$this->guestInformationPayload(), 'facility_id' => $this->room->id,
            'check_in' => '2026-11-10 09:00', 'check_out' => '2026-11-10 17:00', 'guestCount' => 2,
            'infants_count' => 3, 'children_count' => 1, 'pets_included' => true];
        $quote = $this->actingAs($this->guest)->postJson(route('customer.booking.calculate', $this->room), $payload)
            ->assertOk()->assertJsonPath('data.pet_fee', 50000);
        $this->post(route('customer.reservations.store'), $payload)->assertSessionHasNoErrors()->assertSessionMissing('error');
        $line = Reservation::latest('id')->firstOrFail();
        $this->assertSame(1, $line->booking_workflow['children_count']);
        $this->assertSame(3, $line->booking_workflow['infants_count']);
        $this->assertEquals($line->base_price + 500, $line->total_price);
        $this->assertEquals($quote->json('data.total_amount'), $line->total_price * 100);
    }

    public function test_checkout_preserves_line_child_counts_for_owner_discussion(): void
    {
        Storage::fake('uploads');
        $destination = PaymentDestination::create(['method' => 'gcash', 'account_name' => 'Synthetic account',
            'account_number' => 'TEST', 'qr_path' => 'synthetic.png', 'is_active' => true]);
        $this->actingAs($this->guest)->post(route('customer.grouped-booking.store'), [
            ...$this->guestInformationPayload(), 'payment_method' => 'gcash', 'payment_destination_id' => $destination->id,
            'payment_date' => '2026-10-12', 'reference_number' => 'CHILD-COUNTS', 'proof' => UploadedFile::fake()->image('proof.png'),
            'lines' => [['facility_id' => $this->room->id, 'check_in' => '2026-11-10 09:00', 'check_out' => '2026-11-10 17:00',
                'guest_count' => 2, 'children_count' => 1, 'infants_count' => 3]],
        ])->assertSessionHasNoErrors()->assertSessionMissing('error');
        $group = ReservationGroup::latest('id')->firstOrFail();
        $this->assertSame(1, $group->booking_workflow['children_count']);
        $this->assertSame(3, $group->booking_workflow['infants_count']);
        $line = $group->reservations()->firstOrFail();
        $this->actingAs($this->owner)->patch(route('admin.reservations.discussion', $line), [
            'action' => 'child_charge', 'amount' => 150, 'reason' => 'Owner decision after base deposit',
        ])->assertSessionHasNoErrors();
        $this->assertSame(15000, $group->fresh()->booking_workflow['child_charge']['amount_cents']);
    }

    public function test_discussion_endpoint_cannot_replace_or_discount_historical_contracts(): void
    {
        $line = $this->booking();
        $line->update(['policy_version' => '2026-09-v2']);
        $original = $line->only(['check_in', 'check_out', 'total_price', 'policy_version', 'booking_workflow']);
        $this->actingAs($this->guest)->patch(route('customer.reservations.discussion', $line), [
            'action' => 'propose_change', 'facility_id' => $this->room->id,
            'check_in' => '2026-11-12 09:00', 'check_out' => '2026-11-12 17:00', 'guest_count' => 1,
        ])->assertSessionHasErrors('booking');
        $this->actingAs($this->staff)->patch(route('admin.reservations.discussion', $line), [
            'action' => 'arrival_discount', 'amount' => 100, 'reason' => 'Do not change historical contract',
        ])->assertSessionHasErrors('booking');
        $this->assertEquals($original, $line->fresh()->only(array_keys($original)));
    }

    public function test_paid_group_and_disputed_booking_are_not_expired_by_the_scheduler(): void
    {
        config(['payments.deposit_workflow' => true]);
        PaymentDestination::create(['method' => 'gcash', 'account_name' => 'Synthetic account',
            'account_number' => 'TEST', 'qr_path' => 'synthetic.png', 'is_active' => true]);
        $group = app(GroupedBookingService::class)->createGroup($this->guest,
            [['facility_id' => $this->room->id, 'check_in' => '2026-11-10 09:00', 'check_out' => '2026-11-10 17:00', 'guest_count' => 1]],
            $this->guestInformationPayload(), ReservationSource::Online, ['payment' => ['reference_number' => 'DO-NOT-EXPIRE']]);
        $line = $group->reservations()->firstOrFail();
        $line->update(['deposit_due_at' => now()->subMinute()]);
        $this->artisan('reservations:expire-unpaid')->assertSuccessful();
        $this->assertSame(ReservationStatus::Pending, $line->fresh()->status);
        $group->directPayments()->delete();
        $group->update(['booking_workflow' => ['child_charge' => ['status' => 'disputed']]]);
        $this->artisan('reservations:expire-unpaid')->assertSuccessful();
        $this->assertSame(ReservationStatus::Pending, $line->fresh()->status);
    }

    public function test_rejected_paid_booking_still_has_a_full_owner_refund_path(): void
    {
        $line = $this->booking();
        $line->update(['status' => ReservationStatus::Rejected]);
        Payment::factory()->create(['reservation_id' => $line->id, 'amount' => 500, 'status' => PaymentStatus::Verified]);
        $this->actingAs($this->guest)->patch(route('customer.reservations.discussion', $line), [
            'action' => 'request_refund', 'reason' => 'Service declined before check in',
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->staff)->patch(route('admin.reservations.discussion', $line), ['action' => 'approve_refund', 'reason' => 'Owner approved refund for declined service.'])->assertSessionHasNoErrors();
        $this->assertSame(50000, $line->fresh()->booking_workflow['refund']['amount_cents']);
        $this->assertNull($line->fresh()->checked_in_at);
    }

    public function test_policy_publication_updates_deployed_faq_without_rewriting_bookings(): void
    {
        $line = $this->booking();
        $line->update(['policy_version' => '2026-09-v2']);
        $original = $line->only(['total_price', 'pricing_breakdown', 'policy_version', 'policies_accepted_at']);
        $category = \App\Models\FaqCategory::factory()->create(['slug' => 'payments', 'is_active' => true]);
        $faq = \App\Models\Faq::factory()->create(['faq_category_id' => $category->id,
            'question' => 'How much do I pay to reserve?', 'answer' => 'The deposit is non-refundable.', 'is_active' => true]);
        $migration = require database_path('migrations/2026_10_12_000000_add_confirmed_booking_practices.php');
        $migration->publishFaqs();
        $this->assertStringContainsString('100% online payment is allowed', $faq->fresh()->answer);
        $this->assertStringNotContainsString('non-refundable', $faq->fresh()->answer);
        $this->assertEquals($original, $line->fresh()->only(array_keys($original)));
        $this->get(route('faq.index'))->assertOk()->assertSee('100% online payment is allowed')->assertDontSee('non-refundable');
    }

    public function test_new_pending_arrival_copy_requires_verified_deposit_before_confirmation(): void
    {
        $line = $this->booking();
        $line->update(['status' => ReservationStatus::Pending, 'deposit_required' => 500]);
        $copy = implode(' ', $line->fresh()->arrivalPassNotice());
        $this->assertStringContainsString('verify at least the 50%', $copy);
        $this->assertStringNotContainsString('proof upload open then', $copy);
        $line->update(['policy_version' => '2026-09-v2']);
        $this->assertStringContainsString('proof upload open then', implode(' ', $line->fresh()->arrivalPassNotice()));
    }

    public function test_package_departure_requires_cleaning_release_of_its_room_component(): void
    {
        $package = Facility::whereHas('facilityType', fn ($q) => $q->where('slug', 'resort_package'))->firstOrFail();
        $package->packageComponents()->create(['component_facility_id' => $this->room->id]);
        $line = Reservation::factory()->create(['facility_id' => $package->id, 'status' => ReservationStatus::Approved,
            'check_in' => '2026-11-10 14:00', 'check_out' => '2026-11-11 12:00', 'checked_in_at' => now(),
            'policy_version' => config('resort.policy.confirmed_version')]);
        app(ReservationService::class)->checkOut($line, $this->staff);
        $this->assertNotNull($this->room->fresh()->cleaning_required_at);
        app(ReservationService::class)->completeCleaning($this->room, $this->staff);
        $this->assertNull($this->room->fresh()->cleaning_required_at);
    }

    public function test_physical_check_in_blocks_refunds_and_rescheduling_even_if_scheduled_in_future(): void
    {
        $line = $this->booking();
        $line->update(['checked_in_at' => now()]);
        $this->actingAs($this->guest)->patch(route('customer.reservations.discussion', $line), ['action' => 'request_refund', 'reason' => 'Cancel'])->assertSessionHasErrors('booking');
        $this->patch(route('customer.reservations.discussion', $line), ['action' => 'propose_change', 'facility_id' => $this->room->id,
            'check_in' => '2026-11-12 14:00', 'check_out' => '2026-11-13 12:00', 'guest_count' => 1])->assertSessionHasErrors('booking');
    }

    public function test_replacement_requires_guest_acceptance_and_keeps_payments_and_policy(): void
    {
        $line = $this->booking();
        Payment::factory()->create(['reservation_id' => $line->id, 'amount' => 500, 'status' => PaymentStatus::Verified]);
        $policy = $line->policy_version;
        $old = $line->check_in->toDateTimeString();
        $this->travelTo(Carbon::parse('2026-11-10 05:59:00'));
        $selection = ['action' => 'propose_change', 'facility_id' => $this->room->id, 'check_in' => '2026-11-12 09:00', 'check_out' => '2026-11-12 17:00', 'guest_count' => 1];
        $this->actingAs($this->staff)->patch(route('admin.reservations.discussion', $line), $selection)->assertSessionHasNoErrors();
        $this->patch(route('admin.reservations.discussion', $line), ['action' => 'confirm_change'])->assertSessionHasErrors('booking');
        $this->assertSame($old, $line->fresh()->check_in->toDateTimeString());
        $quote = $line->fresh()->booking_workflow['change']['quote_cents'] / 100;
        $this->actingAs($this->guest)->patch(route('customer.reservations.discussion', $line), ['action' => 'accept_change', 'amount' => $quote])->assertSessionHasNoErrors();
        $this->actingAs($this->staff)->patch(route('admin.reservations.discussion', $line), ['action' => 'confirm_change'])->assertSessionHasNoErrors();
        $line->refresh();
        $this->assertSame('2026-11-12 09:00:00', $line->check_in->toDateTimeString());
        $this->assertSame('confirmed', $line->booking_workflow['change']['status']);
        $this->assertEquals(500, $line->getVerifiedTotalPaid());
        $this->assertSame($policy, $line->policy_version);
        $this->assertSame($old, $line->activities()->where('event', 'rescheduled')->first()->properties['old']['check_in']);
    }
}
