<?php

namespace App\Notifications\Payment;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class PaymentStatusChangedNotification extends Notification implements ShouldQueue
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
        $statusValue = $this->payment->status->value;
        $reason = $this->payment->status === PaymentStatus::Rejected ? $this->payment->rejection_reason : null;
        $amount = Money::of($this->payment->amount);
        $code = $this->payment->reservation?->reservation_code
            ?? $this->payment->reservationGroup?->group_code
            ?? 'Unknown';

        return [
            'payment_id' => $this->payment->id,
            'reservation_id' => $this->payment->reservation_id,
            'reservation_code' => $code,
            'new_status' => $statusValue,
            'amount' => $this->payment->amount,
            'reason' => $reason,
            'message' => match ($this->payment->status) {
                PaymentStatus::Pending => "We’re checking your payment of {$amount} for booking {$code}.",
                PaymentStatus::Verified => "We verified your payment of {$amount} for booking {$code}. Open your booking to see your balance.",
                PaymentStatus::Rejected => "We couldn’t verify your payment of {$amount} for booking {$code}. Open your booking to see what needs attention.",
            },
        ];
    }
}
