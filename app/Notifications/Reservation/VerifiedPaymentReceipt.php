<?php

namespace App\Notifications\Reservation;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VerifiedPaymentReceipt extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Payment $payment) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        return $this->payment->fresh()?->status === PaymentStatus::Verified;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $payment = $this->payment->fresh() ?? $this->payment;
        $reservation = $payment->reservation;
        $group = $payment->reservationGroup;
        $mail = (new MailMessage)->subject('Your payment receipt for booking '.($reservation?->reservation_code ?? $group?->group_code))
            ->line('Thank you. We’ve checked and recorded your payment of '.Money::of($payment->amount).'.');
        $lines = $reservation ? collect([$reservation]) : $group->reservations;
        foreach ($lines as $line) {
            $mail->line($line->reservation_code.' — '.$line->facility?->name);
            $mail->line('Booking terms: '.$line->policy_version.'.');
            foreach ($line->priceItems() as $label => $cents) {
                $mail->line($label.': '.Money::of($cents / 100));
            }
        }

        return $mail->line('Remaining balance: '.Money::of($reservation ? $reservation->getRemainingBalance() : $group->outstandingBalance()))
            ->line('This receipt shows the payment we checked. See your booking for its confirmation status. For a tax invoice, please contact the resort.')
            ->action('View full receipt', route('customer.payments.receipt', $payment))
            ->salutation('The '.config('resort.short_name').' team');
    }
}
