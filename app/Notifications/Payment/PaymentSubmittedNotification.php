<?php

namespace App\Notifications\Payment;

use App\Models\Payment;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class PaymentSubmittedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Payment $payment
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $code = $this->payment->reservation?->reservation_code
            ?? $this->payment->reservationGroup?->group_code
            ?? 'Unknown';

        return [
            'payment_id' => $this->payment->id,
            'reservation_id' => $this->payment->reservation_id,
            'reservation_group_id' => $this->payment->reservation_group_id,
            'reservation_code' => $code,
            'amount' => $this->payment->amount,
            'method' => $this->payment->method?->value ?? 'manual',
            'status' => $this->payment->status?->value ?? 'pending',
            'message' => 'A payment of '.Money::of($this->payment->amount)." for booking {$code} is ready for review.",
        ];
    }
}
