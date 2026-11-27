<?php

namespace App\Notifications\Reservation;

use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LateCheckoutUpdate extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $reservationId, public readonly array $decision) {}

    public function via(object $notifiable): array
    {
        return $this->decision['status'] === 'pending' ? ['database'] : ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $approved = $this->decision['status'] === 'approved';

        $mail = (new MailMessage)->subject($approved ? 'Your late check-out is approved' : 'An update on your late check-out request')
            ->line($approved
                ? 'You can check out at '.$this->decision['departure'].' Philippine time. We’ve added the late check-out fee of '.Money::of($this->decision['fee_cents'] / 100).' to the amount you still owe.'
                : 'We couldn’t approve late check-out for your booking. Please keep your original check-out time. There’s no extra charge.');

        if (! $approved && filled($this->decision['reason'] ?? null)) {
            $mail->line('Reason: '.$this->decision['reason']);
        }

        return $mail->action('View booking and balance', route('customer.reservations.show', $this->reservationId))
            ->salutation('The '.config('resort.short_name').' team');
    }

    public function toArray(object $notifiable): array
    {
        \App\Support\LayoutBadges::forgetUnread($notifiable->id);

        return [
            'reservation_id' => $this->reservationId,
            'message' => match ($this->decision['status']) {
                'approved' => 'Your late check-out is approved. You can check out at '.$this->decision['departure'].' Philippine time. The fee is now part of your balance.',
                'pending' => 'A guest requested late check-out. Open the booking to review the request.',
                default => 'We couldn’t approve late check-out. Please keep your original check-out time. There’s no extra charge.',
            },
        ];
    }
}
