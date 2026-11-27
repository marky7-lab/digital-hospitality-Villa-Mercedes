<?php

namespace App\Notifications\Reservation;

use App\Enums\Role;
use App\Models\Reservation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingDiscussionUpdate extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $reservationId, public readonly string $event) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $isGuest = ! $notifiable->hasAnyRole([Role::Administrator->value, Role::Owner->value]);

        return ['reservation_id' => $this->reservationId, 'message' => $this->message($isGuest)];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $line = Reservation::findOrFail($this->reservationId);
        $isStaff = $notifiable->hasRole(Role::Administrator->value);
        $isGuest = (int) $notifiable->getKey() === (int) $line->user_id;

        return (new MailMessage)->subject('Update for booking '.$line->reservation_code)
            ->line($this->message($isGuest))
            ->action('View booking', route($isStaff ? 'admin.reservations.show' : 'customer.reservations.show', $line))
            ->salutation('The '.config('resort.short_name').' team');
    }

    private function message(bool $isGuest): string
    {
        return match ($this->event) {
            'refund_requested' => $isGuest ? 'We received your request to cancel and receive a refund. The resort owner will review it.' : 'The guest asked to cancel this booking and receive a refund.',
            'refund_approved' => 'The resort owner approved the refund. Check the booking for an update on when the money is sent.',
            'funds_returned' => 'The resort has recorded the refund as sent.',
            'change_proposed' => $isGuest ? 'New dates have been proposed for your booking. Open your booking to review the dates and price.' : 'New dates and a price were proposed for this booking.',
            'change_accepted' => $isGuest ? 'We received your acceptance of the proposed dates and price. We’ll let you know when the change is confirmed.' : 'The guest accepted the proposed dates and price. The resort still needs to confirm the change.',
            'change_confirmed' => 'The new dates for this booking are confirmed.',
            'child_charge_approved' => 'The resort owner approved an extra charge for a child guest. Open the booking to see the details.',
            'child_charge_dispute' => $isGuest ? 'We received your request to review the extra charge for a child guest.' : 'The guest asked the resort to review the extra charge for a child guest.',
            'child_charge_resolve' => 'The resort recorded the outcome of the discussion about the child guest charge.',
            'early_arrival_recorded' => 'The resort added an early check-in fee to this booking.',
            'pet_fee_recorded' => 'The resort added a pet fee to this booking.',
            'arrival_discount_recorded' => 'The resort added a check-in discount to this booking.',
            default => 'There’s an update to this booking. Open the booking to see what changed.',
        };
    }
}
