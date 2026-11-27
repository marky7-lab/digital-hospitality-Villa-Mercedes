<?php

namespace App\Notifications\Auth;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

class QueuedEmailVerificationNotification extends VerifyEmail implements ShouldQueue
{
    use Queueable;

    protected function buildMailMessage($url): MailMessage
    {
        return (new MailMessage)
            ->subject('Confirm your email for '.config('resort.short_name'))
            ->greeting('Welcome to '.config('resort.short_name').'!')
            ->line('Please confirm this email address for your account.')
            ->action('Confirm my email', $url)
            ->line('If you didn’t create an account with us, you can ignore this email.')
            ->salutation('The '.config('resort.short_name').' team');
    }
}
