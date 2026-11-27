<?php

namespace Tests\Feature;

use App\Enums\FacilityType as FacilityTypeEnum;
use App\Enums\GuestGender;
use App\Enums\GuestIdType;
use App\Enums\RateType;
use App\Enums\ReservationGroupStatus;
use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Enums\Role;
use App\Models\Facility;
use App\Models\FacilityType;
use App\Models\Rate;
use App\Models\Reservation;
use App\Models\ReservationGroup;
use App\Models\SwimmingSession;
use App\Models\User;
use App\Services\GroupedAvailabilityService;
use App\Services\GroupedBookingService;
use Carbon\Carbon;
use Database\Seeders\FacilityTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GroupedBookingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;
    private User $admin;
    private Facility $room;
    private Facility $cottage;
    private Facility $hall;
    private SwimmingSession $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, FacilityTypeSeeder::class]);

        $this->customer = User::factory()->create([
            'name' => 'Alexander Mauricio',
            'email' => 'alexander@example.com',
            'phone' => '09171234567',
            'address' => 'City of Ilagan, Isabela',
            'nationality' => 'Filipino',
            'gender' => GuestGender::Male,
            'valid_id_type' => GuestIdType::PhilSys,
        ]);
        $this->customer->assignRole(Role::Customer->value);

        $this->admin = User::factory()->create([
            'name' => 'Front Desk Staff',
            'email' => 'admin@example.com',
        ]);
        $this->admin->assignRole(Role::Administrator->value);

        // Create Room
        $roomType = FacilityType::where('slug', FacilityTypeEnum::Room->value)->first();
        $this->room = Facility::factory()->create([
            'facility_type_id' => $roomType->id,
            'name' => 'Deluxe Room 101',
            'is_active' => true,
            'is_listed' => true,
            'status' => 'available',
            'capacity' => 2,
            'max_capacity' => 4,
        ]);
        Rate::factory()->create([
            'facility_id' => $this->room->id,
            'type' => RateType::Base->value,
            'amount' => 2500.00, // 2,500.00 pesos
            'unit' => 'per night',
            'is_active' => true,
        ]);

        // Create Cottage
        $cottageType = FacilityType::where('slug', FacilityTypeEnum::Cottage->value)->first();
        $this->cottage = Facility::factory()->create([
            'facility_type_id' => $cottageType->id,
            'name' => 'Umbrella Cottage 01',
            'is_active' => true,
            'is_listed' => true,
            'status' => 'available',
            'capacity' => 5,
            'max_capacity' => 10,
        ]);
        Rate::factory()->create([
            'facility_id' => $this->cottage->id,
            'type' => RateType::Base->value,
            'amount' => 500.00, // 500.00 pesos
            'unit' => 'per event',
            'is_active' => true,
        ]);

        // Create Hall
        $hallType = FacilityType::where('slug', FacilityTypeEnum::FunctionHall->value)->first();
        $this->hall = Facility::factory()->create([
            'facility_type_id' => $hallType->id,
            'name' => 'Diamond Function Hall',
            'is_active' => true,
            'is_listed' => true,
            'status' => 'available',
            'capacity' => 50,
            'max_capacity' => 100,
        ]);
        Rate::factory()->create([
            'facility_id' => $this->hall->id,
            'type' => RateType::Base->value,
            'amount' => 15000.00, // 15,000.00 pesos
            'unit' => 'per event',
            'is_active' => true,
        ]);

        // Swimming Session
        $this->session = SwimmingSession::create([
            'name' => 'Day Swimming Session',
            'slug' => 'day-session',
            'starts_at' => '08:00:00',
            'ends_at' => '17:00:00',
            'adult_entrance_fee' => 150.00,
            'child_entrance_fee' => 100.00,
            'cottage_fee' => 500.00,
            'is_active' => true,
            'sort_order' => 1,
        ]);
    }

    public function test_grouped_availability_service_returns_line_level_estimates(): void
    {
        $service = app(GroupedAvailabilityService::class);
        $nextMonth = now()->addMonth()->format('Y-m-d');

        $lines = [
            [
                'facility_id' => $this->room->id,
                'check_in' => "{$nextMonth} 14:00",
                'check_out' => Carbon::parse("{$nextMonth} 14:00")->addDay()->setTime(12, 0)->format('Y-m-d H:i'),
                'guest_count' => 2,
            ],
            [
                'facility_id' => $this->cottage->id,
                'date' => $nextMonth,
                'swimming_session_id' => $this->session->id,
                'guest_count' => 5,
            ],
        ];

        $evaluation = $service->evaluateGroup($lines);

        $this->assertTrue($evaluation['all_available']);
        $this->assertCount(2, $evaluation['lines']);
        $this->assertEquals(2500.00, $evaluation['lines'][0]['quoted_amount']);
        $this->assertEquals(500.00, $evaluation['lines'][1]['quoted_amount']);
        $this->assertEquals(3000.00, $evaluation['total_estimate']);
        $this->assertEquals(1500.00, $evaluation['deposit_estimate']);
        $this->assertNotEmpty($evaluation['version_token']);
    }

    public function test_group_rejects_duplicate_facility_and_disconnected_visit(): void
    {
        $date = now()->addMonth()->toDateString();
        $room = ['facility_id' => $this->room->id, 'check_in' => "$date 14:00", 'check_out' => Carbon::parse($date)->addDay()->format('Y-m-d').' 12:00', 'guest_count' => 2];
        $service = app(GroupedAvailabilityService::class);

        $duplicate = $service->evaluateGroup([$room, $room]);
        $this->assertFalse($duplicate['all_available']);
        $this->assertStringContainsString('already in your booking', $duplicate['lines'][1]['reason']);

        $distant = $service->evaluateGroup([$room, ['facility_id' => $this->cottage->id, 'date' => Carbon::parse($date)->addWeek()->toDateString(), 'swimming_session_id' => $this->session->id, 'guest_count' => 5]]);
        $this->assertFalse($distant['all_available']);
        $this->assertStringContainsString('same resort visit', $distant['lines'][1]['reason']);
    }

    public function test_cottage_requires_an_explicit_active_session_and_date(): void
    {
        $service = app(GroupedAvailabilityService::class);
        $line = ['facility_id' => $this->cottage->id, 'date' => now()->addMonth()->toDateString(), 'guest_count' => 5];
        $this->assertFalse($service->evaluateGroup([$line])['all_available']);
        $this->session->update(['is_active' => false]);
        $this->assertFalse($service->evaluateGroup([array_merge($line, ['swimming_session_id' => $this->session->id])])['all_available']);
    }

    public function test_customer_can_view_unified_booking_workspace(): void
    {
        $response = $this->actingAs($this->customer)
            ->get(route('customer.grouped-booking.workspace', [
                'facility_id' => $this->room->id,
                'check_in' => '2026-11-10 14:00',
                'check_out' => '2026-11-11 12:00',
                'guests' => 2,
            ]));

        $this->assertMatchesRegularExpression('/<h1\b[^>]*>New reservation<\/h1>/', $response->getContent());
        $response->assertOk()
            ->assertDontSee('Unified Booking Workspace')
            ->assertSee('id="account-navigation"', false)
            ->assertSee('aria-controls="account-navigation"', false)
            ->assertSee('Toggle navigation menu')
            ->assertSee('Services')
            ->assertSee('Guest details')
            ->assertSee('Review');
    }

    public function test_guest_must_sign_in_before_opening_unified_booking_workspace(): void
    {
        $this->get(route('customer.grouped-booking.workspace'))
            ->assertRedirect(route('login'));

        $this->get(route('facilities.show', $this->room))
            ->assertOk()
            ->assertSee('Add to booking')
            ->assertSee(route('customer.booking.legacy', $this->room));
    }

    public function test_public_group_intent_returns_to_the_same_basket_after_authentication(): void
    {
        $date = now()->addMonth()->toDateString();
        $lines = [['facility_id' => $this->cottage->id, 'date' => $date, 'swimming_session_id' => $this->session->id, 'guest_count' => 5]];
        $response = $this->postJson(route('reservation.intent'), ['lines' => $lines]);

        $response->assertOk()->assertJsonPath('redirect', route('customer.grouped-booking.workspace', ['resume' => 1]));
        $this->assertSame($lines, session('grouped_booking.intent.lines'));
        $this->assertSame(route('customer.grouped-booking.workspace', ['resume' => 1]), session('url.intended'));
    }

    public function test_guest_can_add_a_room_before_choosing_visit_dates(): void
    {
        $this->postJson(route('reservation.intent'), ['facility_id' => $this->room->id])
            ->assertOk()
            ->assertJsonPath('authenticated', false)
            ->assertJsonPath('name', $this->room->name);

        $this->assertSame($this->room->id, session('grouped_booking.intent.lines.0.facility_id'));
        $this->assertSame(0, session('grouped_booking.intent.lines.0.guest_count'));
        $this->assertNull(session('grouped_booking.intent.lines.0.check_in'));
        $this->assertNull(session('grouped_booking.intent.lines.0.check_out'));
    }

    public function test_customer_home_category_opens_grouped_catalog(): void
    {
        $this->actingAs($this->customer)
            ->get(route('customer.home'))
            ->assertOk()
            ->assertSee(route('customer.grouped-booking.workspace', ['type' => 'room']));
    }

    public function test_signed_in_facility_detail_adds_to_grouped_workspace(): void
    {
        $this->actingAs($this->customer)
            ->get(route('customer.facilities.show', $this->room))
            ->assertOk()
            ->assertSee(route('customer.grouped-booking.workspace', ['facility_id' => $this->room->id]));
    }

    public function test_customer_can_submit_grouped_booking_request(): void
    {
        $nextMonth = now()->addMonth()->format('Y-m-d');
        $checkIn = "{$nextMonth} 14:00";
        $checkOut = Carbon::parse($checkIn)->addDay()->setTime(12, 0)->format('Y-m-d H:i');

        $service = app(GroupedAvailabilityService::class);
        $evaluation = $service->evaluateGroup([
            [
                'facility_id' => $this->room->id,
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'guest_count' => 2,
            ],
            [
                'facility_id' => $this->cottage->id,
                'date' => $nextMonth,
                'swimming_session_id' => $this->session->id,
                'guest_count' => 4,
            ],
        ]);

        $payload = [
            'lines' => [
                [
                    'facility_id' => $this->room->id,
                    'check_in' => $checkIn,
                    'check_out' => $checkOut,
                    'guest_count' => 2,
                    'special_requests' => 'Extra towels in the room',
                ],
                [
                    'facility_id' => $this->cottage->id,
                    'date' => $nextMonth,
                    'swimming_session_id' => $this->session->id,
                    'guest_count' => 4,
                    'special_requests' => 'A shaded seat at the cottage',
                ],
            ],
            'version_token' => $evaluation['version_token'],
            'guest_name' => 'Alexander Mauricio',
            'guest_email' => 'alexander@example.com',
            'guest_phone' => '09171234567',
            'guest_place_of_origin' => 'City of Ilagan, Isabela',
            'guest_nationality' => 'Filipino',
            'guest_company' => 'Tech Corp',
            'guest_valid_id' => GuestIdType::PhilSys->value,
            'guest_gender' => GuestGender::Male->value,
            'policies_accepted' => true,
            'special_requests' => 'We are celebrating a birthday',
        ];
        $payload += $this->depositProofFields();

        $response = $this->actingAs($this->customer)
            ->post(route('customer.grouped-booking.store'), $payload);

        $response->assertRedirect();

        $group = ReservationGroup::with('reservations')->latest('id')->first();
        $this->assertNotNull($group);
        $this->assertEquals(ReservationGroupStatus::Pending, $group->status);
        $this->assertEquals(ReservationSource::Online, $group->source);
        $this->assertCount(2, $group->reservations);
        $this->assertEquals(3000.00, (float) $group->total_amount);
        $this->assertEquals(1500.00, (float) $group->deposit_required);

        // Operational line records
        $roomRes = $group->reservations->firstWhere('facility_id', $this->room->id);
        $cottageRes = $group->reservations->firstWhere('facility_id', $this->cottage->id);

        $this->assertNotNull($roomRes);
        $this->assertNotNull($cottageRes);
        $this->assertSame('Extra towels in the room', $roomRes->special_requests);
        $this->assertSame('A shaded seat at the cottage', $cottageRes->special_requests);
        $this->assertSame('We are celebrating a birthday', $group->special_requests);
        $this->actingAs($this->admin)->get(route('admin.reservation-groups.show', $group))
            ->assertOk()
            ->assertSee('Extra towels in the room')
            ->assertSee('A shaded seat at the cottage')
            ->assertSee('We are celebrating a birthday');
        $this->assertEquals(2500.00, (float) $roomRes->total_price);
        $this->assertEquals(500.00, (float) $cottageRes->total_price);
    }

    public function test_staff_settle_and_manage_three_services_as_one_group(): void
    {
        config(['payments.deposit_workflow' => true]);
        $today = \App\Support\ResortTime::databaseNow();
        $group = ReservationGroup::factory()->create(['status' => ReservationGroupStatus::Approved, 'total_amount' => 12400, 'deposit_required' => 6200]);
        foreach ([3400, 2500, 6500] as $price) {
            Reservation::factory()->create(['reservation_group_id' => $group->id, 'status' => ReservationStatus::Approved,
                'total_price' => $price, 'user_id' => $this->customer->id, 'check_in' => $today, 'check_out' => $today->copy()->addDay()]);
        }
        \App\Models\Payment::factory()->create(['reservation_id' => null, 'reservation_group_id' => $group->id, 'amount' => 6200, 'status' => \App\Enums\PaymentStatus::Verified]);
        $page = route('admin.reservation-groups.show', $group);
        $this->actingAs($this->admin)->get($page)->assertOk()
            ->assertSee('Record group payment')
            ->assertSee('Group payments')
            ->assertSee('Supporting payment records')
            ->assertSee('Front desk adjustments')
            ->assertDontSee('Line details');

        foreach ($group->reservations as $line) {
            $this->get($page)->assertSee(route('admin.reservations.guest-information-sheet', $line));
        }
        $url = route('admin.reservation-groups.balance', $group);
        $payload = ['amount' => 6200, 'method' => 'cash', 'funds_received' => '1', 'collected_at' => \App\Support\ResortTime::now()->subMinute()->format('Y-m-d H:i')];
        $this->post($url, $payload)->assertSessionHas('success');
        $this->assertEquals(0, $group->outstandingBalance());
        $this->post($url, $payload)->assertSessionHas('error');
        $this->get($page)->assertOk()->assertSee('Check in group')->assertDontSee('Record group payment');
        $this->patch(route('admin.reservation-groups.attendance', [$group, 'check-in']))->assertSessionHas('success');
        $this->assertSame(3, $group->reservations()->whereNotNull('checked_in_at')->count());
        $this->get($page)->assertOk()
            ->assertDontSee('id="refund-flow"', false)
            ->assertDontSee('id="group-reschedule"', false)
            ->assertDontSee('href="#refund-flow"', false)
            ->assertDontSee('href="#group-reschedule"', false);
        $this->patch(route('admin.reservation-groups.attendance', [$group, 'check-out']))->assertSessionHas('success');
        $this->assertSame(3, $group->reservations()->whereNotNull('checked_out_at')->count());
        $this->get($page)->assertOk()
            ->assertDontSee('id="refund-flow"', false)
            ->assertDontSee('id="group-reschedule"', false);
    }

    public function test_admin_can_approve_entire_group_request(): void
    {
        $nextMonth = now()->addMonth()->format('Y-m-d');
        $bookingService = app(GroupedBookingService::class);

        $group = $bookingService->createGroup(
            actor: $this->customer,
            lines: [
                [
                    'facility_id' => $this->room->id,
                    'check_in' => "{$nextMonth} 14:00",
                    'check_out' => Carbon::parse("{$nextMonth} 14:00")->addDay()->setTime(12, 0)->format('Y-m-d H:i'),
                    'guest_count' => 2,
                ],
            ],
            guestData: [
                'guest_name' => 'Alexander Mauricio',
                'guest_email' => 'alexander@example.com',
                'guest_phone' => '09171234567',
                'guest_place_of_origin' => 'Ilagan',
                'guest_nationality' => 'Filipino',
                'guest_valid_id' => GuestIdType::PhilSys->value,
                'guest_gender' => GuestGender::Male->value,
            ],
            source: ReservationSource::Online,
            options: ['payment' => [
                'amount' => 1250.00,
                'method' => \App\Enums\PaymentMethod::GCash,
                'reference_number' => 'APPROVAL-123',
                'payment_date' => now()->toDateString(),
            ]]
        );

        $this->assertEquals(ReservationGroupStatus::Pending, $group->status);
        $line = $group->reservations->first();
        $this->assertEquals(ReservationStatus::Pending, $line->status);

        $this->actingAs($this->admin)->patch(route('admin.reservation-groups.approve', $group));
        $this->assertEquals(ReservationGroupStatus::Pending, $group->fresh()->status);
        app(\App\Services\PaymentService::class)->verifyPayment($group->directPayments()->firstOrFail(), $this->admin);

        $response = $this->actingAs($this->admin)
            ->patch(route('admin.reservation-groups.approve', $group));

        $response->assertRedirect();
        $group->refresh();
        $line->refresh();

        $this->assertEquals(ReservationGroupStatus::Approved, $group->status);
        $this->assertNotNull($group->approved_at);
        $this->assertEquals($this->admin->id, $group->approved_by);
        $this->assertEquals(ReservationStatus::Approved, $line->status);
    }

    public function test_admin_can_create_walk_in_group_reservation(): void
    {
        $today = now()->addDay()->format('Y-m-d');

        $response = $this->actingAs($this->admin)
            ->post(route('admin.reservations.walk-in.store'), [
                'lines' => [
                    [
                        'facility_id' => $this->room->id,
                        'check_in' => "{$today} 14:00",
                        'check_out' => Carbon::parse("{$today} 14:00")->addDay()->setTime(12, 0)->format('Y-m-d H:i'),
                        'guest_count' => 2,
                    ],
                ],
                'guest_name' => 'Walk-In Guest',
                'guest_email' => 'walkin@example.com',
                'guest_phone' => '09170001122',
                'guest_place_of_origin' => 'Cauayan City',
                'guest_nationality' => 'Filipino',
                'guest_valid_id' => GuestIdType::DriversLicense->value,
                'guest_gender' => GuestGender::Female->value,
                'admin_notes' => 'Encoded directly at front desk',
            ]);

        $response->assertRedirect();

        $group = ReservationGroup::latest('id')->first();
        $this->assertNotNull($group);
        $this->assertEquals(ReservationSource::WalkIn, $group->source);
        $this->assertEquals(ReservationGroupStatus::Approved, $group->status);
        $this->assertEquals('Walk-In Guest', $group->guest_name);
        $this->assertEquals(2500.00, (float) $group->total_amount);
    }

    public function test_conflicting_line_in_group_aborts_entire_creation(): void
    {
        $nextMonth = now()->addMonth()->format('Y-m-d');
        $checkIn = "{$nextMonth} 14:00";
        $checkOut = Carbon::parse($checkIn)->addDay()->setTime(12, 0)->format('Y-m-d H:i');

        // Create an existing reservation that takes Deluxe Room 101
        Reservation::create([
            'user_id' => $this->admin->id,
            'facility_id' => $this->room->id,
            'reservation_code' => 'RES-TAKEN-999',
            'reservation_date' => now(),
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'guest_count' => 2,
            'base_price' => 2500.00,
            'total_price' => 2500.00,
            'status' => ReservationStatus::Approved,
            'reservation_source' => ReservationSource::Online,
            'guest_name' => 'Prior Guest',
            'guest_email' => 'prior@example.com',
            'guest_phone' => '09171112233',
        ]);

        $payload = [
            'lines' => [
                [
                    'facility_id' => $this->room->id, // CONFLICT!
                    'check_in' => $checkIn,
                    'check_out' => $checkOut,
                    'guest_count' => 2,
                ],
                [
                    'facility_id' => $this->cottage->id, // FREE
                    'date' => $nextMonth,
                    'swimming_session_id' => $this->session->id,
                    'guest_count' => 4,
                ],
            ],
            'guest_name' => 'Alexander Mauricio',
            'guest_email' => 'alexander@example.com',
            'guest_phone' => '09171234567',
            'guest_place_of_origin' => 'City of Ilagan, Isabela',
            'guest_nationality' => 'Filipino',
            'guest_valid_id' => GuestIdType::PhilSys->value,
            'guest_gender' => GuestGender::Male->value,
            'policies_accepted' => true,
        ];
        $payload += $this->depositProofFields();

        $response = $this->actingAs($this->customer)
            ->post(route('customer.grouped-booking.store'), $payload);

        $response->assertSessionHas('error');

        // Verify no partial group or reservation was persisted for the cottage
        $this->assertDatabaseMissing('reservations', [
            'facility_id' => $this->cottage->id,
        ]);
        $this->assertDatabaseMissing('reservation_groups', [
            'guest_email' => 'alexander@example.com',
        ]);
    }

    public function test_admin_can_create_pending_walk_in_group_with_override(): void
    {
        $today = now()->addDay()->format('Y-m-d');

        $response = $this->actingAs($this->admin)
            ->post(route('admin.reservations.walk-in.store'), [
                'lines' => [
                    [
                        'facility_id' => $this->room->id,
                        'check_in' => "{$today} 14:00",
                        'check_out' => Carbon::parse("{$today} 14:00")->addDay()->setTime(12, 0)->format('Y-m-d H:i'),
                        'guest_count' => 2,
                    ],
                ],
                'creation_action' => 'pending',
                'override_reason' => 'VIP management approval',
                'guest_name' => 'Pending Walk-In Guest',
                'guest_email' => 'pending.walkin@example.com',
                'guest_phone' => '09170003344',
                'guest_place_of_origin' => 'Echague, Isabela',
                'guest_nationality' => 'Filipino',
                'guest_valid_id' => GuestIdType::DriversLicense->value,
                'guest_gender' => GuestGender::Male->value,
                'admin_notes' => 'Waiting for deposit settlement',
            ]);

        $response->assertRedirect();

        $group = ReservationGroup::where('guest_email', 'pending.walkin@example.com')->firstOrFail();
        $this->assertEquals(ReservationGroupStatus::Pending, $group->status);
        $this->assertNull($group->approved_at);
        $this->assertEquals(ReservationSource::WalkIn, $group->source);
    }

    public function test_admin_can_view_group_details_and_print_summary(): void
    {
        $today = now()->addDays(3)->format('Y-m-d');
        $bookingService = app(GroupedBookingService::class);

        $group = $bookingService->createGroup(
            actor: $this->admin,
            lines: [
                [
                    'facility_id' => $this->room->id,
                    'check_in' => "{$today} 14:00",
                    'check_out' => Carbon::parse("{$today} 14:00")->addDay()->setTime(12, 0)->format('Y-m-d H:i'),
                    'guest_count' => 2,
                ],
            ],
            guestData: [
                'guest_name' => 'Print Summary Guest',
                'guest_email' => 'print.guest@example.com',
                'guest_phone' => '09179998877',
                'guest_place_of_origin' => 'Santiago City',
                'guest_nationality' => 'Filipino',
                'guest_valid_id' => GuestIdType::PhilSys->value,
                'guest_gender' => GuestGender::Female->value,
            ],
            source: ReservationSource::WalkIn
        );

        $showResponse = $this->actingAs($this->admin)->get(route('admin.reservation-groups.show', $group));
        $showResponse->assertOk();
        $showResponse->assertSee($group->group_code);
        $showResponse->assertSee('Print Summary Guest');

        $printResponse = $this->actingAs($this->admin)->get(route('admin.reservation-groups.print', $group));
        $printResponse->assertOk();
        $printResponse->assertSee($group->group_code);
        $printResponse->assertSee('Grouped Booking Summary');
    }

    public function test_customer_can_book_a_priced_hall_with_payment_proof(): void
    {
        \Illuminate\Support\Facades\Storage::fake('uploads');
        $destination = \App\Models\PaymentDestination::create([
            'method' => \App\Enums\PaymentMethod::GCash,
            'account_name' => 'Villa Mercedes GCash',
            'account_number' => '09171234567',
            'qr_path' => 'qr/gcash.png',
            'is_active' => true,
        ]);

        $today = now()->addDays(5)->format('Y-m-d');
        $proofFile = \Illuminate\Http\UploadedFile::fake()->image('deposit_slip.jpg');

        $response = $this->actingAs($this->customer)->post(route('customer.grouped-booking.store'), [
            'lines' => [
                [
                    'facility_id' => $this->hall->id,
                    'check_in' => "{$today} 09:00",
                    'check_out' => "{$today} 13:00",
                    'guest_count' => 50,
                ],
            ],
            'guest_name' => $this->customer->name,
            'guest_email' => $this->customer->email,
            'guest_phone' => $this->customer->phone,
            'guest_place_of_origin' => $this->customer->address,
            'guest_nationality' => $this->customer->nationality,
            'guest_valid_id' => $this->customer->valid_id_type->value,
            'guest_gender' => $this->customer->gender->value,
            'policies_accepted' => '1',
            'policy_version' => config('resort.policy.version'),
            'payment_destination_id' => $destination->id,
            'payment_method' => 'gcash',
            'payment_date' => now()->toDateString(),
            'proof' => $proofFile,
        ]);

        $response->assertRedirect();
        $group = ReservationGroup::latest()->first();
        $this->assertNotNull($group);
        $this->assertSame(config('resort.policy.version'), $group->policy_version);
        $this->assertFalse($group->requires_quote);
        $this->assertEquals(15000.00, (float) $group->total_amount);
        
        $payment = $group->directPayments()->first();
        $this->assertNotNull($payment);
        $this->assertEquals(7500.00, (float) $payment->amount);
        $this->assertNull($payment->reference_number);
        $this->assertSame(\App\Enums\PaymentStatus::Pending, $payment->status);
    }

    public function test_late_same_day_room_booking_can_be_paid_approved_and_checked_in(): void
    {
        $this->travelTo(Carbon::parse('2026-11-13 16:00', 'Asia/Manila'));
        config(['payments.deposit_workflow' => true, 'resort.policy.version' => config('resort.policy.confirmed_version')]);
        \Illuminate\Support\Facades\Notification::fake();
        \Illuminate\Support\Facades\Storage::fake('uploads');
        $destination = \App\Models\PaymentDestination::create([
            'method' => \App\Enums\PaymentMethod::GCash,
            'account_name' => 'Villa Mercedes GCash',
            'account_number' => '09171234567',
            'qr_path' => 'qr/gcash.png',
            'is_active' => true,
        ]);

        $today = '2026-11-13';
        $proofFile = \Illuminate\Http\UploadedFile::fake()->image('deposit_slip.jpg');

        $response = $this->actingAs($this->customer)->post(route('customer.grouped-booking.store'), [
            'lines' => [
                [
                    'facility_id' => $this->room->id,
                    'check_in' => "{$today} 14:00",
                    'check_out' => Carbon::parse("{$today} 14:00")->addDay()->setTime(12, 0)->format('Y-m-d H:i'),
                    'guest_count' => 2,
                ],
            ],
            'guest_name' => $this->customer->name,
            'guest_email' => $this->customer->email,
            'guest_phone' => $this->customer->phone,
            'guest_place_of_origin' => $this->customer->address,
            'guest_nationality' => $this->customer->nationality,
            'guest_valid_id' => $this->customer->valid_id_type->value,
            'guest_gender' => $this->customer->gender->value,
            'policies_accepted' => '1',
            'policy_version' => config('resort.policy.version'),
            'payment_destination_id' => $destination->id,
            'payment_method' => 'gcash',
            'reference_number' => 'GCASH-123456789',
            'payment_date' => now()->toDateString(),
            'proof' => $proofFile,
        ]);

        $response->assertSessionHasNoErrors()->assertRedirect();
        $group = ReservationGroup::latest()->first();
        $this->assertNotNull($group);
        $this->assertSame(config('resort.policy.version'), $group->policy_version);
        $this->assertFalse($group->requires_quote);
        
        $payment = $group->directPayments()->first();
        $this->assertNotNull($payment);
        $this->assertSame('GCASH-123456789', $payment->reference_number);
        $this->assertSame(\App\Enums\PaymentStatus::Pending, $payment->status);
        $line = $group->reservations()->firstOrFail();
        $this->assertTrue($line->deposit_due_at->isFuture());
        $this->assertEquals(2500, $line->total_price);
        $this->assertSame('2026-11-13 14:00', $line->check_in->format('Y-m-d H:i'));
        $this->assertSame('2026-11-14 12:00', $line->check_out->format('Y-m-d H:i'));
        $this->artisan('reservations:expire-unpaid')->assertSuccessful();
        $this->assertSame(ReservationStatus::Pending, $line->fresh()->status);
        app(\App\Services\PaymentService::class)->verifyPayment($payment, $this->admin);
        app(GroupedBookingService::class)->approveGroup($group, $this->admin);
        $this->assertSame(ReservationGroupStatus::Approved, $group->fresh()->status);
        $this->assertTrue($group->fresh()->deposit_due_at->isFuture());
        $this->assertSame(ReservationStatus::Approved, $line->fresh()->status);
        $groupPage = route('admin.reservation-groups.show', $group);
        $this->actingAs($this->admin)->get($groupPage)->assertOk()->assertSee('Record balance payment')->assertDontSee('Confirm check-in');
        $balance = \App\Models\Payment::factory()->create([
            'reservation_id' => null, 'reservation_group_id' => $group->id,
            'amount' => $group->fresh()->outstandingBalance(),
            'status' => \App\Enums\PaymentStatus::Pending,
        ]);
        app(\App\Services\PaymentService::class)->verifyPayment($balance, $this->admin);
        $this->get($groupPage)->assertOk()->assertSee('Confirm check-in')->assertDontSee('Record balance payment');
        app(\App\Services\ReservationService::class)->checkIn($line->fresh(), $this->admin);
        $this->assertNotNull($line->fresh()->checked_in_at);
    }

    public function test_admin_can_quote_an_existing_request_and_customer_can_pay(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        $today = now()->addDays(6)->format('Y-m-d');

        $group = app(GroupedBookingService::class)->createGroup(
            actor: $this->customer,
            lines: [
                [
                    'facility_id' => $this->hall->id,
                    'check_in' => "{$today} 09:00",
                    'check_out' => "{$today} 13:00",
                    'guest_count' => 50,
                ],
            ],
            guestData: [],
            source: ReservationSource::Online,
            options: ['policies_accepted' => true, 'requires_quote' => true],
        );
        $this->assertTrue($group->requires_quote);
        $this->assertNotNull($group->quote_requested_at);

        // Admin provides quote
        $quoteResponse = $this->actingAs($this->admin)->patch(route('admin.reservation-groups.quote', $group), [
            'quoted_total' => 16000.00,
            'notes' => 'Includes sound system and air conditioning.',
        ]);
        $quoteResponse->assertRedirect();

        $group->refresh();
        $this->assertFalse($group->requires_quote);
        $this->assertEquals(16000.00, (float) $group->total_amount);
        $this->assertEquals(8000.00, (float) $group->deposit_required);
        $this->assertEquals(16000.00, (float) $group->reservations()->firstOrFail()->total_price);
        $this->assertNotNull($group->quoted_at);
        $this->assertSame($this->admin->id, $group->quoted_by);

        \Illuminate\Support\Facades\Notification::assertSentTo(
            $this->customer,
            \App\Notifications\Reservation\GroupQuotationProvidedMail::class,
            function ($notification) use ($group) {
                return $notification->group->id === $group->id && $notification->notes === 'Includes sound system and air conditioning.';
            }
        );

        $this->actingAs($this->customer)->get(route('customer.grouped-booking.quote-checkout', $group))
            ->assertOk()->assertSee('Review your quotation');
        $destination = \App\Models\PaymentDestination::create([
            'method' => \App\Enums\PaymentMethod::GCash,
            'account_name' => 'Villa Mercedes GCash',
            'account_number' => '09171234567',
            'qr_path' => 'qr/gcash.png',
            'is_active' => true,
        ]);
        \Illuminate\Support\Facades\Storage::fake('uploads');
        $this->actingAs($this->customer)->post(route('customer.grouped-booking.quote-deposit', $group), [
            'policy_version' => config('resort.policy.version'),
            'policies_accepted' => '1',
            'payment_destination_id' => $destination->id,
            'payment_date' => now()->toDateString(),
            'proof' => \Illuminate\Http\UploadedFile::fake()->image('receipt.jpg'),
        ])->assertRedirect(route('customer.reservations.index', ['group' => $group->group_code]));
        $this->assertEquals(8000.00, (float) $group->directPayments()->first()->amount);
        $this->actingAs($this->customer)->post(route('customer.grouped-booking.quote-deposit', $group), [
            'policy_version' => config('resort.policy.version'),
            'policies_accepted' => '1',
            'payment_destination_id' => $destination->id,
            'payment_date' => now()->toDateString(),
            'proof' => \Illuminate\Http\UploadedFile::fake()->image('receipt-again.jpg'),
        ])->assertSessionHasErrors('group');
        $this->assertSame(1, $group->directPayments()->count());
    }

    public function test_admin_decline_with_full_refund_workflow(): void
    {
        $today = now()->addDays(4)->format('Y-m-d');
        $bookingService = app(GroupedBookingService::class);

        $group = $bookingService->createGroup(
            actor: $this->customer,
            lines: [
                [
                    'facility_id' => $this->room->id,
                    'check_in' => "{$today} 14:00",
                    'check_out' => Carbon::parse("{$today} 14:00")->addDay()->setTime(12, 0)->format('Y-m-d H:i'),
                    'guest_count' => 2,
                ],
            ],
            guestData: [
                'guest_name' => $this->customer->name,
                'guest_email' => $this->customer->email,
                'guest_phone' => $this->customer->phone,
                'guest_place_of_origin' => $this->customer->address,
                'guest_nationality' => $this->customer->nationality,
                'guest_valid_id' => $this->customer->valid_id_type->value,
                'guest_gender' => $this->customer->gender->value,
            ],
            source: ReservationSource::Online,
            options: [
                'payment' => [
                    'amount' => 1250.00,
                    'method' => \App\Enums\PaymentMethod::GCash,
                    'reference_number' => 'GCASH-998877',
                    'payment_date' => now()->toDateString(),
                ],
            ]
        );

        $this->assertEquals(1250.00, $group->pendingPaymentsTotal());

        $declineResponse = $this->actingAs($this->admin)->patch(route('admin.reservation-groups.reject', $group), [
            'reason' => 'Facility under scheduled maintenance on those dates.',
            'refund_status' => 'pending',
        ]);

        $declineResponse->assertRedirect();
        $group->refresh();
        $this->assertSame(ReservationGroupStatus::Rejected, $group->status);
        $this->assertSame('requested', $group->refund_status);
        $this->assertEquals(0.00, (float) $group->refund_amount);
        $this->assertNull($group->refunded_at);
        $this->assertNull($group->refunded_by);

        \Illuminate\Support\Facades\Notification::fake();
        app(\App\Services\PaymentService::class)->verifyPayment($group->directPayments()->firstOrFail(), $this->admin);
        $this->actingAs($this->admin)->patch(route('admin.reservation-groups.refund', $group), [
            'refund_amount' => '1250.00', 'refund_reference' => 'No staff return authority',
        ])->assertSessionHas('error');
        app(\App\Services\BookingPracticeService::class)->approveRefund($group->reservations()->firstOrFail(), $this->admin, 'Owner approved full group refund.');
        $this->assertSame('approved', $group->fresh()->refund_status);
        $this->actingAs($this->admin)->patch(route('admin.reservation-groups.refund', $group), [
            'refund_amount' => '1250.00',
            'refund_reference' => 'GCash reversal trans ref REV-12345',
        ])->assertRedirect();
        $group->refresh();
        $this->assertSame('processed', $group->refund_status);
        $this->assertEquals(1250.00, (float) $group->refund_amount);
        $this->assertNotNull($group->refunded_at);
    }

    public function test_admin_verifying_group_deposit_sends_customer_notification_email(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        $today = now()->addDays(5)->format('Y-m-d');
        $bookingService = app(GroupedBookingService::class);
        $paymentService = app(\App\Services\PaymentService::class);

        $group = $bookingService->createGroup(
            actor: $this->customer,
            lines: [
                [
                    'facility_id' => $this->room->id,
                    'check_in' => "{$today} 14:00",
                    'check_out' => Carbon::parse("{$today} 14:00")->addDay()->setTime(12, 0)->format('Y-m-d H:i'),
                    'guest_count' => 2,
                ],
            ],
            guestData: [
                'guest_name' => $this->customer->name,
                'guest_email' => $this->customer->email,
                'guest_phone' => $this->customer->phone,
                'guest_place_of_origin' => $this->customer->address,
                'guest_nationality' => $this->customer->nationality,
                'guest_valid_id' => $this->customer->valid_id_type->value,
                'guest_gender' => $this->customer->gender->value,
            ],
            source: ReservationSource::Online,
            options: [
                'payment' => [
                    'amount' => 1250.00,
                    'method' => \App\Enums\PaymentMethod::GCash,
                    'reference_number' => 'REF-GROUP-DEP-1',
                    'payment_date' => now()->toDateString(),
                ],
            ]
        );

        $payment = $group->directPayments()->first();
        $this->assertNotNull($payment);

        $this->actingAs($this->admin)->get(route('admin.reservations.index'))->assertSee(route('admin.payments.show', $payment), false);
        $response = $this->actingAs($this->admin)->patch(route('admin.payments.verify', $payment), [
            'funds_received' => '1',
        ]);
        $response->assertRedirect(route('admin.reservation-groups.show', $group->id));

        $payment->refresh();
        $this->assertSame(\App\Enums\PaymentStatus::Verified, $payment->status);
        $this->assertTrue($group->fresh()->isApproved());
        $line = $group->reservations()->firstOrFail()->refresh();
        $this->assertEquals(1250.00, $line->getVerifiedTotalPaid());
        $this->assertTrue($line->canShowArrivalPass());

        \Illuminate\Support\Facades\Notification::assertSentTo(
            $this->customer,
            \App\Notifications\Reservation\GroupDepositVerifiedMail::class,
            function ($notification) use ($group, $payment) {
                return $notification->group->id === $group->id && $notification->payment->id === $payment->id;
            }
        );
    }

    private function depositProofFields(): array
    {
        \Illuminate\Support\Facades\Storage::fake('uploads');
        $destination = \App\Models\PaymentDestination::create([
            'method' => \App\Enums\PaymentMethod::GCash,
            'account_name' => 'Villa Mercedes GCash',
            'account_number' => '09171234567',
            'qr_path' => 'qr/gcash.png',
            'is_active' => true,
        ]);

        return [
            'payment_destination_id' => $destination->id,
            'payment_method' => 'gcash',
            'reference_number' => 'GCASH-TEST-123',
            'payment_date' => now()->toDateString(),
            'proof' => \Illuminate\Http\UploadedFile::fake()->image('deposit.jpg'),
        ];
    }
}
