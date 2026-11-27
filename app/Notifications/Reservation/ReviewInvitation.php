<?php

namespace App\Notifications\Reservation;

use App\Models\Reservation;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class ReviewInvitation extends Notification
{
    public function __construct(public Reservation $reservation, public string $channel) {}
    public function via(object $notifiable): array { return [$this->channel]; }
    public function toArray(object $notifiable): array
    {
        return ['reservation_id' => $this->reservation->id, 'review_invitation' => true, 'message' => 'How was your visit? Rate your experience at Villa Mercedes. Sharing feedback is optional.'];
    }
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('How was your visit to Villa Mercedes?')
            ->greeting('Hello!')
            ->line('Thank you for visiting Villa Mercedes. We’d love to hear what you enjoyed and what we could do better.')
            ->line('If you’d like to share feedback, give your visit a star rating and add a comment if you wish.')
            ->line('You can share your feedback with resort staff only, or let us post it on our website with your first name and last initial.')
            ->action('Rate your visit', route('customer.reservations.show', $this->reservation).'#review')
            ->salutation('Warm wishes,  '."\n".'The Villa Mercedes team');
    }
}
