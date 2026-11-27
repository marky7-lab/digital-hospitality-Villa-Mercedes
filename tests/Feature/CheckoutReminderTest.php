<?php

namespace Tests\Feature;

use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Enums\Role;
use App\Models\Facility;
use App\Models\Reservation;
use App\Models\User;
use App\Notifications\Reservation\CheckoutReminderNotification;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CheckoutReminderTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_gets_one_badged_reminder_twenty_minutes_before_extended_checkout(): void
    {
        $this->seed(RoleSeeder::class);
        $this->travelTo(Carbon::parse('2026-09-21 11:40:00'));

        $customer = User::factory()->create();
        $customer->assignRole(Role::Customer->value);
        $facility = Facility::factory()->create(['name' => 'Room 23']);
        $reservation = Reservation::factory()->create([
            'user_id' => $customer->id,
            'facility_id' => $facility->id,
            'reservation_source' => ReservationSource::Online->value,
            'status' => ReservationStatus::Approved->value,
            'check_in' => now()->subDay(),
            'check_out' => now()->subMinutes(40),
            'late_check_out_hours' => 1,
            'checked_in_at' => now()->subDay(),
            'checked_out_at' => null,
        ]);

        $this->artisan('reservations:send-checkout-reminders')
            ->expectsOutput('Sent 1 checkout reminders.')
            ->assertSuccessful();

        $notification = $customer->notifications()
            ->where('type', CheckoutReminderNotification::class)
            ->firstOrFail();
        $this->assertSame(CheckoutReminderNotification::class, $notification->type);
        $this->assertSame($reservation->id, $notification->data['reservation_id']);
        $this->assertStringContainsString('about 20 minutes left', $notification->data['message']);

        $this->artisan('reservations:send-checkout-reminders')
            ->expectsOutput('Sent 0 checkout reminders.')
            ->assertSuccessful();
        $this->assertSame(1, $customer->notifications()->where('type', CheckoutReminderNotification::class)->count());

        $this->actingAs($customer)
            ->get(route('customer.reservations.index'))
            ->assertOk()
            ->assertSee('20 minutes or less remain')
            ->assertSee('Room 23')
            ->assertSee('unread notifications');
    }
}
