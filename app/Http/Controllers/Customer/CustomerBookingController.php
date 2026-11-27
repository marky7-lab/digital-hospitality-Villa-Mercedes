<?php

namespace App\Http\Controllers\Customer;

use App\Enums\RateType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\SendBookingMagicLinkRequest;
use App\Models\Facility;
use App\Rules\PersonName;
use App\Models\SwimmingSession;
use App\Services\BookingMagicLinkService;
use App\Services\BookingResumeLink;
use App\Services\CottageBookingService;
use App\Services\Customer\CustomerReservationService;
use App\Services\PricingService;
use App\Support\BookingDraft;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CustomerBookingController extends Controller
{
    public function __construct(
        private readonly CustomerReservationService $reservationService,
        private readonly BookingMagicLinkService $magicLinkService,
        private readonly CottageBookingService $cottageBookingService,
        private readonly BookingResumeLink $resumeLinks,
    ) {}

    public function availability(Request $request, Facility $facility): View|RedirectResponse
    {
        abort_unless($facility->is_active, 404);
        if ($facility->isCottage()) {
            return redirect()->to(route('resort.show').'#cottage-reservations');
        }
        if (! $facility->isOnlineBookable()) {
            return redirect()->route('facilities.show', $facility);
        }

        $prefill = $this->resolvePrefill($request, $facility);

        return view('pages.service-availability', compact('facility', 'prefill'));
    }

    public function create(Request $request, Facility $facility): View|RedirectResponse
    {
        abort_if(! $facility->is_active, 404);

        if (! $facility->isOnlineBookable()) {
            return redirect()
                ->route('facilities.show', $facility)
                ->with('error', 'This package cannot be reserved online. Please call the resort to arrange it.');
        }

        $facility->load(['facilityType', 'galleryImages', 'rates']);

        if ($facility->isCottage()) {
            $sessions = SwimmingSession::query()->where('is_active', true)->orderBy('sort_order')->get();
            $selectedSession = $sessions->firstWhere('slug', $request->query('session')) ?? $sessions->first();
            $visitDate = $request->query('date', now()->addDay()->toDateString());

            return view('customer.booking.cottage', compact('facility', 'sessions', 'selectedSession', 'visitDate'));
        }

        $earlyCheckInRate = $facility->rates
            ->where('is_active', true)
            ->first(fn ($rate) => $rate->type === RateType::AdditionalCharge && $rate->name === PricingService::EARLY_CHECK_IN_RATE_NAME);

        $lateCheckOutRate = $facility->rates
            ->where('is_active', true)
            ->first(fn ($rate) => $rate->type === RateType::AdditionalCharge && $rate->name === PricingService::LATE_CHECK_OUT_RATE_NAME);

        $baseRate = $facility->rates
            ->where('is_active', true)
            ->first(fn ($rate) => $rate->type === RateType::Base);

        $extraPersonRate = $facility->rates
            ->where('is_active', true)
            ->first(fn ($rate) => $rate->type === RateType::ExtraPerson);

        $prefill = $this->resolvePrefill($request, $facility);

        // "Start over" is a link, so it has to leave the guest somewhere they can
        // reload: drop whatever this booking left behind and redirect to the clean
        // URL. It only ever touches the guest's own session.
        if ($request->query('fresh') === '1') {
            BookingDraft::forget($facility);

            return redirect()->route('customer.booking.create', $facility);
        }

        $resume = $this->resumeDraft($request, $facility, $prefill);

        if ($resume !== null) {
            $prefill = BookingDraft::mergeInto($prefill, $resume);
        }

        return view('customer.booking.create', compact(
            'facility',
            'earlyCheckInRate',
            'lateCheckOutRate',
            'baseRate',
            'extraPersonRate',
            'prefill',
            'resume'
        ));
    }

    /**
     * The guest's unfinished booking for this space, if there is one and this
     * visit is a return rather than a fresh intent.
     *
     * A link that carries its own dates — the availability bar's dates, or the
     * continuation hint that follows them — is a guest starting this booking now,
     * so it wins over a draft they left earlier and the draft is left alone.
     *
     * @param  array<string, mixed>  $prefill
     * @return array<string, mixed>|null
     */
    private function resumeDraft(Request $request, Facility $facility, array $prefill): ?array
    {
        $carriesItsOwnDates = filled($prefill['check_in'] ?? null)
            || $request->query('availability_checked') === '1';

        return $carriesItsOwnDates ? null : BookingDraft::load($facility);
    }

    /**
     * Turn the dates picked on the public availability bar into wizard values.
     *
     * The bar only collects calendar dates, so the resort's published day-use and
     * overnight hours supply the times. Nothing is submitted for the guest.
     *
     * @return array{check_in: ?string, check_out: ?string, guest_count: ?int, preset: ?string, pax_tier: ?int, availability_checked: bool}
     */
    private function resolvePrefill(Request $request, Facility $facility): array
    {
        $paxTier = filter_var($request->query('pax_tier'), FILTER_VALIDATE_INT);
        $paxTier = $paxTier !== false && $paxTier > 0 ? $paxTier : null;
        $empty = [
            'check_in' => null,
            'check_out' => null,
            'guest_count' => null,
            'preset' => null,
            'pax_tier' => $paxTier,
            // This is only a UX continuation hint. The authenticated wizard still
            // calls the calculation endpoint before allowing the guest to proceed.
            'availability_checked' => $request->query('availability_checked') === '1',
        ];

        $guestCount = filter_var($request->query('guests'), FILTER_VALIDATE_INT);
        $maxGuests = $paxTier !== null
            ? min($facility->effectiveMaxCapacity() ?? $paxTier, $paxTier)
            : $facility->effectiveMaxCapacity();

        if ($guestCount !== false && $guestCount > 0) {
            $empty['guest_count'] = $maxGuests ? min($guestCount, $maxGuests) : $guestCount;
        }

        if ($request->filled('arrival') && $request->filled('departure')) {
            $selection = $request->validate([
                'arrival' => ['required', 'date_format:Y-m-d H:i'],
                'departure' => ['required', 'date_format:Y-m-d H:i', 'after:arrival'],
            ]);

            return [...$empty, 'check_in' => $selection['arrival'], 'check_out' => $selection['departure'], 'early_check_in_hours' => min(2, max(0, (int) $request->query('early_check_in_hours', 0)))];
        }

        $checkInDate = $this->parseDate($request->query('check_in'));

        if (! $checkInDate) {
            return $empty;
        }

        $checkOutDate = $this->parseDate($request->query('check_out')) ?? $checkInDate->copy()->addDay();

        if ($checkOutDate->lessThan($checkInDate)) {
            $checkOutDate = $checkInDate->copy()->addDay();
        }

        [$arrival, $departure] = app(\App\Services\ReservationSchedule::class)->windowForDates($facility, $checkInDate, $checkOutDate);

        return [
            ...$empty,
            'check_in' => $arrival->format('Y-m-d H:i'),
            'check_out' => $departure->format('Y-m-d H:i'),
            'preset' => $facility->stay_category?->value,
            'early_check_in_hours' => min(2, max(0, (int) $request->query('early_check_in_hours', 0))),
        ];
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', trim($value))->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    public function calculate(Request $request, Facility $facility): JsonResponse
    {
        abort_if(! $facility->is_active, 404);

        if ($facility->isCottage()) {
            $validated = $request->validate([
                'visit_date' => ['required', 'date_format:Y-m-d'],
                'swimming_session_id' => ['required', 'integer'],
                'guestCount' => ['required', 'integer', 'min:1'],
            ]);

            try {
                $resolved = $this->cottageBookingService->resolve($facility, $validated['swimming_session_id'], $validated['visit_date']);
                $availability = $this->reservationService->checkAvailability(
                    $facility, $resolved['check_in'], $resolved['check_out'], null, $validated['guestCount']
                );
                if (! $availability->isAvailable) {
                    return response()->json(['success' => false, 'message' => $availability->reason, 'availability' => $availability->toArray()], 422);
                }

                $price = $this->cottageBookingService->price(
                    $facility, $resolved['session']->id, $resolved['check_in'], $resolved['check_out']
                );

                return response()->json(['success' => true, 'availability' => $availability->toArray(), 'data' => [
                    'base_amount' => $price->baseAmount,
                    'early_check_in_charge' => 0,
                    'late_check_out_charge' => 0,
                    'extra_person_charge' => 0,
                    'total_amount' => $price->totalAmount,
                    'check_in' => $resolved['check_in']->toDateTimeString(),
                    'check_out' => $resolved['check_out']->toDateTimeString(),
                ]]);
            } catch (\Throwable $e) {
                return response()->json(['success' => false, 'message' => $e instanceof ValidationException
                    ? collect($e->errors())->flatten()->first()
                    : 'Unable to check this cottage. Please try another session.'], 422);
            }
        }

        $validated = $request->validate([
            'check_in' => ['required', 'date'],
            'check_out' => ['required', 'date', 'after:check_in'],
            'guestCount' => ['required', 'integer', 'min:1'],
            'children_count' => ['nullable', 'integer', 'min:0', 'lte:guestCount'],
            'pets_included' => ['nullable', 'boolean'],
            'pax_tier' => ['nullable', 'integer', 'min:1'],
            'early_check_in_hours' => ['nullable', 'integer', 'min:0', 'max:2'],
            'late_check_out_hours' => ['nullable', 'integer', 'min:0', 'max:0'],
            // The wizard reports which step it is on, and hands over the guest-sheet
            // work it has collected, so a guest who leaves on step 2 or 3 can be
            // given it back. All three are optional: the public availability bar
            // prices a selection without any of them.
            'step' => ['nullable', 'integer', 'min:1', 'max:3'],
            'other_guest_names' => ['nullable', 'array'],
            'other_guest_names.*.name' => ['nullable', 'string', 'max:255', new PersonName],
            'other_guest_names.*.valid_id' => ['nullable', 'string', 'max:64'],
            'special_requests' => ['nullable', 'string', 'max:1000'],
        ]);

        app(\App\Services\ReservationSchedule::class)->validate($facility, Carbon::parse($validated['check_in']), Carbon::parse($validated['check_out']), (int) ($validated['early_check_in_hours'] ?? 0), (int) ($validated['late_check_out_hours'] ?? 0));

        // Only an account leaves a draft: a browsing guest has no booking to come
        // back to, and no reason for their party's names to sit in a session.
        if ($request->user() !== null) {
            BookingDraft::remember($facility, $validated, (int) ($validated['step'] ?? 2));

            // A draft lives in one browser. Reaching the confirm step also arms the
            // emailed way back to it, which `arm()` schedules only for a guest who
            // actually stops here (and drops it again the moment they go back to
            // change something).
            $this->resumeLinks->arm($request->user(), $facility);
        }

        try {
            $checkIn = Carbon::parse($validated['check_in']);
            $checkOut = Carbon::parse($validated['check_out']);
            $guestCount = $validated['guestCount'];
            $paxTier = isset($validated['pax_tier']) ? (int) $validated['pax_tier'] : null;
            $earlyCheckInHours = (int) ($validated['early_check_in_hours'] ?? 0);
            $lateCheckOutHours = (int) ($validated['late_check_out_hours'] ?? 0);
            $maxCapacity = $facility->effectiveMaxCapacity();
            if ($paxTier !== null) {
                $maxCapacity = $maxCapacity === null ? $paxTier : min($maxCapacity, $paxTier);
            }

            // 1. Check Availability
            $availability = $this->reservationService->checkAvailability(
                $facility,
                $checkIn,
                $checkOut,
                null,
                $guestCount,
                $earlyCheckInHours,
                $lateCheckOutHours,
                $paxTier
            );

            // Check capacity strictly here (though AvailabilityService does it, if it doesn't we enforce it)
            if ($maxCapacity !== null && $guestCount > $maxCapacity) {
                return response()->json([
                    'success' => false,
                    'message' => 'Guest count exceeds facility maximum capacity.',
                ], 422);
            }

            if (! $availability->isAvailable) {
                return response()->json([
                    'success' => false,
                    'message' => $availability->reason ?: 'Facility is unavailable for the selected dates.',
                    'availability' => $availability->toArray(),
                ], 422);
            }

            // 2. Estimate Price
            $price = $this->reservationService->getEstimatedPrice(
                $facility,
                $checkIn,
                $checkOut,
                $earlyCheckInHours,
                $lateCheckOutHours,
                max(0, $guestCount - (($facility->isRoom() || $facility->isPackage()) ? (int) ($validated['children_count'] ?? 0) : 0)),
                $paxTier
            );
            $petFee = $request->boolean('pets_included') ? 50000 : 0;

            return response()->json([
                'success' => true,
                'availability' => $availability->toArray(),
                'data' => [
                    'base_amount' => $price->baseAmount,
                    'early_check_in_charge' => $price->earlyCheckInCharge,
                    'late_check_out_charge' => $price->lateCheckOutCharge,
                    'hall_extension_charge' => $price->hallExtensionCharge,
                    'extra_person_charge' => $price->extraPersonCharge,
                    'pet_fee' => $petFee,
                    'total_amount' => $price->totalAmount + $petFee,
                ],
            ]);

        } catch (\Exception $e) {
            // Map raw backend exceptions to safe user messages
            return response()->json([
                'success' => false,
                'message' => 'Unable to calculate pricing for this selection. Please try different dates or contact support.',
            ], 422);
        }
    }

    /**
     * Throw an unfinished booking away, from "My reservations".
     *
     * The same action the wizard's own **Start over** performs, offered where the
     * guest can see what they are discarding — a draft that is never shown is a
     * draft nobody can clear.
     */
    public function discardDraft(Request $request, Facility $facility): RedirectResponse
    {
        BookingDraft::forget($facility);

        return back()->with('success', 'We removed your unfinished booking.');
    }

    /**
     * The guest asked for the link now, rather than waiting to be asked.
     *
     * The same link the scheduler would have sent twenty minutes after they
     * walked away, sent when they take it: on the device they will not finish on,
     * or simply while they still have the page in front of them.
     */
    public function sendResumeLink(Request $request, Facility $facility): RedirectResponse
    {
        abort_if(! $facility->is_active || ! $facility->isOnlineBookable(), 404);

        $user = $request->user();

        if (! $this->resumeLinks->sendNow($user, $facility)) {
            return back()->with('error', 'We couldn’t email your booking link. Your details are still here. You can keep going or try sending the link again in a moment.');
        }

        return back()->with('success', 'Sent to '.$user->email.'. Open the email to pick up where you left off.');
    }

    public function sendMagicLink(SendBookingMagicLinkRequest $request, Facility $facility): RedirectResponse
    {
        abort_if(! $facility->is_active || ! $facility->isOnlineBookable(), 404);

        $validated = $request->validated();

        if ((int) $validated['facility_id'] !== $facility->id) {
            abort(404);
        }

        $this->magicLinkService->send(
            $validated['email'],
            $validated,
            (string) $request->ip(),
        );

        return redirect()
            ->route('customer.booking.create', $facility)
            ->withInput($request->except('email'))
            ->with('success', BookingMagicLinkService::SENT_STATUS);
    }

    public function consumeMagicLink(Request $request, string $draft): RedirectResponse
    {
        try {
            $reservation = $this->magicLinkService->consume($draft);
        } catch (ValidationException $e) {
            $message = $e->errors()['email'][0] ?? 'This confirmation link is no longer valid.';
            $facilityId = $e->errors()['facility_id'][0] ?? null;

            if (filled($facilityId)) {
                return redirect()
                    ->route('customer.booking.create', $facilityId)
                    ->with('error', $message);
            }

            return redirect()
                ->route('home')
                ->with('error', $message);
        }

        $finishAccount = $request->user()?->isLightweight() || $request->user()?->needsContactDetails();

        return redirect()
            ->route('customer.reservations.show', $reservation)
            ->with('success', $finishAccount
                ? 'Reservation created. Add your name, mobile number, and a password in Profile so you can sign in next time.'
                : 'Reservation created. Save the check in pass or keep the confirmation email — you will not need to sign in at the front desk.');
    }
}
