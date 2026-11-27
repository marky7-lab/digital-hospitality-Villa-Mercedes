<?php

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingMagicLinkNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $url,
        public readonly int $expireMinutes,
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
        return (new MailMessage)
            ->subject('Confirm your '.config('resort.short_name').' reservation')
            ->greeting('Hello!')
            ->line('Thanks for choosing Villa Mercedes. Use the button below to confirm your reservation. There is no charge when you use this link.')
            ->action('Confirm reservation', $this->url)
            ->line("This link expires in {$this->expireMinutes} minutes.")
            ->line('If you did not start a reservation, you can ignore this email.')
            ->salutation('The '.config('resort.short_name').' team');
    }
}
