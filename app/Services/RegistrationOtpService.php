<?php

namespace App\Services;

use App\Models\EmailOtpVerification;
use App\Models\User;
use App\Notifications\Auth\RegistrationOtpNotification;
use App\Support\OutboundMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class RegistrationOtpService
{
    public const SESSION_EMAIL = 'registration.verified_email';

    public const SESSION_ID = 'registration.verification_id';

    public const SESSION_VERIFIED_AT = 'registration.verified_at';

    public const SESSION_PENDING_EMAIL = 'registration.pending_email';

    public const SESSION_OTP_SENT_AT = 'registration.otp_sent_at';

    public const ELIGIBLE_STATUS = 'Look for your code in your inbox. If you don’t see it, check your spam folder or earlier Villa Mercedes emails.';

    public const EMAIL_TAKEN = 'This email already has an account.';

    public const SET_PASSWORD = 'This email already has a reservation. Set a password to sign in.';

    public function normalizeEmail(string $email): string
    {
        return Str::lower(trim($email));
    }

    public function send(string $email, string $ip): void
    {
        $email = $this->normalizeEmail($email);

        $existing = User::query()->where('email', $email)->first();

        if ($existing) {
            throw ValidationException::withMessages([
                'email' => $existing->isLightweight()
                    ? self::SET_PASSWORD
                    : self::EMAIL_TAKEN,
            ]);
        }

        $this->ensureSendNotRateLimited($email, $ip);

        $existing = EmailOtpVerification::query()->where('email', $email)->first();

        if ($this->isCoolingDown($existing)) {
            throw ValidationException::withMessages([
                'email' => 'Please wait before requesting another verification code.',
            ]);
        }

        $this->hitSendLimiters($email, $ip);

        if (! OutboundMail::isConfigured()) {
            throw ValidationException::withMessages([
                'email' => 'Email sending is not configured yet. Add SMTP credentials in the .env file.',
            ]);
        }

        $code = $this->generateCode();
        try {
            DB::transaction(function () use ($email, $existing, $code): void {
                EmailOtpVerification::query()->updateOrCreate(
                    ['email' => $email],
                    [
                        'otp_hash' => Hash::make($code),
                        'expires_at' => now()->addMinutes((int) config('otp.expire_minutes')),
                        'attempt_count' => 0,
                        'send_count' => ($existing?->send_count ?? 0) + 1,
                        'last_sent_at' => now(),
                        'verified_at' => null,
                        'consumed_at' => null,
                    ],
                );

                Notification::route('mail', $email)->notify(new RegistrationOtpNotification(
                    $code,
                    (int) config('otp.expire_minutes'),
                ));
            });
        } catch (Throwable $e) {
            report($e);

            throw ValidationException::withMessages([
                'email' => 'We could not queue the verification email. Please try again shortly.',
            ]);
        }

        // A newly issued challenge gets a fresh verification budget. Otherwise
        // a guest who requests a replacement code can remain locked out by the
        // attempts made against the code that was just invalidated.
        $this->clearVerifyLimiters($email, $ip);
        $this->forgetVerifiedState();
        $this->rememberPendingEmail($email);
    }

    public function verify(string $email, string $otp, string $ip): void
    {
        $email = $this->normalizeEmail($email);

        $this->ensureVerifyNotRateLimited($email, $ip);
        $this->hitVerifyLimiters($email, $ip);

        $record = EmailOtpVerification::query()->where('email', $email)->first();

        if (
            ! $record
            || $record->consumed_at !== null
            || $record->verified_at !== null
            || $record->expires_at->isPast()
            || $record->attempt_count >= (int) config('otp.max_verify_attempts')
        ) {
            throw ValidationException::withMessages([
                'otp' => 'Invalid or expired code.',
            ]);
        }

        if (! Hash::check($otp, $record->otp_hash)) {
            $record->increment('attempt_count');

            throw ValidationException::withMessages([
                'otp' => 'Invalid or expired code.',
            ]);
        }

        $record->forceFill([
            'verified_at' => now(),
        ])->save();

        $this->clearVerifyLimiters($email, $ip);

        session([
            self::SESSION_EMAIL => $email,
            self::SESSION_ID => $record->id,
            self::SESSION_VERIFIED_AT => now()->timestamp,
            self::SESSION_PENDING_EMAIL => $email,
        ]);
    }

    public function hasVerifiedEmail(string $email): bool
    {
        $email = $this->normalizeEmail($email);

        if (session(self::SESSION_EMAIL) !== $email) {
            return false;
        }

        $verifiedAt = session(self::SESSION_VERIFIED_AT);
        $lifetime = (int) config('otp.verified_session_minutes') * 60;

        if (! is_numeric($verifiedAt) || (now()->timestamp - (int) $verifiedAt) > $lifetime) {
            $this->forgetVerifiedState();

            return false;
        }

        $record = EmailOtpVerification::query()->find(session(self::SESSION_ID));

        return $record instanceof EmailOtpVerification
            && $record->email === $email
            && $record->verified_at !== null
            && $record->consumed_at === null;
    }

    public function consume(string $email): void
    {
        $email = $this->normalizeEmail($email);
        $record = EmailOtpVerification::query()->find(session(self::SESSION_ID));

        if ($record instanceof EmailOtpVerification && $record->email === $email) {
            $record->forceFill(['consumed_at' => now()])->save();
        }

        $this->forgetRegistrationState();
    }

    public function reset(): void
    {
        // A guest may clear their session, but an email address is not authority
        // to invalidate a challenge shared with another browser session.
        $this->forgetRegistrationState();
    }

    public function registrationStep(): string
    {
        $verifiedEmail = session(self::SESSION_EMAIL);

        if (is_string($verifiedEmail) && $this->hasVerifiedEmail($verifiedEmail)) {
            return 'details';
        }

        if (is_string(session(self::SESSION_PENDING_EMAIL)) && session(self::SESSION_PENDING_EMAIL) !== '') {
            return 'otp';
        }

        return 'email';
    }

    public function pendingEmail(): ?string
    {
        $email = session(self::SESSION_PENDING_EMAIL);

        return is_string($email) && $email !== '' ? $email : null;
    }

    public function verifiedEmail(): ?string
    {
        $email = session(self::SESSION_EMAIL);

        return is_string($email) && $this->hasVerifiedEmail($email) ? $email : null;
    }

    public function resendAvailableIn(): int
    {
        $sentAt = session(self::SESSION_OTP_SENT_AT);

        if (! is_numeric($sentAt)) {
            return 0;
        }

        $remaining = ((int) $sentAt + (int) config('otp.resend_cooldown_seconds')) - now()->timestamp;

        return max(0, $remaining);
    }

    public function forgetVerifiedState(): void
    {
        session()->forget([
            self::SESSION_EMAIL,
            self::SESSION_ID,
            self::SESSION_VERIFIED_AT,
        ]);
    }

    public function forgetRegistrationState(): void
    {
        session()->forget([
            self::SESSION_EMAIL,
            self::SESSION_ID,
            self::SESSION_VERIFIED_AT,
            self::SESSION_PENDING_EMAIL,
            self::SESSION_OTP_SENT_AT,
        ]);
    }

    private function rememberPendingEmail(string $email): void
    {
        session([
            self::SESSION_PENDING_EMAIL => $email,
            self::SESSION_OTP_SENT_AT => now()->timestamp,
        ]);
    }

    private function generateCode(): string
    {
        $digits = max(4, (int) config('otp.digits'));
        $max = (10 ** $digits) - 1;

        return str_pad((string) random_int(0, $max), $digits, '0', STR_PAD_LEFT);
    }

    private function isCoolingDown(?EmailOtpVerification $existing): bool
    {
        if ($existing?->last_sent_at === null) {
            $sentAt = session(self::SESSION_OTP_SENT_AT);

            if (! is_numeric($sentAt)) {
                return false;
            }

            return (now()->timestamp - (int) $sentAt) < (int) config('otp.resend_cooldown_seconds');
        }

        return $existing->last_sent_at->addSeconds((int) config('otp.resend_cooldown_seconds'))->isFuture();
    }

    private function ensureSendNotRateLimited(string $email, string $ip): void
    {
        if (RateLimiter::tooManyAttempts($this->sendEmailKey($email), (int) config('otp.max_sends_per_email'))) {
            $this->throwThrottle('email', $this->sendEmailKey($email));
        }

        if (RateLimiter::tooManyAttempts($this->sendIpKey($ip), (int) config('otp.max_sends_per_ip'))) {
            $this->throwThrottle('email', $this->sendIpKey($ip));
        }
    }

    private function hitSendLimiters(string $email, string $ip): void
    {
        RateLimiter::hit($this->sendEmailKey($email), 3600);
        RateLimiter::hit($this->sendIpKey($ip), 3600);
    }

    private function ensureVerifyNotRateLimited(string $email, string $ip): void
    {
        if (RateLimiter::tooManyAttempts($this->verifyEmailKey($email), (int) config('otp.max_verifies_per_email'))) {
            $this->throwThrottle('otp', $this->verifyEmailKey($email));
        }

        if (RateLimiter::tooManyAttempts($this->verifyIpKey($ip), (int) config('otp.max_verifies_per_ip'))) {
            $this->throwThrottle('otp', $this->verifyIpKey($ip));
        }
    }

    private function hitVerifyLimiters(string $email, string $ip): void
    {
        $decaySeconds = max(60, (int) config('otp.verify_decay_seconds', 300));

        RateLimiter::hit($this->verifyEmailKey($email), $decaySeconds);
        RateLimiter::hit($this->verifyIpKey($ip), $decaySeconds);
    }

    private function clearVerifyLimiters(string $email, string $ip): void
    {
        RateLimiter::clear($this->verifyEmailKey($email));
        RateLimiter::clear($this->verifyIpKey($ip));
    }

    private function throwThrottle(string $field, string $key): never
    {
        $seconds = RateLimiter::availableIn($key);

        if ($field === 'otp') {
            $minutes = max(1, (int) ceil($seconds / 60));

            throw ValidationException::withMessages([
                $field => "Too many verification attempts. Request a new code or try again in about {$minutes} ".($minutes === 1 ? 'minute.' : 'minutes.'),
            ]);
        }

        throw ValidationException::withMessages([
            $field => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    private function sendEmailKey(string $email): string
    {
        return 'otp-send-email:'.$email;
    }

    private function sendIpKey(string $ip): string
    {
        return 'otp-send-ip:'.$ip;
    }

    private function verifyEmailKey(string $email): string
    {
        // v2 intentionally leaves behind the former one-hour limiter keys.
        return 'otp-verify-v2-email:'.$email;
    }

    private function verifyIpKey(string $ip): string
    {
        return 'otp-verify-v2-ip:'.$ip;
    }
}
