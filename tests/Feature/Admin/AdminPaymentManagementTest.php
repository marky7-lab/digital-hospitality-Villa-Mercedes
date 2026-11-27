<?php

namespace Tests\Feature\Admin;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Enums\Role;
use App\Models\Facility;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\ReservationGroup;
use App\Models\User;
use App\Support\LayoutBadges;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminPaymentManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $customer;

    private Facility $facility;

    private Reservation $reservation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Role::Administrator->value);

        $this->customer = User::factory()->create();
        $this->customer->assignRole(Role::Customer->value);

        $this->facility = Facility::first();

        $this->reservation = Reservation::factory()->create([
            'user_id' => $this->customer->id,
            'facility_id' => $this->facility->id,
            'status' => ReservationStatus::Approved->value,
            'total_price' => 5000.00,
        ]);
    }

    private function createPayment(array $overrides = []): Payment
    {
        return Payment::factory()->create(array_merge([
            'reservation_id' => $this->reservation->id,
            'amount' => 2500.00,
            'method' => PaymentMethod::GCash->value,
            'status' => PaymentStatus::Pending->value,
            'payment_date' => now()->toDateString(),
        ], $overrides));
    }

    public function test_group_and_room_payments_share_one_searchable_booking_row(): void
    {
        $group = ReservationGroup::factory()->create(['guest_name' => 'Grouped Payment Guest']);
        $this->reservation->update(['reservation_group_id' => $group->id]);
        $linePayment = $this->createPayment(['status' => PaymentStatus::Verified, 'amount' => 1700]);
        $groupPayment = Payment::factory()->create([
            'reservation_id' => null,
            'reservation_group_id' => $group->id,
            'status' => PaymentStatus::Pending,
            'amount' => 6200,
        ]);

        $response = $this->actingAs($this->admin)->get(route('admin.payments.index', ['search' => $group->group_code]));
        $response->assertOk()->assertSee($group->group_code);
        $bookings = $response->viewData('payments');
        $this->assertSame(1, $bookings->total());
        $this->assertEqualsCanonicalizing([$linePayment->id, $groupPayment->id], $bookings->first()->pluck('id')->all());
    }

    // --- Access Control ---

    public function test_unauthenticated_user_cannot_access_payment_management(): void
    {
        $response = $this->get(route('admin.payments.index'));
        $response->assertRedirect(route('admin.login'));
    }

    public function test_customer_cannot_access_payment_management(): void
    {
        $response = $this->actingAs($this->customer)->get(route('admin.payments.index'));
        $response->assertRedirect(route('admin.login'));
    }

    public function test_admin_can_access_payment_listing(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.payments.index'));
        $response->assertStatus(200);
        $response->assertSee('Payment review');
    }

    public function test_group_payment_renders_in_listing_and_details_without_a_reservation(): void
    {
        $group = ReservationGroup::factory()->create([
            'user_id' => $this->customer->id,
            'guest_name' => 'Group Payment Guest',
        ]);
        $payment = Payment::factory()->create([
            'reservation_id' => null,
            'reservation_group_id' => $group->id,
            'created_at' => now()->subYears(40),
            'updated_at' => now()->subYears(40),
        ]);

        $listing = $this->actingAs($this->admin)->get(route('admin.payments.index', ['status' => 'pending']));

        $listing->assertOk()
            ->assertSee('Group Payment Guest')
            ->assertSee($group->group_code)
            ->assertSee(route('admin.reservation-groups.show', $group), false);

        $this->actingAs($this->admin)
            ->get(route('admin.payments.show', $payment))
            ->assertOk()
            ->assertSee('Related booking group')
            ->assertSee('Group Payment Guest')
            ->assertSee(route('admin.reservation-groups.show', $group), false);
    }

    // --- Listing ---

    public function test_payments_display_in_listing(): void
    {
        $payment = $this->createPayment();

        // Scoped to this reservation, the way the reservation record's "Open in
        // payment review" link scopes it. The unscoped listing escalates the
        // oldest pending proof first and holds twenty rows, so a payment created
        // just now sits behind every pending row the demo seeders left behind —
        // which is a fact about the seed volume, not about whether a payment is
        // rendered. The claim here is about this payment.
        $response = $this->actingAs($this->admin)->get(route('admin.payments.index', [
            'search' => $this->reservation->reservation_code,
        ]));

        $response->assertStatus(200);
        $response->assertSee($this->reservation->reservation_code);
        $response->assertSee('2,500.00');
    }

    public function test_pending_payments_are_visible(): void
    {
        $this->createPayment(['status' => PaymentStatus::Pending->value]);

        $response = $this->actingAs($this->admin)->get(route('admin.payments.index', ['status' => 'pending']));
        $response->assertStatus(200);

        $payments = $response->viewData('payments');
        $this->assertTrue($payments->getCollection()->flatten(1)->every(fn ($p) => $p->status === PaymentStatus::Pending));
    }

    public function test_listing_filters_by_method(): void
    {
        $this->createPayment(['method' => PaymentMethod::GCash->value]);
        $this->createPayment(['method' => PaymentMethod::BankTransfer->value]);

        $response = $this->actingAs($this->admin)->get(route('admin.payments.index', ['method' => 'gcash']));
        $response->assertStatus(200);

        $payments = $response->viewData('payments');
        $this->assertTrue($payments->getCollection()->flatten(1)->every(fn ($p) => $p->method === PaymentMethod::GCash));
    }

    public function test_listing_search_by_reservation_code(): void
    {
        $this->createPayment();

        $response = $this->actingAs($this->admin)->get(route('admin.payments.index', ['search' => $this->reservation->reservation_code]));
        $response->assertStatus(200);

        $payments = $response->viewData('payments');
        $this->assertGreaterThanOrEqual(1, $payments->count());
    }

    public function test_listing_pagination_works(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.payments.index'));
        $response->assertStatus(200);

        // View data should be a paginator
        $payments = $response->viewData('payments');
        $this->assertInstanceOf(LengthAwarePaginator::class, $payments);
    }

    // --- Detail Page ---

    public function test_payment_details_render(): void
    {
        $payment = $this->createPayment();

        $response = $this->actingAs($this->admin)->get(route('admin.payments.show', $payment));
        $response->assertStatus(200);
        $response->assertSee('Payment details');
        $response->assertSee('2,500.00');
        $response->assertSee($payment->method->label());
    }

    public function test_reservation_context_renders(): void
    {
        $payment = $this->createPayment();

        $response = $this->actingAs($this->admin)->get(route('admin.payments.show', $payment));
        $response->assertStatus(200);
        $response->assertSee('Related reservation');
        $response->assertSee($this->reservation->reservation_code);
        $response->assertSee($this->facility->name);
    }

    public function test_balance_is_correct(): void
    {
        // Create one verified and one pending payment
        Payment::factory()->create([
            'reservation_id' => $this->reservation->id,
            'amount' => 2000.00,
            'status' => PaymentStatus::Verified->value,
            'payment_date' => now()->toDateString(),
        ]);

        $pendingPayment = $this->createPayment(['amount' => 1500.00]);

        $response = $this->actingAs($this->admin)->get(route('admin.payments.show', $pendingPayment));
        $response->assertStatus(200);

        // The pending proof is shown separately so it is not represented as
        // verified money, while staff can still see the projected balance.
        $response->assertSeeInOrder([
            'Reservation total',
            '5,000.00',
            'Verified paid',
            '2,000.00',
            'Under review',
            '1,500.00',
            'Balance if verified',
            '1,500.00',
        ]);
    }

    public function test_sensitive_user_fields_not_exposed(): void
    {
        $payment = $this->createPayment();

        $response = $this->actingAs($this->admin)->get(route('admin.payments.show', $payment));
        $response->assertStatus(200);

        // Password hash must never appear in the rendered HTML
        $response->assertDontSee($this->customer->password);
    }

    // --- Proof of Payment ---

    public function test_authorized_admin_can_download_proof(): void
    {
        Storage::fake('uploads');
        Storage::disk('uploads')->put('proofs/test-proof.jpg', 'fake-content');

        $payment = $this->createPayment(['proof_path' => 'proofs/test-proof.jpg']);

        $response = $this->actingAs($this->admin)->get(route('admin.payments.download', $payment));
        $response->assertStatus(200);
    }

    public function test_authorized_admin_can_view_proof_inline(): void
    {
        Storage::fake('uploads');
        Storage::disk('uploads')->put('proofs/test-proof.jpg', 'fake-content');

        $payment = $this->createPayment(['proof_path' => 'proofs/test-proof.jpg']);

        $response = $this->actingAs($this->admin)->get(route('admin.payments.view', $payment));
        $response->assertStatus(200);
        $response->assertHeader('Content-Disposition', 'inline; filename="test-proof.jpg"');
    }

    public function test_payment_detail_embeds_image_proof(): void
    {
        Storage::fake('uploads');
        Storage::disk('uploads')->put('proofs/test-proof.jpg', 'fake-image');

        $payment = $this->createPayment(['proof_path' => 'proofs/test-proof.jpg']);

        $this->actingAs($this->admin)
            ->get(route('admin.payments.show', $payment))
            ->assertOk()
            ->assertSee('aria-label="Proof of payment enlarged"', false)
            ->assertSee(route('admin.payments.view', $payment), false)
            ->assertDontSee('proofs/test-proof.jpg');
    }

    public function test_customer_cannot_access_admin_proof_route(): void
    {
        $payment = $this->createPayment(['proof_path' => 'proofs/test.jpg']);

        $response = $this->actingAs($this->customer)->get(route('admin.payments.download', $payment));
        $response->assertRedirect(route('admin.login'));
    }

    public function test_missing_proof_handled_safely(): void
    {
        $payment = $this->createPayment(['proof_path' => null]);

        $response = $this->actingAs($this->admin)->get(route('admin.payments.download', $payment));
        $response->assertStatus(404);
    }

    public function test_filesystem_path_not_exposed(): void
    {
        $payment = $this->createPayment(['proof_path' => 'proofs/secret-file.jpg']);

        $response = $this->actingAs($this->admin)->get(route('admin.payments.show', $payment));
        $response->assertStatus(200);

        // The raw filesystem path must never be rendered in HTML
        $response->assertDontSee('proofs/secret-file.jpg');
    }

    // --- The queue count the sidebar shows ---

    public function test_the_sidebar_counts_the_proofs_waiting_for_review(): void
    {
        // The seed already leaves proofs in the queue, so the check is that the
        // badge carries the queue's own size rather than a number this test
        // picked — the same way the count is read on screen.
        $this->createPayment();

        $waiting = Payment::query()->where('status', PaymentStatus::Pending->value)->count();

        $this->assertSame($waiting, LayoutBadges::pendingPaymentCount());

        $sidebar = $this->actingAs($this->admin)->get(route('admin.dashboard'))->getContent();

        $this->assertStringContainsString(
            'Payment review, '.$waiting.' waiting for review',
            $sidebar,
            'The Payment review entry does not carry the size of its queue.'
        );
    }

    public function test_settling_a_proof_drops_the_payment_review_count(): void
    {
        $payment = $this->createPayment();

        $waiting = LayoutBadges::pendingPaymentCount();

        $this->actingAs($this->admin)->patch(route('admin.payments.verify', $payment));

        $this->assertSame(
            $waiting - 1,
            LayoutBadges::pendingPaymentCount(),
            'A verified proof stays in the count for the rest of the cache window.'
        );
    }

    public function test_the_review_badge_warns_once_a_proof_outlasts_its_window(): void
    {
        $this->clearPaymentHistory();

        // A proof inside its window is a queue: a muted count, nothing else.
        $this->createPayment(['source' => 'customer_deposit', 'created_at' => now()->subHour()]);

        $this->assertSame(0, LayoutBadges::overduePaymentCount());
        $this->assertStringNotContainsString('bg-warning', $this->sidebarEntry(route('admin.payments.index')));

        // One that has outlasted the window the guest was promised is not a
        // queue any more, and the badge has to say so.
        $this->createPayment([
            'source' => 'customer_deposit',
            'created_at' => now()->subHours((int) config('payments.review_hours') + 1),
        ]);

        $this->assertSame(1, LayoutBadges::overduePaymentCount());

        $entry = $this->sidebarEntry(route('admin.payments.index'));

        $this->assertStringContainsString('bg-warning', $entry, 'An overdue proof leaves the badge looking like ordinary work.');
        $this->assertStringContainsString('overdue for a decision', $entry, 'Colour alone does not reach every staff member.');
    }

    public function test_the_proof_panel_calls_a_late_proof_overdue(): void
    {
        $fresh = $this->createPayment(['source' => 'customer_deposit', 'created_at' => now()->subHour()]);
        $late = $this->createPayment([
            'source' => 'customer_deposit',
            'created_at' => now()->subHours((int) config('payments.review_hours') + 1),
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.payments.show', $fresh))
            ->assertOk()
            ->assertDontSee('reconcile this transfer now');

        $this->actingAs($this->admin)
            ->get(route('admin.payments.show', $late))
            ->assertOk()
            ->assertSee('reconcile this transfer now')
            ->assertSee('waiting longer than '.config('payments.review_hours').' hours');
    }

    /**
     * The seeded payment history is somebody else's queue; these counts are read
     * from proofs this test creates.
     */
    private function clearPaymentHistory(): void
    {
        Payment::query()->delete();
        LayoutBadges::forgetPendingPayments();
    }

    /** One sidebar link as the browser receives it. */
    private function sidebarEntry(string $url): string
    {
        $html = $this->actingAs($this->admin)->get(route('admin.dashboard'))->getContent();

        preg_match('#<a[^>]*href="'.preg_quote($url, '#').'"[^>]*>.*?</a>#s', $html, $match);

        return $match[0] ?? '';
    }

    // --- Verification ---

    public function test_pending_payment_can_be_verified(): void
    {
        $payment = $this->createPayment();

        $response = $this->actingAs($this->admin)->patch(route('admin.payments.verify', $payment));

        $response->assertRedirect(route('admin.reservations.show', $this->reservation));
        $response->assertSessionHas('success', 'Payment verified. The guest can see the updated balance.');

        $payment->refresh();
        $this->assertEquals(PaymentStatus::Verified, $payment->status);
        $this->assertEquals($this->admin->id, $payment->verified_by);
        $this->assertNotNull($payment->verified_at);
    }

    public function test_verified_payment_cannot_be_verified_again(): void
    {
        $payment = $this->createPayment(['status' => PaymentStatus::Verified->value, 'verified_by' => $this->admin->id, 'verified_at' => now()]);

        $response = $this->actingAs($this->admin)->patch(route('admin.payments.verify', $payment));

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    public function test_verifier_is_set_by_backend(): void
    {
        $payment = $this->createPayment();

        $this->actingAs($this->admin)->patch(route('admin.payments.verify', $payment));

        $payment->refresh();
        $this->assertEquals($this->admin->id, $payment->verified_by);
    }

    // --- Rejection ---

    public function test_pending_payment_can_be_rejected(): void
    {
        $payment = $this->createPayment();

        $response = $this->actingAs($this->admin)->patch(route('admin.payments.reject', $payment), [
            'reason' => 'Invalid proof of payment.',
        ]);

        $response->assertRedirect(route('admin.reservations.show', $this->reservation));
        $response->assertSessionHas('success', 'Payment proof declined. The guest has been notified and the amount no longer appears under review.');

        $payment->refresh();
        $this->assertEquals(PaymentStatus::Rejected, $payment->status);
        $this->assertEquals('Invalid proof of payment.', $payment->rejection_reason);
    }

    public function test_rejection_reason_is_required(): void
    {
        $payment = $this->createPayment();

        $response = $this->actingAs($this->admin)->patch(route('admin.payments.reject', $payment), [
            'reason' => '',
        ]);

        $response->assertSessionHasErrors(['reason']);
    }

    public function test_rejected_payment_remains_stored(): void
    {
        $payment = $this->createPayment();

        $this->actingAs($this->admin)->patch(route('admin.payments.reject', $payment), [
            'reason' => 'Bad proof.',
        ]);

        $this->assertDatabaseHas('payments', ['id' => $payment->id]);
    }

    public function test_rejected_payment_cannot_be_verified(): void
    {
        $payment = $this->createPayment([
            'status' => PaymentStatus::Rejected->value,
            'rejection_reason' => 'Bad proof.',
            'verified_by' => $this->admin->id,
            'verified_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->patch(route('admin.payments.verify', $payment));

        $response->assertRedirect();
        $response->assertSessionHas('error');

        $payment->refresh();
        $this->assertEquals(PaymentStatus::Rejected, $payment->status);
    }

    // --- Security ---

    public function test_status_cannot_be_spoofed(): void
    {
        $payment = $this->createPayment();

        // Attempt to send status in the verify request body (should be ignored)
        $response = $this->actingAs($this->admin)->patch(route('admin.payments.verify', $payment), [
            'status' => 'rejected',
        ]);

        $payment->refresh();
        $this->assertEquals(PaymentStatus::Verified, $payment->status);
    }

    public function test_verified_by_cannot_be_spoofed(): void
    {
        $payment = $this->createPayment();

        $otherAdmin = User::factory()->create();
        $otherAdmin->assignRole(Role::Administrator->value);

        // Attempt to spoof verified_by
        $this->actingAs($this->admin)->patch(route('admin.payments.verify', $payment), [
            'verified_by' => $otherAdmin->id,
        ]);

        $payment->refresh();
        $this->assertEquals($this->admin->id, $payment->verified_by);
    }

    public function test_customer_cannot_perform_admin_payment_actions(): void
    {
        $payment = $this->createPayment();

        $verifyResponse = $this->actingAs($this->customer)->patch(route('admin.payments.verify', $payment));
        $verifyResponse->assertRedirect(route('admin.login'));

        $rejectResponse = $this->actingAs($this->customer)->patch(route('admin.payments.reject', $payment), [
            'reason' => 'Hack attempt',
        ]);
        $rejectResponse->assertRedirect(route('admin.login'));
    }
}
