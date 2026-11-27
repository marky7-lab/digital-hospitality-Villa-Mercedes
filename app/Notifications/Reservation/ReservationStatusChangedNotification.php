<?php

namespace App\Notifications\Reservation;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;

class ReservationStatusChangedNotification extends Notification
{
    public function __construct(
        public readonly Reservation $reservation
    ) {}

    /**
     * In-app badge updates stay on the request. SMTP is ReservationStatusChangedMail.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        if ($notifiable instanceof AnonymousNotifiable) {
            return [];
        }

        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $statusValue = $this->reservation->status->value;
        $code = $this->reservation->reservation_code;

        $reason = null;
        if ($statusValue === ReservationStatus::Rejected->value) {
            $reason = $this->reservation->rejection_reason;
        } elseif ($statusValue === ReservationStatus::Cancelled->value) {
            $reason = $this->reservation->cancellation_reason;
        }

        return [
            'reservation_id' => $this->reservation->id,
            'reservation_code' => $this->reservation->reservation_code,
            'new_status' => $statusValue,
            'reason' => $reason,
            'message' => match ($this->reservation->status) {
                ReservationStatus::Pending => "Your booking request {$code} is under review.",
                ReservationStatus::Approved => "Your booking {$code} is confirmed. Open it to see what’s next.",
                ReservationStatus::Rejected => "We couldn’t confirm your booking request {$code}. Open it for details.",
                ReservationStatus::Cancelled => "Your booking {$code} was cancelled. Open it for details.",
                ReservationStatus::Rescheduled => "Your booking {$code} was moved to new dates. Open it to see the details.",
                ReservationStatus::Completed => "Your visit for booking {$code} is complete. Thanks for visiting Villa Mercedes.",
                ReservationStatus::Expired => "Your booking {$code} has expired. Open it for details.",
            },
        ];
    }
}
