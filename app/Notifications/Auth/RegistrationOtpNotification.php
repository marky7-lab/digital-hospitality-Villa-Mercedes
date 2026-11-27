<?php

namespace App\Notifications\Auth;

use App\Models\EmailOtpVerification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Hash;

class RegistrationOtpNotification extends Notification implements ShouldQueue, ShouldBeEncrypted
{
    use Queueable;

    // A brief SMTP disconnect should not consume the guest's only delivery attempt.
    // shouldSend() checks the current, unexpired challenge before every retry.
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [15, 45];

    public function __construct(
        public readonly string $code,
        public readonly int $expireMinutes,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        $email = $notifiable->routeNotificationFor('mail');
        if ($channel !== 'mail' || ! is_string($email)) {
            return false;
        }

        $record = EmailOtpVerification::query()->where('email', $email)->first();

        return $record !== null
            && $record->verified_at === null
            && $record->consumed_at === null
            && $record->expires_at->isFuture()
            && Hash::check($this->code, $record->otp_hash);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your '.config('resort.short_name').' verification code')
            ->greeting('Hello!')
            ->line('Enter this code on the sign-up page to confirm your email address.')
            ->line($this->code)
            ->line("This code expires in {$this->expireMinutes} minutes.")
            ->line('If you didn’t ask for this code, you can ignore this email. Please keep the code to yourself.')
            ->salutation('The '.config('resort.short_name').' team');
    }
}
