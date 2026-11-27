<?php

namespace App\Services;

use App\Enums\Role;
use App\Exceptions\Availability\InvalidAvailabilityPeriodException;
use App\Exceptions\Pricing\InvalidPricingPeriodException;
use App\Exceptions\Pricing\NoApplicableRateException;
use App\Exceptions\Pricing\OverlappingSeasonalRatesException;
use App\Exceptions\Reservation\ReservationConflictException;
use App\Models\Facility;
use App\Models\Reservation;
use App\Models\User;
use App\Notifications\Auth\BookingMagicLinkNotification;
use App\Services\Customer\CustomerReservationService;
use App\Support\GuestInformation;
use App\Support\OutboundMail;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class BookingMagicLinkService
{
    public const SENT_STATUS = 'Check your inbox for a link to confirm your booking. If you don’t see it, check your spam folder.';

    public function __construct(
        private readonly CustomerReservationService $reservationService,
        private readonly AuthService $authService,
    ) {}

    /**
     * @param  array{facility_id: int, check_in: string, check_out: string, guestCount: int, early_check_in_hours?: int, late_check_out_hours?: int, guest_name?: string, guest_email?: string, guest_phone?: string, guest_place_of_origin?: string, guest_nationality?: string, guest_company?: string|null, guest_valid_id?: string, guest_gender?: string, other_guest_names?: array<int, string>}  $payload
     */
    public function send(string $email, array $payload, string $ip): void
    {
        $email = $this->normalizeEmail($email);

        $this->ensureSendNotRateLimited($email, $ip);
        $this->hitSendLimiters($email, $ip);

        if (! OutboundMail::isConfigured()) {
            throw ValidationException::withMessages([
                'email' => 'Email sending is not configured yet. Add SMTP credentials in the .env file.',
            ]);
        }

        $draftId = (string) Str::uuid();

        Cache::put($this->cacheKey($draftId), [
            'email' => $email,
            'facility_id' => (int) $payload['facility_id'],
            'swimming_session_id' => isset($payload['swimming_session_id']) ? (int) $payload['swimming_session_id'] : null,
            'visit_date' => $payload['visit_date'] ?? null,
            'check_in' => $payload['check_in'],
            'check_out' => $payload['check_out'],
            'guest_count' => (int) $payload['guestCount'],
            'early_check_in_hours' => (int) ($payload['early_check_in_hours'] ?? 0),
            'late_check_out_hours' => (int) ($payload['late_check_out_hours'] ?? 0),
            ...GuestInformation::fromValidated($payload),
        ], now()->addMinutes($this->expireMinutes()));

        $url = URL::temporarySignedRoute(
            'customer.booking.magic-link.consume',
            now()->addMinutes($this->expireMinutes()),
            ['draft' => $draftId]
        );

        try {
            Notification::route('mail', $email)->notify(new BookingMagicLinkNotification(
                $url,
                $this->expireMinutes(),
            ));
        } catch (Throwable $e) {
            report($e);
            Cache::forget($this->cacheKey($draftId));

            throw ValidationException::withMessages([
                'email' => 'We could not send the confirmation email. Please try again or sign in.',
            ]);
        }
    }

    public function consume(string $draftId): Reservation
    {
        $draft = Cache::get($this->cacheKey($draftId));

        if (! is_array($draft) || ! isset($draft['email'], $draft['facility_id'])) {
            throw ValidationException::withMessages([
                'email' => 'This confirmation link has expired or is no longer valid. Please check availability again.',
            ]);
        }

        $email = $this->normalizeEmail((string) $draft['email']);
        $existing = User::query()->where('email', $email)->first();

        if ($existing?->hasRole(Role::Administrator->value)) {
            throw ValidationException::withMessages([
                'email' => 'Staff accounts confirm reservations from the admin desk. Please sign in there.',
            ]);
        }

        $user = $existing ?? $this->createLightweightCustomer($email, $draft);
        $facility = Facility::query()->findOrFail((int) $draft['facility_id']);

        try {
            $reservation = $this->reservationService->createReservation(
                $user,
                $facility,
                Carbon::parse((string) $draft['check_in']),
                Carbon::parse((string) $draft['check_out']),
                (int) $draft['guest_count'],
                [
                    'early_check_in_hours' => (int) ($draft['early_check_in_hours'] ?? 0),
                    'late_check_out_hours' => (int) ($draft['late_check_out_hours'] ?? 0),
                    'swimming_session_id' => $draft['swimming_session_id'] ?? null,
                    ...GuestInformation::fromValidated($draft),
                ]
            );
        } catch (
            ReservationConflictException|
            InvalidAvailabilityPeriodException|
            InvalidPricingPeriodException|
            NoApplicableRateException|
            OverlappingSeasonalRatesException $e
        ) {
            throw ValidationException::withMessages([
                'email' => $e->getMessage(),
                'facility_id' => [(string) $facility->id],
            ]);
        }

        Cache::forget($this->cacheKey($draftId));

        Auth::login($user);
        request()->session()->regenerate();

        return $reservation;
    }

    public function normalizeEmail(string $email): string
    {
        return Str::lower(trim($email));
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function createLightweightCustomer(string $email, array $draft = []): User
    {
        $localPart = Str::before($email, '@') ?: 'Guest';
        $name = filled($draft['guest_name'] ?? null) ? (string) $draft['guest_name'] : $localPart;

        $user = $this->authService->register([
            'name' => $name,
            'email' => $email,
            'phone' => $draft['guest_phone'] ?? null,
            'password' => Str::password(32),
            'profile_completed_at' => null,
        ]);

        $user->forceFill([
            'profile_completed_at' => null,
            'email_verified_at' => now(),
        ])->save();

        return $user->fresh() ?? $user;
    }

    private function expireMinutes(): int
    {
        return max(10, (int) config('auth.booking_magic_link.expire_minutes', 45));
    }

    private function cacheKey(string $draftId): string
    {
        return 'booking-magic-link:'.$draftId;
    }

    private function ensureSendNotRateLimited(string $email, string $ip): void
    {
        if (RateLimiter::tooManyAttempts($this->sendEmailKey($email), (int) config('auth.booking_magic_link.max_sends_per_email', 5))) {
            $this->throwThrottle($this->sendEmailKey($email));
        }

        if (RateLimiter::tooManyAttempts($this->sendIpKey($ip), (int) config('auth.booking_magic_link.max_sends_per_ip', 10))) {
            $this->throwThrottle($this->sendIpKey($ip));
        }
    }

    private function hitSendLimiters(string $email, string $ip): void
    {
        RateLimiter::hit($this->sendEmailKey($email), 3600);
        RateLimiter::hit($this->sendIpKey($ip), 3600);
    }

    private function throwThrottle(string $key): never
    {
        $seconds = RateLimiter::availableIn($key);

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    private function sendEmailKey(string $email): string
    {
        return 'booking-magic-send-email:'.$email;
    }

    private function sendIpKey(string $ip): string
    {
        return 'booking-magic-send-ip:'.$ip;
    }
}
