<?php

namespace App\Notifications\Reservation;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Support\ReservationArrivalMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReservationCreatedMail extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Reservation $reservation
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->reservation->loadMissing(['facility', 'user']);

        // A request that is still under review must not read like a booking: what
        // the guest was told at submission is what this copy repeats.
        $intro = match (true) {
            $this->reservation->status === ReservationStatus::Approved => 'Thanks for booking with us. Keep this email handy to show at the front desk when you arrive.',
            config('payments.deposit_workflow') => 'Thanks for sending your booking request. Our team will review it and check your payment. We’ll email you when there’s an update.',
            default => 'Thanks for sending your booking request. Our team is reviewing it. We’ll email you when your booking is confirmed.',
        };

        return ReservationArrivalMail::message($this->reservation, $intro);
    }
}
