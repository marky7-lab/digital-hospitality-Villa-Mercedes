<?php

namespace App\Notifications\Auth;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

class GuestPasswordResetNotification extends ResetPassword implements ShouldQueue
{
    use Queueable;

    public function toMail($notifiable): MailMessage
    {
        $url = $this->resetUrl($notifiable);
        $minutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        return (new MailMessage)
            ->subject('Reset your '.config('resort.short_name').' password')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('Use the button below to choose a new password for your '.config('resort.short_name').' account.')
            ->action('Choose a new password', $url)
            ->line("This link expires in {$minutes} minutes.")
            ->line('If you didn’t ask for a new password, you can ignore this email. Your current password will still work.')
            ->salutation('The '.config('resort.short_name').' team');
    }
}
