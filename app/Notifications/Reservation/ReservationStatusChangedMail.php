<?php

namespace App\Notifications\Reservation;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Support\ReservationArrivalMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReservationStatusChangedMail extends Notification implements ShouldQueue
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

        $intro = match ($this->reservation->status) {
            ReservationStatus::Approved => $this->approvedIntro(),
            ReservationStatus::Rejected => $this->rejectedIntro(),
            ReservationStatus::Cancelled => $this->cancelledIntro(),
            ReservationStatus::Expired => $this->expiredIntro(),
            default => 'Your reservation '.$this->reservation->reservation_code.' is now '.$this->reservation->status->label().'.',
        };

        return ReservationArrivalMail::message($this->reservation, $intro);
    }

    /**
     * Approval is the moment the guest is told to pay, so this is the letter
     * that has to say what to do about it: the 50% deposit is open, and it has
     * a deadline that approval set. A booking whose deposit is already in — a
     * walk-in, a re-sent confirmation — is not asked for money again.
     */
    private function approvedIntro(): string
    {
        $depositOwed = $this->reservation->deposit_required !== null
            && $this->reservation->getVerifiedTotalPaid() < $this->reservation->requiredDepositAmount();

        return 'Your booking is confirmed. '
            . ($depositOwed
                ? 'Open your booking to pay the 50% deposit using the resort’s QR code. Upload your payment receipt before the deadline shown in your booking. You can pay the rest when you check in. Show this email or your check-in pass when you arrive.'
                : 'Show this email or your check-in pass at the front desk when you arrive.');
    }

    /**
     * An expired booking ended for one of two reasons, and the guest can only act
     * on the right one: either nobody answered the request in time, or approval
     * came and the 50% deposit did not.
     */
    private function expiredIntro(): string
    {
        return $this->reservation->approved_at === null
            ? 'Your booking request expired before we could confirm it. You can make a new request or call us for help finding another date or space.'
            : 'Your booking expired because we didn’t receive the 50% deposit by the deadline. If you’ve already paid, please contact us before sending any more money.';
    }

    private function rejectedIntro(): string
    {
        $reason = $this->reservation->rejection_reason;

        return 'We could not confirm this reservation.'
            . ($reason ? ' Reason: '.$reason : '')
            . ' If you’d like to visit on another date, we’d be happy to check what’s available.';
    }

    private function cancelledIntro(): string
    {
        $reason = $this->reservation->cancellation_reason;

        return 'This reservation has been cancelled.'
            . ($reason ? ' Reason: '.$reason : '')
            . ' If you weren’t expecting this cancellation, please contact us as soon as you can.';
    }
}
