<?php

namespace App\Http\Controllers\Customer;

use App\Enums\ReservationGroupStatus;
use App\Enums\ReservationSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\GroupedBooking\EvaluateGroupAvailabilityRequest;
use App\Http\Requests\GroupedBooking\StoreGroupedBookingRequest;
use App\Models\Facility;
use App\Models\Payment;
use App\Models\PaymentDestination;
use App\Models\ReservationGroup;
use App\Models\SwimmingSession;
use App\Services\FileUploadService;
use App\Services\GroupedAvailabilityService;
use App\Services\GroupedBookingService;
use App\Services\ReservationSchedule;
use App\Support\GuestProfilePrefill;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class GroupedBookingController extends Controller
{
    public function __construct(
        private readonly GroupedAvailabilityService $availabilityService,
        private readonly GroupedBookingService $bookingService,
        private readonly FileUploadService $fileUploadService,
    ) {}

    public function showQuoteCheckout(Request $request, ReservationGroup $group): View|RedirectResponse
    {
        abort_unless($group->user_id === $request->user()->id, 403);
        if (! $group->isPending() || ! $group->quoted_at || $group->requires_quote || $group->directPayments()->exists()) {
            return redirect()->route('customer.reservations.index', ['group' => $group->group_code]);
        }

        return view('customer.booking.group-quote-checkout', [
            'group' => $group->load('reservations.facility'),
            'destinations' => PaymentDestination::where('is_active', true)->get(),
        ]);
    }

    public function submitQuoteDeposit(Request $request, ReservationGroup $group): RedirectResponse
    {
        abort_unless($group->user_id === $request->user()->id, 403);
        $data = $request->validate([
            'policy_version' => ['required', Rule::in([$group->policy_version])],
            'policies_accepted' => ['accepted'],
            'payment_percent' => ['nullable', Rule::in([50, 100])],
            'payment_destination_id' => ['required', 'integer', 'exists:payment_destinations,id'],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'payment_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.\App\Support\ResortTime::today()->toDateString()],
            'proof' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ]);

        $destination = PaymentDestination::whereKey($data['payment_destination_id'])->where('is_active', true)->first();
        if (! $destination) {
            throw ValidationException::withMessages(['payment_destination_id' => 'Choose an active receiving account.']);
        }

        $proofPath = null;
        try {
            DB::transaction(function () use ($group, $request, $data, $destination, &$proofPath): void {
                $locked = ReservationGroup::whereKey($group->id)->lockForUpdate()->firstOrFail();
                if (! $locked->isPending() || ! $locked->quoted_at || $locked->requires_quote || $locked->directPayments()->exists()) {
                    throw ValidationException::withMessages(['group' => 'This quote is no longer ready for payment. Review your reservation before trying again.']);
                }

                $upload = $this->fileUploadService->store($request->file('proof'), 'payment_proofs');
                $proofPath = $upload['path'];
                if ($locked->policy_version !== $data['policy_version']) {
                    throw ValidationException::withMessages(['policy_version' => 'This booking retains its saved terms. Contact staff about this quotation.']);
                }
                $locked->update(['policies_accepted_at' => $locked->policies_accepted_at ?? now()]);
                Payment::create([
                    'reservation_group_id' => $locked->id,
                    'amount' => (int) ($data['payment_percent'] ?? 50) === 100 ? $locked->total_amount : $locked->deposit_required,
                    'method' => $destination->method,
                    'reference_number' => isset($data['reference_number']) ? strtoupper(trim($data['reference_number'])) : null,
                    'payment_date' => $data['payment_date'],
                    'payment_destination_id' => $destination->id,
                    'destination_snapshot' => $destination->snapshot(),
                    'proof_path' => $proofPath,
                    'proof_hash' => hash_file('sha256', $request->file('proof')->getRealPath()),
                    'source' => 'customer_deposit',
                    'status' => \App\Enums\PaymentStatus::Pending,
                ]);
            }, 5);
        } catch (\Throwable $e) {
            if ($proofPath) $this->fileUploadService->delete($proofPath);
            throw $e;
        }

        return redirect()->route('customer.reservations.index', ['group' => $group->group_code])
            ->with('success', 'We received your deposit receipt. Our team will check the payment and review your booking request.');
    }

    /**
     * Display the unified booking workspace for online guests.
     */
    public function workspace(Request $request): View
    {
        $bookableFacilities = Facility::listed()
            ->with(['facilityType', 'rates', 'amenities', 'galleryImages'])
            ->orderBy('name')
            ->get();

        $swimmingSessions = SwimmingSession::where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        // Check if there are prefilled lines in the session or query parameters
        $initialLines = [];

        // Support session intent lines (e.g. from public browsing before auth)
        $savedIntent = $request->session()->get('grouped_booking.intent');
        if (is_array($savedIntent) && ! empty($savedIntent['lines'])) {
            $initialLines = $savedIntent['lines'];
        }
        if ($request->filled('facility_id')) {
            $facility = $bookableFacilities->firstWhere('id', (int) $request->query('facility_id'));
            if ($facility && ! collect($initialLines)->contains(fn ($line) => (int) ($line['facility_id'] ?? 0) === $facility->id)) {
                $arrival = $request->query('arrival') ?: $request->query('check_in');
                $departure = $request->query('departure') ?: $request->query('check_out');
                if (! $facility->isCottage() && $arrival && strlen((string) $arrival) === 10) {
                    try {
                        [$start, $end] = app(ReservationSchedule::class)->windowForDates(
                            $facility, Carbon::parse($arrival), $departure ? Carbon::parse($departure) : null
                        );
                        $arrival = $start->format('Y-m-d H:i');
                        $departure = $end->format('Y-m-d H:i');
                    } catch (\Throwable) {
                        $arrival = $departure = null;
                    }
                }
                $initialLines[] = [
                    'facility_id' => $facility->id,
                    'check_in' => $arrival,
                    'check_out' => $departure,
                    'date' => $request->query('date') ?: ($facility->isCottage() ? $request->query('check_in') : null),
                    'session' => $request->query('session'),
                    'guest_count' => (int) ($request->query('guests') ?? 0),
                    'pax_tier' => $request->filled('pax_tier') ? (int) $request->query('pax_tier') : null,
                    'early_check_in_hours' => (int) ($request->query('early_check_in_hours') ?? 0),
                ];
            }
        }

        $user = $request->user();
        $guestProfile = $user ? GuestProfilePrefill::forUser($user) : [];

        $paymentDestinations = PaymentDestination::where('is_active', true)->get();
        $policyVersion = config('resort.policy.version', '2026-09-v2');

        return view('customer.booking.unified-workspace', compact(
            'bookableFacilities',
            'swimmingSessions',
            'initialLines',
            'guestProfile',
            'paymentDestinations',
            'policyVersion'
        ));
    }

    /**
     * Check availability and line-level estimates for proposed service lines.
     * Publicly callable (non-creating).
     */
    public function checkAvailability(EvaluateGroupAvailabilityRequest $request): JsonResponse
    {
        $lines = $request->validated('lines');
        $result = $this->availabilityService->evaluateGroup($lines);

        // Store current lines in session to survive login/register if guest is not yet logged in
        $request->session()->put('grouped_booking.intent', [
            'lines' => $lines,
            'version_token' => $result['version_token'],
            'checked_at' => $result['checked_at'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    /**
     * Save the authenticated grouped booking request.
     */
    public function store(StoreGroupedBookingRequest $request): JsonResponse|RedirectResponse
    {
        $lines = $request->validated('lines');
        $versionToken = $request->validated('version_token');

        // Always perform authoritative final recheck
        if ($versionToken && ! $this->availabilityService->isTokenFresh($versionToken)) {
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'code' => 'availability_expired',
                    'message' => 'Please check availability again before confirming. Your earlier results have expired.',
                ], 422);
            }
            return redirect()->back()->withInput()->with('error', 'Please check availability again before confirming. Your earlier results have expired.');
        }

        $guestData = [
            'guest_name' => $request->validated('guest_name'),
            'guest_email' => $request->validated('guest_email'),
            'guest_phone' => $request->validated('guest_phone'),
            'guest_place_of_origin' => $request->validated('guest_place_of_origin'),
            'guest_nationality' => $request->validated('guest_nationality'),
            'guest_company' => $request->validated('guest_company'),
            'guest_valid_id' => $request->validated('guest_valid_id'),
            'guest_gender' => $request->validated('guest_gender'),
        ];

        $options = [
            'special_requests' => $request->validated('special_requests'),
            'policies_accepted' => true,
            'policy_version' => $request->validated('policy_version') ?: config('resort.policy.version', '2026-09-v2'),
            'version_token' => $versionToken,
            'payment_percent' => (int) ($request->validated('payment_percent') ?? 50),
            'infants_count' => (int) max($request->validated('infants_count') ?? 0, collect($lines)->max('infants_count') ?? 0),
            'children_count' => (int) max($request->validated('children_count') ?? 0, collect($lines)->max('children_count') ?? 0),
            'pets_included' => $request->boolean('pets_included'),
        ];

        $proofPath = null;
        // Store proof privately; release it if group creation rolls back.
        if ($request->hasFile('proof')) {
            $proofFile = $request->file('proof');
            $upload = $this->fileUploadService->store($proofFile, 'payment_proofs');
            $proofPath = $upload['path'];
            $destination = null;
            if ($destId = $request->validated('payment_destination_id')) {
                $destination = PaymentDestination::find($destId);
            }

            $options['payment'] = [
                'proof_path' => $upload['path'],
                'proof_hash' => hash_file('sha256', $proofFile->getRealPath()),
                'payment_destination_id' => $destination?->id,
                'destination_snapshot' => $destination?->snapshot(),
                'method' => $request->validated('payment_method')
                    ? \App\Enums\PaymentMethod::tryFrom($request->validated('payment_method')) ?? \App\Enums\PaymentMethod::BankTransfer
                    : ($destination?->method ?? \App\Enums\PaymentMethod::BankTransfer),
                'reference_number' => $request->validated('reference_number'),
                'payment_date' => $request->validated('payment_date') ?? now()->toDateString(),
            ];
        }

        try {
            $group = $this->bookingService->createGroup(
                actor: $request->user(),
                lines: $lines,
                guestData: $guestData,
                source: ReservationSource::Online,
                options: $options
            );

            // Clean up session intent
            $request->session()->forget('grouped_booking.intent');

            $message = 'Your booking request and receipt were submitted. Staff will verify your payment and review your booking.';

            if ($request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'group_code' => $group->group_code,
                    'redirect_url' => route('customer.reservations.index', ['group' => $group->group_code]),
                    'message' => $message,
                ]);
            }

            return redirect()->route('customer.reservations.index', ['group' => $group->group_code])
                ->with('success', $message);
        } catch (\Throwable $e) {
            if ($proofPath) {
                $this->fileUploadService->delete($proofPath);
            }
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 422);
            }

            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }
    }
}
