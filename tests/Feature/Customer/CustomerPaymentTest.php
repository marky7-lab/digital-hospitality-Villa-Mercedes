<?php

namespace Tests\Feature\Customer;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Models\Facility;
use App\Models\Payment;
use App\Models\Rate;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CustomerPaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $customer1;

    private User $customer2;

    private Facility $facility;

    private Reservation $reservation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer1 = User::factory()->create();
        $this->customer2 = User::factory()->create();

        $this->facility = Facility::factory()->create(['is_active' => true]);

        Rate::factory()->create([
            'facility_id' => $this->facility->id,
            'amount' => 5000,
            'is_active' => true,
        ]);

        $this->reservation = Reservation::factory()->create([
            'user_id' => $this->customer1->id,
            'facility_id' => $this->facility->id,
            'status' => ReservationStatus::Approved->value,
            'base_price' => 5000,
            'total_price' => 5000,
        ]);

        Storage::fake('uploads');
    }

    public function test_pending_reservation_payment_page_does_not_offer_payment_forms(): void
    {
        $this->reservation->update(['status' => ReservationStatus::Pending->value]);

        $this->actingAs($this->customer1)
            ->get(route('customer.payments.index', $this->reservation))
            ->assertOk()
            ->assertSee('Waiting for resort approval')
            ->assertDontSee('Submit payment')
            ->assertDontSee('Continue to secure checkout');
    }

    public function test_customer_cannot_submit_payment_for_pending_reservation(): void
    {
        $this->reservation->update(['status' => ReservationStatus::Pending->value]);

        $file = UploadedFile::fake()->image('proof.jpg');

        $this->actingAs($this->customer1)
            ->post(route('customer.payments.store', $this->reservation), [
                'amount' => 2500,
                'payment_method' => PaymentMethod::GCash->value,
                'proof' => $file,
            ])
            ->assertRedirect(route('customer.payments.index', $this->reservation))
            ->assertSessionHas('error');

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_customer_can_access_payment_page_for_own_reservation()
    {
        $response = $this->actingAs($this->customer1)
            ->get(route('customer.payments.index', $this->reservation));

        $response->assertStatus(200);
        $response->assertSee('Pay');
        $response->assertSee($this->reservation->reservation_code);
    }

    public function test_payment_navigation_preserves_the_reservation_list_context(): void
    {
        $context = ['tab' => 'upcoming', 'page' => 2];
        $record = [$this->reservation, ...$context];
        $paymentUrl = route('customer.payments.index', $record);

        $this->actingAs($this->customer1)
            ->get(route('customer.reservations.show', $record))
            ->assertOk()
            ->assertSee($paymentUrl);

        $this->get($paymentUrl)
            ->assertOk()
            ->assertSee(route('customer.reservations.index', $context))
            ->assertSee(route('customer.reservations.show', $record))
            ->assertSee(route('customer.reservations.show', [
                $this->reservation, 'view' => 'arrival', ...$context,
            ]));
    }

    public function test_customer_cannot_access_another_customers_payment_page()
    {
        $response = $this->actingAs($this->customer2)
            ->get(route('customer.payments.index', $this->reservation));

        $response->assertStatus(403);
    }

    public function test_customer_can_submit_valid_payment()
    {
        $file = UploadedFile::fake()->image('proof.jpg');

        $response = $this->actingAs($this->customer1)
            ->post(route('customer.payments.store', $this->reservation), [
                'amount' => 2500,
                'payment_method' => PaymentMethod::GCash->value,
                'reference_number' => 'REF123456',
                'proof' => $file,
            ]);

        $response->assertRedirect(route('customer.reservations.index', ['tab' => 'upcoming']));
        $response->assertSessionHas('success', fn (string $message) => str_contains($message, 'The admin will review the transfer'));

        $this->assertDatabaseHas('payments', [
            'reservation_id' => $this->reservation->id,
            'amount' => 2500,
            'method' => PaymentMethod::GCash->value,
            'reference_number' => 'REF123456',
            'status' => PaymentStatus::Pending->value,
        ]);

        $payment = Payment::first();

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk('uploads');
        $disk->assertExists($payment->proof_path);
    }

    public function test_invalid_amount_is_rejected()
    {
        $file = UploadedFile::fake()->image('proof.jpg');

        $response = $this->actingAs($this->customer1)
            ->post(route('customer.payments.store', $this->reservation), [
                'amount' => -100, // Invalid amount
                'payment_method' => PaymentMethod::GCash->value,
                'proof' => $file,
            ]);

        $response->assertSessionHasErrors(['amount']);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_invalid_proof_file_type_is_rejected()
    {
        // Try uploading a generic document that is not allowed (e.g. .txt)
        $file = UploadedFile::fake()->create('document.txt', 100, 'text/plain');

        $response = $this->actingAs($this->customer1)
            ->post(route('customer.payments.store', $this->reservation), [
                'amount' => 2500,
                'payment_method' => PaymentMethod::BankTransfer->value,
                'proof' => $file,
            ]);

        $response->assertSessionHasErrors(['proof']);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_oversized_file_is_rejected()
    {
        // Create an image that is 6MB (max is 5MB default)
        $file = UploadedFile::fake()->image('large.jpg')->size(6144);

        $response = $this->actingAs($this->customer1)
            ->post(route('customer.payments.store', $this->reservation), [
                'amount' => 2500,
                'payment_method' => PaymentMethod::GCash->value,
                'proof' => $file,
            ]);

        $response->assertSessionHasErrors(['proof']);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_customer_can_download_own_proof()
    {
        $file = UploadedFile::fake()->image('proof.jpg');
        $path = $file->store('proofs', 'uploads');

        $payment = Payment::factory()->create([
            'reservation_id' => $this->reservation->id,
            'proof_path' => $path,
        ]);

        $response = $this->actingAs($this->customer1)
            ->get(route('customer.payments.download', $payment));

        $response->assertStatus(200);
        $response->assertHeader('Content-Disposition');
    }

    public function test_customer_can_view_own_proof_inline(): void
    {
        $file = UploadedFile::fake()->image('proof.jpg');
        $path = $file->store('proofs', 'uploads');

        $payment = Payment::factory()->create([
            'reservation_id' => $this->reservation->id,
            'proof_path' => $path,
        ]);

        $response = $this->actingAs($this->customer1)
            ->get(route('customer.payments.view', $payment));

        $response->assertStatus(200);
        $this->assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_customer_cannot_download_another_customers_proof()
    {
        $file = UploadedFile::fake()->image('proof.jpg');
        $path = $file->store('proofs', 'uploads');

        $payment = Payment::factory()->create([
            'reservation_id' => $this->reservation->id,
            'proof_path' => $path,
        ]);

        $response = $this->actingAs($this->customer2)
            ->get(route('customer.payments.download', $payment));

        $response->assertStatus(403);
    }

    public function test_customer_cannot_submit_verification_fields()
    {
        $file = UploadedFile::fake()->image('proof.jpg');

        $response = $this->actingAs($this->customer1)
            ->post(route('customer.payments.store', $this->reservation), [
                'amount' => 2500,
                'payment_method' => PaymentMethod::Cash->value,
                'proof' => $file,
                'status' => PaymentStatus::Verified->value, // Trying to spoof status
                'verified_by' => 999,
            ]);

        $response->assertRedirect(route('customer.reservations.index', ['tab' => 'upcoming']));

        $this->assertDatabaseHas('payments', [
            'reservation_id' => $this->reservation->id,
            'status' => PaymentStatus::Pending->value, // Still pending
            'verified_by' => null, // Was not updated
        ]);
    }
}
