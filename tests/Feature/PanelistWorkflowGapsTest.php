<?php

namespace Tests\Feature;

use App\Enums\FacilityStatus;
use App\Enums\PaymentStatus;
use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Enums\Role;
use App\Models\Facility;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\ReservationGroup;
use App\Models\User;
use App\Notifications\Reservation\LateCheckoutUpdate;
use App\Notifications\Reservation\VerifiedPaymentReceipt;
use App\Services\AvailabilityService;
use App\Services\LateCheckoutService;
use App\Services\PaymentService;
use App\Services\PricingService;
use App\Services\ReservationService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PanelistWorkflowGapsTest extends TestCase
{
    use RefreshDatabase;

    private User $guest;
    private User $staff;
    private Facility $room;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-30 00:00:00'));
        $this->seedReservationCatalog();
        $this->seed(\Database\Seeders\ResortServicesSeeder::class);
        Notification::fake();
        $this->guest = User::factory()->create();
        $this->guest->assignRole(Role::Customer->value);
        $this->staff = User::role(Role::Administrator->value)->firstOrFail();
        $this->room = Facility::whereHas('facilityType', fn ($q) => $q->where('slug', 'room'))->firstOrFail();
    }

    private function booking(int $early = 0): Reservation
    {
        $reservation = app(ReservationService::class)->createReservation($this->room, $this->guest,
            Carbon::parse('2026-11-10 14:00'), Carbon::parse('2026-11-11 12:00'), ReservationSource::Online, 1,
            ['early_check_in_hours' => $early]);
        $reservation->update(['status' => ReservationStatus::Approved]);

        return $reservation;
    }

    private function request(Reservation $reservation, int $hours = 1): void
    {
        $this->actingAs($this->guest)->postJson(route('customer.reservations.late-checkout.quote', $reservation), ['hours' => $hours])
            ->assertOk()->assertJsonPath('fee_cents', $hours * 30000);
        $this->post(route('customer.reservations.late-checkout.store', $reservation), ['hours' => $hours, 'fee_cents' => $hours * 30000])
            ->assertSessionHasNoErrors()->assertRedirect();
        $reservation->refresh();
    }

    public function test_standard_and_each_allowed_early_hour_have_exact_prices_and_boundaries(): void
    {
        foreach ([0, 1, 2] as $early) {
            $price = app(PricingService::class)->calculate($this->room, Carbon::parse('2026-11-10 14:00'), Carbon::parse('2026-11-11 12:00'), $early);
            $this->assertSame($early * 20000, $price->earlyCheckInCharge);
            $this->assertSame(0, $price->lateCheckOutCharge);
            $this->assertSame($price->baseAmount + $early * 20000, $price->totalAmount);
            $this->postJson(route('facilities.availability.calculate', $this->room), [
                'check_in' => '2026-11-10 14:00', 'check_out' => '2026-11-11 12:00', 'guestCount' => 1, 'early_check_in_hours' => $early,
            ])->assertOk()->assertJsonPath('availability.status', 'available');
        }
        $reservation = $this->booking(2);
        $this->assertSame('12:00', $reservation->occupancyStart()->format('H:i'));
        $this->assertSame(40000, $reservation->priceItems()['Early check-in']);
    }

    public function test_selected_period_states_are_not_staff_managed_fully_booked_flags(): void
    {
        $service = app(AvailabilityService::class);
        $start = Carbon::parse('2026-11-10 14:00');
        $end = Carbon::parse('2026-11-11 12:00');
        $cottageType = \App\Models\FacilityType::firstOrCreate(['slug' => 'cottage'], ['name' => 'Cottage', 'is_active' => true]);
        Facility::factory()->create(['facility_type_id' => $cottageType->id, 'is_listed' => true, 'is_active' => true, 'status' => FacilityStatus::Available]);
        foreach (['room', 'function_hall', 'resort_package', 'cottage'] as $type) {
            $facility = Facility::whereHas('facilityType', fn ($q) => $q->where('slug', $type))->where('is_listed', true)->firstOrFail();
            $this->assertSame('available', $service->isAvailable($facility, $start, $end)->toArray()['status']);
            $facility->update(['status' => FacilityStatus::Maintenance]);
            $this->assertSame('temporarily_closed', $service->isAvailable($facility, $start, $end)->toArray()['status']);
            $facility->update(['status' => FacilityStatus::Unavailable]);
            $this->assertSame('unavailable', $service->isAvailable($facility, $start, $end)->toArray()['status']);
            $facility->update(['status' => FacilityStatus::Available]);
            $blocking = Reservation::factory()->create(['facility_id' => $facility->id, 'check_in' => $start, 'check_out' => $end, 'status' => ReservationStatus::Approved]);
            $this->assertSame('fully_booked', $service->isAvailable($facility, $start, $end)->toArray()['status']);
            $this->assertSame(FacilityStatus::Available, $facility->fresh()->status);
            $this->assertTrue($service->isAvailable($facility, $end, $end->copy()->addHour())->isAvailable);
            $blocking->delete();
        }
    }

    public function test_approved_late_hour_updates_booking_balance_itemization_and_audit_once(): void
    {
        $reservation = $this->booking(1);
        $oldTotal = (float) $reservation->total_price;
        Payment::factory()->create(['reservation_id' => $reservation->id, 'amount' => $oldTotal, 'status' => PaymentStatus::Verified, 'verified_at' => now()]);
        // Historical rate changes must not reprice the already-agreed base/early charges.
        $this->room->rates()->where('name', 'Base Rate')->update(['amount' => 9999]);
        $this->request($reservation);
        $this->assertEquals($oldTotal, $reservation->total_price);
        $this->assertSame('12:00', $reservation->occupancyEnd()->format('H:i'));
        $this->actingAs($this->staff)->patch(route('admin.reservations.late-checkout.decide', $reservation), ['decision' => 'approve', 'fee_cents' => 30000])->assertSessionHasNoErrors();
        $reservation->refresh();
        $this->assertEquals($oldTotal + 300, $reservation->total_price);
        $this->assertEquals(300, $reservation->getRemainingBalance());
        $this->assertSame('13:00', $reservation->occupancyEnd()->format('H:i'));
        $this->assertSame(30000, $reservation->priceItems()['Approved late check-out']);
        $this->assertSame(20000, $reservation->priceItems()['Early check-in']);
        $this->assertSame(1, $reservation->activities()->where('event', 'late_checkout_approved')->count());
        $this->patch(route('admin.reservations.late-checkout.decide', $reservation), ['decision' => 'approve', 'fee_cents' => 30000])->assertSessionHasErrors('late_checkout');
        $this->assertEquals($oldTotal + 300, $reservation->fresh()->total_price);
    }

    public function test_conflict_after_quote_cannot_be_approved_and_decline_does_not_charge(): void
    {
        $reservation = $this->booking();
        $oldTotal = $reservation->total_price;
        $this->request($reservation);
        Reservation::factory()->create(['facility_id' => $this->room->id, 'status' => ReservationStatus::Approved,
            'check_in' => '2026-11-11 14:00', 'check_out' => '2026-11-12 12:00', 'early_check_in_hours' => 2]);
        $this->actingAs($this->staff)->patch(route('admin.reservations.late-checkout.decide', $reservation), ['decision' => 'approve', 'fee_cents' => 30000])->assertSessionHasErrors('late_checkout');
        $this->assertSame('pending', $reservation->fresh()->late_checkout_request['status']);
        $this->patch(route('admin.reservations.late-checkout.decide', $reservation), ['decision' => 'decline', 'fee_cents' => 30000, 'reason' => 'Next guest arrives at noon.'])->assertSessionHasNoErrors();
        $reservation->refresh();
        $this->assertSame('declined', $reservation->late_checkout_request['status']);
        $this->assertSame($oldTotal, $reservation->total_price);
        $this->assertSame(0, $reservation->late_check_out_hours);
        $this->assertSame(1, $reservation->activities()->where('event', 'late_checkout_declined')->count());
    }

    public function test_touching_next_occupancy_boundary_is_allowed_but_extending_past_it_is_not(): void
    {
        $reservation = $this->booking();
        Reservation::factory()->create(['facility_id' => $this->room->id, 'status' => ReservationStatus::Approved,
            'check_in' => '2026-11-11 14:00', 'check_out' => '2026-11-12 12:00', 'early_check_in_hours' => 1]);
        $service = app(LateCheckoutService::class);
        $this->assertTrue($service->quote($reservation, 1)['availability']['is_available']);
        $this->assertSame('fully_booked', $service->quote($reservation, 2)['availability']['status']);
    }

    public function test_group_balance_increases_but_the_existing_deposit_requirement_is_unchanged(): void
    {
        $reservation = $this->booking();
        $group = ReservationGroup::create(['user_id' => $this->guest->id, 'group_code' => 'GRP-LATE-TEST', 'source' => 'online', 'status' => 'approved', 'guest_name' => $this->guest->name, 'guest_email' => $this->guest->email, 'guest_phone' => '09171234567',
            'total_amount' => $reservation->total_price, 'deposit_required' => 500]);
        $reservation->update(['reservation_group_id' => $group->id]);
        $payment = Payment::factory()->create(['reservation_id' => null, 'reservation_group_id' => $group->id, 'amount' => 500, 'status' => PaymentStatus::Verified, 'verified_at' => now()]);
        $balance = $group->outstandingBalance();
        $this->request($reservation);
        app(LateCheckoutService::class)->decide($reservation, $this->staff, true, 30000);
        $this->assertEquals($balance + 300, $group->fresh()->outstandingBalance());
        $this->assertEquals(500, $group->fresh()->deposit_required);
        $this->actingAs($this->guest)->get(route('customer.payments.index', $reservation))->assertOk()->assertSee('Itemized payment receipt');
        $this->get(route('customer.payments.receipt', $payment))->assertOk()->assertSee('Approved late check-out')->assertSeeText('₱300.00')->assertSee($group->group_code);
    }

    public function test_receipts_require_verification_and_ownership_and_keep_pending_proof_separate(): void
    {
        $reservation = $this->booking(2);
        $payment = Payment::factory()->create(['reservation_id' => $reservation->id, 'amount' => 500]);
        $this->actingAs($this->guest)->get(route('customer.payments.receipt', $payment))->assertNotFound();
        $this->get(route('customer.payments.index', $reservation))->assertOk()->assertSee('Under review')->assertDontSee('Itemized payment receipt');
        app(PaymentService::class)->verifyPayment($payment, $this->staff);
        $this->get(route('customer.payments.receipt', $payment))->assertOk()->assertSee('Verified payment receipt')->assertSee('Early check-in')->assertSeeText('₱400.00')->assertSeeText('₱500.00');
        $this->actingAs(User::factory()->create())->get(route('customer.payments.receipt', $payment))->assertForbidden();
    }

    public function test_queued_notification_content_does_not_claim_fee_payment_or_email_delivery(): void
    {
        $reservation = $this->booking();
        $payment = Payment::factory()->create(['reservation_id' => $reservation->id, 'amount' => 500]);
        app(PaymentService::class)->verifyPayment($payment, $this->staff);
        $notification = new VerifiedPaymentReceipt($payment->fresh());
        $this->assertInstanceOf(ShouldQueue::class, $notification);
        $this->assertTrue($notification->shouldSend($this->guest, 'mail'));
        $text = implode(' ', $notification->toMail($this->guest)->introLines);
        $this->assertStringContainsString('checked and recorded your payment', $text);
        $this->assertStringContainsString('₱500.00', $text);
        $this->assertStringContainsString($reservation->reservation_code, $text);
        $this->request($reservation);
        app(LateCheckoutService::class)->decide($reservation, $this->staff, true, 30000);
        $decision = new LateCheckoutUpdate($reservation->id, $reservation->late_checkout_request);
        $this->assertInstanceOf(ShouldQueue::class, $decision);
        $text = implode(' ', $decision->toMail($this->guest)->introLines);
        $this->assertStringContainsString('₱300.00', $text);
        $this->assertStringContainsString('to the amount you still owe', $text);
        $payment->update(['status' => PaymentStatus::Rejected]);
        $this->assertFalse($notification->shouldSend($this->guest, 'mail'));
    }

    public function test_staff_and_guest_flows_render_the_exact_fee_and_decisions(): void
    {
        $reservation = $this->booking();
        $this->actingAs($this->guest)->get(route('customer.reservations.show', $reservation))->assertOk()->assertSee('Review exact fee and availability');
        $this->request($reservation);
        $this->get(route('customer.reservations.show', $reservation))->assertOk()->assertSee('Under staff review');
        $this->actingAs($this->staff)->get(route('admin.reservations.show', $reservation))->assertOk()->assertSeeText('₱300.00')->assertSee('Approve & add fee', false)->assertSee('Decline without charge');
    }

    public function test_request_security_stale_schedule_and_limits(): void
    {
        $reservation = $this->booking();
        $outsider = User::factory()->create();
        $this->actingAs($outsider)->postJson(route('customer.reservations.late-checkout.quote', $reservation), ['hours' => 1])->assertForbidden();
        $this->actingAs($this->guest)->post(route('customer.reservations.late-checkout.store', $reservation), ['hours' => 1, 'fee_cents' => 1])->assertSessionHasErrors('hours');
        $this->postJson(route('customer.reservations.late-checkout.quote', $reservation), ['hours' => 0])->assertUnprocessable();
        $this->request($reservation);
        $this->post(route('customer.reservations.late-checkout.store', $reservation), ['hours' => 1, 'fee_cents' => 30000])->assertSessionHasErrors('hours');
        $this->patch(route('admin.reservations.late-checkout.decide', $reservation), ['decision' => 'approve', 'fee_cents' => 30000])->assertRedirect(route('admin.login'));
        $reservation->update(['check_out' => '2026-11-12 12:00']);
        $this->actingAs($this->staff)->patch(route('admin.reservations.late-checkout.decide', $reservation), ['decision' => 'approve', 'fee_cents' => 30000])->assertSessionHasErrors('late_checkout');
        $this->assertSame(0, $reservation->fresh()->late_check_out_hours);
    }

    public function test_exclusions_are_editable_escaped_and_diamond_capacity_is_not_replaced(): void
    {
        $hall = Facility::where('slug', 'diamond-hall')->firstOrFail();
        $this->assertSame(250, (int) $hall->capacity);
        $this->actingAs($this->staff)->put(route('admin.facilities.update', $hall), ['name' => $hall->name, 'description' => $hall->description,
            'base_capacity' => 250, 'max_capacity' => 250, 'exclusions' => "Approved catalog exclusion\n<script>bad()</script>"])->assertSessionHasNoErrors();
        $this->get(route('facilities.show', $hall))->assertOk()->assertSee('Exclusions / Not included')->assertSee('Approved catalog exclusion')->assertDontSee('<script>bad()</script>', false);
        $this->assertSame(250, (int) $hall->fresh()->capacity);
        $this->get(route('admin.facilities.edit', $hall))->assertOk()->assertSee('Not included (one item per line)');
    }

    public function test_rate_reconciliation_is_dry_run_by_default_and_apply_does_not_reprice_bookings(): void
    {
        $reservation = $this->booking();
        $oldTotal = $reservation->total_price;
        $rate = $this->room->rates()->where('name', PricingService::LATE_CHECK_OUT_RATE_NAME)->firstOrFail();
        $rate->update(['amount' => 900]);
        $this->artisan('resort:reconcile-room-hour-rates')->assertSuccessful();
        $this->assertEquals(900, $rate->fresh()->amount);
        $this->actingAs($this->guest)->postJson(route('customer.reservations.late-checkout.quote', $reservation), ['hours' => 1])->assertUnprocessable();
        $this->artisan('resort:reconcile-room-hour-rates', ['--apply' => true])->assertSuccessful();
        $this->assertEquals(300, $rate->fresh()->amount);
        $this->assertSame($oldTotal, $reservation->fresh()->total_price);
    }

    public function test_staff_can_arrange_earlier_hours_but_online_requests_remain_limited_to_two(): void
    {
        $arranged = app(ReservationService::class)->createReservation($this->room, $this->staff,
            Carbon::parse('2026-11-10 14:00'), Carbon::parse('2026-11-11 12:00'), ReservationSource::WalkIn, 1,
            ['early_check_in_hours' => 3]);
        $this->assertSame(3, $arranged->early_check_in_hours);
        $this->assertSame(60000, $arranged->pricing_breakdown['early_check_in_charge']);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(ReservationService::class)->createReservation($this->room, $this->guest,
            Carbon::parse('2026-11-12 14:00'), Carbon::parse('2026-11-13 12:00'), ReservationSource::Online, 1,
            ['early_check_in_hours' => 3]);
    }

    public function test_group_proof_access_requires_ownership_and_a_real_private_proof(): void
    {
        \Illuminate\Support\Facades\Storage::fake('uploads');
        \Illuminate\Support\Facades\Storage::disk('uploads')->put('proofs/group.png', 'synthetic proof');
        $group = ReservationGroup::create(['user_id' => $this->guest->id, 'group_code' => 'GRP-PROOF-TEST', 'source' => 'online', 'status' => 'approved',
            'guest_name' => $this->guest->name, 'guest_email' => $this->guest->email, 'guest_phone' => '09171234567', 'total_amount' => 1000]);
        $payment = Payment::factory()->create(['reservation_id' => null, 'reservation_group_id' => $group->id, 'amount' => 500, 'proof_path' => 'proofs/group.png']);
        $this->actingAs($this->guest)->get(route('customer.payments.view', $payment))->assertOk();
        $this->get(route('customer.payments.download', $payment))->assertOk();
        $this->get(route('customer.payments.receipt', $payment))->assertNotFound();
        $this->actingAs(User::factory()->create())->get(route('customer.payments.view', $payment))->assertForbidden();
    }

    public function test_closed_completed_and_non_room_bookings_cannot_request_late_checkout(): void
    {
        $reservation = $this->booking();
        $this->actingAs($this->guest);
        foreach ([['status' => ReservationStatus::Pending], ['status' => ReservationStatus::Completed], ['status' => ReservationStatus::Approved, 'checked_out_at' => now()]] as $change) {
            $reservation->update($change);
            $this->postJson(route('customer.reservations.late-checkout.quote', $reservation), ['hours' => 1])->assertUnprocessable();
        }
        $reservation->update(['status' => ReservationStatus::Approved, 'checked_out_at' => null, 'facility_id' => Facility::where('slug', 'diamond-hall')->value('id')]);
        $this->postJson(route('customer.reservations.late-checkout.quote', $reservation), ['hours' => 1])->assertUnprocessable();
    }

    public function test_search_labels_fully_booked_and_closed_inventory_and_grouped_labels_match(): void
    {
        $roomType = $this->room->facility_type_id;
        Facility::where('facility_type_id', $roomType)->where('id', '!=', $this->room->id)->update(['is_listed' => false]);
        $this->booking();
        $search = app(\App\Services\AvailabilitySearchService::class)->search(\App\Enums\BookingServiceType::Room, Carbon::parse('2026-11-10'), Carbon::parse('2026-11-11'), 1);
        $this->assertSame('fully_booked', $search['status']);
        $this->assertStringContainsString('Fully booked', $search['message']);
        $line = ['facility_id' => $this->room->id, 'check_in' => '2026-11-10 14:00', 'check_out' => '2026-11-11 12:00', 'guest_count' => 1];
        $grouped = app(\App\Services\GroupedAvailabilityService::class)->evaluateGroup([$line]);
        $this->assertSame('Fully booked', $grouped['lines'][0]['status']);
        $this->room->update(['status' => FacilityStatus::Maintenance]);
        $search = app(\App\Services\AvailabilitySearchService::class)->search(\App\Enums\BookingServiceType::Room, Carbon::parse('2026-11-10'), Carbon::parse('2026-11-11'), 1);
        $this->assertSame('temporarily_closed', $search['status']);
        $this->assertSame('Temporarily closed', app(\App\Services\GroupedAvailabilityService::class)->evaluateGroup([$line])['lines'][0]['status']);
    }

    public function test_repeated_legacy_requests_keep_previously_approved_fees_itemized(): void
    {
        $reservation = $this->booking();
        $reservation->update(['pricing_breakdown' => null]);
        for ($i = 1; $i <= 2; $i++) {
            $this->request($reservation);
            app(LateCheckoutService::class)->decide($reservation, $this->staff, true, 30000);
        }
        $this->assertSame(60000, $reservation->priceItems()['Approved late check-out']);
        $this->request($reservation);
        app(LateCheckoutService::class)->decide($reservation, $this->staff, false, 30000, 'Unavailable');
        $this->assertSame(60000, $reservation->priceItems()['Approved late check-out']);
    }
}
