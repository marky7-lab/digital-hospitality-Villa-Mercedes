<?php

namespace App\Services;

use App\Jobs\SendBookingResumeLink;
use App\Models\Facility;
use App\Models\Reservation;
use App\Models\User;
use App\Notifications\Reservation\BookingResumeMail;
use App\Support\BookingDraft;
use App\Support\OutboundMail;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The emailed way back to a booking a guest did not finish.
 *
 * A draft lives in one browser's session, so a guest who leaves the confirm step
 * on a phone, on another computer, or after the session expired has no way back
 * to the booking they had put together — the work is there, and unreachable. So
 * the draft is also copied, once, into something a link can carry: a random token
 * in the cache holding the same fields the session draft holds, an email telling
 * the guest where it is, and a route that puts it back into whatever browser they
 * open it in.
 *
 * Three things this is careful about:
 *
 *  - It is not a hold. The link restores a booking *form*; availability is
 *    re-checked when the guest arrives and again when they confirm, and the email
 *    says so.
 *  - It is not sent to someone who is still here. Reaching the confirm step only
 *    schedules the email (see `SendBookingResumeLink`); confirming, changing the
 *    dates, or starting over cancels it, so the only guest who hears from us is
 *    the one who actually walked away.
 *  - It is not a way into an account. The link belongs to the account it was
 *    emailed to, and carries nothing but the fields the wizard prefills.
 */
final class BookingResumeLink
{
    /** The step the wizard calls "Confirm" — the last one before a booking exists. */
    public const CONFIRM_STEP = 3;

    /**
     * How early a worker is allowed to be and still be treated as on time.
     *
     * The delay is a promise about *when* the guest hears from us, and a queue
     * delivery that lands a second before it is due must not be read as "they are
     * still here, stay silent forever".
     */
    private const EARLY_TOLERANCE_SECONDS = 5;

    public const EXPIRED_MESSAGE = 'This link is no longer available. If you already booked, check My Reservations. To start a new booking, choose your space and dates again.';

    public const COMPLETED_MESSAGE = 'You already finished this booking. Here are the details.';

    public const SPACE_GONE_MESSAGE = 'This space is no longer available to book online. You can choose another space.';

    public const STALE_MESSAGE = 'Your original booking dates have passed. Choose new dates to see what’s available.';

    public const RESTORED_MESSAGE = 'Welcome back. We filled in your saved details. Review them and confirm your booking when you’re ready.';

    /**
     * A guest has just had the confirm step priced.
     *
     * Called on every priced step, so one call site decides everything. Below the
     * confirm step there is nothing to finish — and a guest who has gone back to
     * change their dates must not be emailed about the dates they left — so the
     * earlier link is dropped instead. At the confirm step a materially changed
     * draft replaces the link; repeated pricing requests for the same draft keep
     * the existing job instead of growing the queue.
     */
    public function arm(User $user, Facility $facility): void
    {
        $userId = (int) $user->id;
        $facilityId = (int) $facility->id;
        $draft = $this->confirmStepDraft($facility);

        if ($draft === null) {
            $this->clear($userId, $facilityId);

            return;
        }

        $pendingToken = Cache::get(BookingDraft::pendingLinkKey($userId, $facilityId));
        $pendingPayload = is_string($pendingToken) ? $this->resolve($pendingToken) : null;

        if (is_array($pendingPayload)
            && (int) ($pendingPayload['user_id'] ?? 0) === $userId
            && (int) ($pendingPayload['facility_id'] ?? 0) === $facilityId
            && $this->sameDraft($pendingPayload['draft'] ?? null, $draft)) {
            return;
        }

        if (is_string($pendingToken) && $pendingToken !== '') {
            Cache::forget(BookingDraft::linkPayloadKey($pendingToken));
        }

        $token = $this->store($userId, $facilityId, $draft);

        Cache::put(
            BookingDraft::pendingLinkKey($userId, $facilityId),
            $token,
            now()->addHours($this->expireHours())
        );

        // A queue write is a secondary effect of asking for a price: a mailer or
        // queue outage must not cost the guest their quote.
        OutboundMail::safely(
            'Could not schedule a booking resume link.',
            fn () => SendBookingResumeLink::dispatch($userId, $facilityId, $token)->delay(now()->addMinutes($this->delayMinutes())),
            ['user_id' => $userId, 'facility_id' => $facilityId],
        );
    }

    /**
     * The guest asked for the link now, from the confirm step.
     *
     * Whatever was scheduled is dropped: they have the link in hand, and a second
     * copy arriving twenty minutes later would only be noise.
     */
    public function sendNow(User $user, Facility $facility): bool
    {
        $userId = (int) $user->id;
        $facilityId = (int) $facility->id;
        $draft = $this->confirmStepDraft($facility);

        if ($draft === null) {
            return false;
        }

        $token = $this->store($userId, $facilityId, $draft);

        Cache::forget(BookingDraft::pendingLinkKey($userId, $facilityId));

        return $this->notify($userId, $facilityId, $token, $draft);
    }

    /**
     * The scheduled email, asking whether it is still worth sending.
     *
     * @return bool whether the guest was emailed
     */
    public function deliverPending(int $userId, int $facilityId, string $token): bool
    {
        if (! OutboundMail::isConfigured()) {
            return false;
        }

        // A newer arrival at the confirm step replaces the pending link, and a
        // booking finished in the meantime clears it. Either way this job has been
        // overtaken, and mailing about it would be mailing about a booking that is
        // not the one waiting.
        if (Cache::get(BookingDraft::pendingLinkKey($userId, $facilityId)) !== $token) {
            return false;
        }

        $payload = $this->resolve($token);
        $draft = $payload['draft'] ?? null;
        $armedAt = $payload === null ? null : $this->moment($payload['armed_at'] ?? null);

        if (! is_array($draft) || ! BookingDraft::isResumable($draft) || $armedAt === null) {
            $this->clear($userId, $facilityId);

            return false;
        }

        // The delay is checked here as well as asked of the queue, because a queue
        // that ignores delays (the `sync` driver) would otherwise mail the guest
        // while they are still looking at the confirm step. Not due yet is not an
        // error: the link stays armed for whoever asks next.
        if (now()->addSeconds(self::EARLY_TOLERANCE_SECONDS)->lessThan($armedAt->copy()->addMinutes($this->delayMinutes()))) {
            return false;
        }

        if ($this->completedReservationFor($userId, $facilityId, $draft) instanceof Reservation) {
            $this->clear($userId, $facilityId);

            return false;
        }

        return $this->notify($userId, $facilityId, $token, $draft);
    }

    /**
     * The draft this token hands back, or null when the link is dead — expired,
     * already used, or belonging to a booking that has been forgotten.
     *
     * @return array<string, mixed>|null
     */
    public function resolve(string $token): ?array
    {
        $payload = Cache::get(BookingDraft::linkPayloadKey($token));

        return is_array($payload) ? $payload : null;
    }

    /**
     * What this account has a way back to, keyed by space.
     *
     * The drafts behind these tokens outlive the browser that made them, which is
     * the point of the link — but it also means they are the only unfinished
     * bookings a guest can still see after their session has ended. Entries whose
     * payload is gone (expired, used, or cleared) are dropped from the list as it
     * is read, so the account's own index cannot outlive the links it names.
     *
     * @return array<int, array{draft: array<string, mixed>, token: string, armed_at: string|null}>
     */
    public function armedFor(int $userId): array
    {
        $links = $this->accountLinks($userId);
        $armed = [];

        foreach ($links as $facilityId => $entry) {
            $token = is_array($entry) ? ($entry['token'] ?? null) : null;
            $payload = is_string($token) && $token !== '' ? $this->resolve($token) : null;
            $draft = $payload['draft'] ?? null;

            if (! is_array($draft) || (int) ($payload['facility_id'] ?? 0) !== (int) $facilityId) {
                continue;
            }

            $armed[(int) $facilityId] = [
                'draft' => $draft,
                'token' => (string) $token,
                'armed_at' => is_string($payload['armed_at'] ?? null) ? $payload['armed_at'] : null,
            ];
        }

        // Reading the list prunes it as well: an entry whose link is gone is not a
        // booking this account can get back to any more.
        if (count($armed) !== count($links)) {
            $this->writeAccountLinks($userId, array_map(
                fn (array $entry): array => ['token' => $entry['token'], 'armed_at' => $entry['armed_at']],
                $armed,
            ));
        }

        return $armed;
    }

    /**
     * The booking this draft has already become, when the guest finished it
     * somewhere else between the email and the click.
     *
     * A draft is only ever handed back to be confirmed, so this is the one case
     * where the honest answer is not "here it is again" but "here is what you
     * made" — and it is also what keeps a stale link from starting a second
     * booking for the same stay.
     *
     * @param  array<string, mixed>  $draft
     */
    public function completedReservationFor(int $userId, int $facilityId, array $draft): ?Reservation
    {
        $savedAt = $draft['saved_at'] ?? null;

        if (! is_string($savedAt) || trim($savedAt) === '') {
            return null;
        }

        try {
            $since = Carbon::parse($savedAt);
        } catch (\Throwable) {
            return null;
        }

        return Reservation::query()
            ->where('user_id', $userId)
            ->where('facility_id', $facilityId)
            ->where('created_at', '>=', $since)
            ->latest('id')
            ->first();
    }

    public function clear(int $userId, int $facilityId): void
    {
        BookingDraft::forgetResumeLink($userId, $facilityId);

        $links = $this->accountLinks($userId);

        if (array_key_exists($facilityId, $links)) {
            unset($links[$facilityId]);
            $this->writeAccountLinks($userId, $links);
        }
    }

    private function moment(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse(trim($value));
        } catch (\Throwable) {
            return null;
        }
    }

    public function delayMinutes(): int
    {
        return max(1, (int) config('resort.booking.resume_delay_minutes', 20));
    }

    public function expireHours(): int
    {
        return max(1, (int) config('resort.booking.resume_expire_hours', 48));
    }

    /**
     * The guest's draft, but only when it is the one worth coming back to.
     *
     * @return array<string, mixed>|null
     */
    private function confirmStepDraft(Facility $facility): ?array
    {
        if (! OutboundMail::isConfigured()) {
            return null;
        }

        $draft = BookingDraft::load($facility);

        return is_array($draft) && (int) ($draft['step'] ?? 1) >= self::CONFIRM_STEP ? $draft : null;
    }

    /**
     * Keep this draft where a link can reach it, and mint the token that reaches
     * it. The token is the whole credential: unguessable, expiring, and useless
     * to anyone but the account it names.
     *
     * @param  array<string, mixed>  $draft
     */
    private function store(int $userId, int $facilityId, array $draft): string
    {
        $token = Str::random(48);
        $armedAt = now()->toIso8601String();

        Cache::put(BookingDraft::linkPayloadKey($token), [
            'user_id' => $userId,
            'facility_id' => $facilityId,
            'armed_at' => $armedAt,
            'draft' => $draft,
        ], now()->addHours($this->expireHours()));

        // The account keeps its own list of what it can get back to, because a
        // cache cannot be asked for its keys.
        $links = $this->accountLinks($userId);
        $links[$facilityId] = ['token' => $token, 'armed_at' => $armedAt];

        $this->writeAccountLinks($userId, $links);

        return $token;
    }

    /** @param array<string, mixed> $draft */
    private function sameDraft(mixed $stored, array $draft): bool
    {
        if (! is_array($stored)) {
            return false;
        }

        unset($stored['saved_at'], $draft['saved_at']);

        return $stored === $draft;
    }

    /**
     * @return array<int, array{token?: string|null, armed_at?: string|null}>
     */
    private function accountLinks(int $userId): array
    {
        $links = Cache::get(BookingDraft::accountLinksKey($userId));

        return is_array($links) ? $links : [];
    }

    /**
     * @param  array<int, array{token?: string|null, armed_at?: string|null}>  $links
     */
    private function writeAccountLinks(int $userId, array $links): void
    {
        if ($links === []) {
            Cache::forget(BookingDraft::accountLinksKey($userId));

            return;
        }

        Cache::put(BookingDraft::accountLinksKey($userId), $links, now()->addHours($this->expireHours()));
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return bool whether the guest was emailed
     */
    private function notify(int $userId, int $facilityId, string $token, array $draft): bool
    {
        $user = User::query()->find($userId);
        $facility = Facility::query()->find($facilityId);

        if (! $user instanceof User || ! $facility instanceof Facility) {
            return false;
        }

        $sent = false;

        OutboundMail::safely('Could not send a booking resume link.', function () use ($user, $facility, $token, $draft, &$sent): void {
            $user->notify(new BookingResumeMail(
                facilityName: $facility->name,
                guestName: $user->name,
                url: route('customer.booking.resume', ['token' => $token]),
                checkIn: Carbon::parse((string) $draft['check_in'])->format('D, M j, Y g:i A'),
                checkOut: Carbon::parse((string) $draft['check_out'])->format('D, M j, Y g:i A'),
                guestCount: (int) ($draft['guest_count'] ?? 1),
                expireHours: $this->expireHours(),
            ));

            $sent = true;
        }, ['user_id' => $userId, 'facility_id' => $facilityId]);

        if (! $sent) {
            // A token whose email never went out is not worth keeping: the link
            // cannot be sitting in an inbox nobody sent to.
            $this->clear($userId, $facilityId);
        }

        return $sent;
    }
}
