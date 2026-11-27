<?php

namespace App\Notifications\Reservation;

use App\Models\Reservation;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReservationWithdrawalRequestedNotification extends Notification
{
    public function __construct(
        public readonly Reservation $reservation
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Guest wants to withdraw booking request '.$this->reservation->reservation_code)
            ->line("{$this->reservation->user?->name} asked to withdraw booking request {$this->reservation->reservation_code}.")
            ->action('Review booking request', route('admin.reservations.show', $this->reservation))
            ->salutation('The '.config('resort.short_name').' team');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'reservation_id' => $this->reservation->id,
            'reservation_code' => $this->reservation->reservation_code,
            'message' => "A guest asked to withdraw booking request {$this->reservation->reservation_code}. Open the booking to review their request.",
        ];
    }
}
