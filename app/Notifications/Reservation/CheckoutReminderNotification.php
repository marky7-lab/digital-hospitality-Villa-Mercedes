<?php

namespace App\Notifications\Reservation;

use App\Models\Reservation;
use Illuminate\Notifications\Notification;

class CheckoutReminderNotification extends Notification
{
    public function __construct(public readonly Reservation $reservation) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'reservation_id' => $this->reservation->id,
            'reservation_code' => $this->reservation->reservation_code,
            'checkout_reminder' => true,
            'checkout_at' => $this->reservation->occupancyEnd()->toDateTimeString(),
            'message' => 'Your check-out time is '.$this->reservation->occupancyEnd()->format('g:i A').'. You have about 20 minutes left.',
        ];
    }
}
