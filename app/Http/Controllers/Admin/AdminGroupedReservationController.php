<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ReservationGroupStatus;
use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAdminWalkInGroupRequest;
use App\Http\Requests\GroupedBooking\EvaluateGroupAvailabilityRequest;
use App\Models\Facility;
use App\Models\ReservationGroup;
use App\Models\SwimmingSession;
use App\Models\User;
use App\Services\GroupedAvailabilityService;
use App\Services\GroupedBookingService;
use App\Support\AdminCatalogCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminGroupedReservationController extends Controller
{
    public function __construct(
        private readonly GroupedAvailabilityService $availabilityService,
        private readonly GroupedBookingService $bookingService,
    ) {}

    /**
     * Show front desk unified booking workspace for staff walk-ins.
     */
    public function create(): View
    {
        $bookableFacilities = AdminCatalogCache::bookableFacilities();
        $swimmingSessions = SwimmingSession::where('is_active', true)->orderBy('sort_order')->get();

        return view('admin.reservations.walk-in-workspace', compact('bookableFacilities', 'swimmingSessions'));
    }

    /**
     * Staff check availability and calculate estimates.
     */
    public function checkAvailability(EvaluateGroupAvailabilityRequest $request): JsonResponse
    {
        $lines = $request->validated('lines');
        $result = $this->availabilityService->evaluateGroup($lines, customer: false);

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    /**
     * Store walk-in grouped reservation.
     */
    public function storeWalkIn(StoreAdminWalkInGroupRequest $request): JsonResponse|RedirectResponse
    {
        $lines = $request->validated('lines');

        $guestData = [
            'user_id' => $request->validated('existing_user_id'),
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
            'admin_notes' => $request->validated('admin_notes'),
            'special_requests' => $request->validated('special_requests'),
            'creation_action' => $request->validated('creation_action', 'approve'),
            'override_reason' => $request->validated('override_reason'),
            'pets_included' => $request->boolean('pets_included'),
            'policies_accepted' => true,
        ];

        try {
            $group = $this->bookingService->createGroup(
                actor: $request->user(),
                lines: $lines,
                guestData: $guestData,
                source: ReservationSource::WalkIn,
                options: $options
            );

            if ($request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'group_code' => $group->group_code,
                    'redirect_url' => route('admin.reservation-groups.show', $group),
                    'message' => 'Walk-in group booking saved.',
                ]);
            }

            return redirect()->route('admin.reservation-groups.show', $group)
                ->with('success', 'Walk-in group booking saved.');
        } catch (\Throwable $e) {
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 422);
            }

            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }
    }

    /**
     * View grouped reservation details and operational status per line.
     */
    public function attendance(Request $request, ReservationGroup $group, string $operation, \App\Services\ReservationService $service): RedirectResponse
    {
        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($request, $group, $operation, $service) {
                $lines = $group->reservations()->with('facility')->orderBy('id')->get();
                $service->lockFacilityGraph($lines->map->facility->all());
                $group->newQuery()->lockForUpdate()->findOrFail($group->id);
                foreach ($lines as $line) {
                    if ($operation === 'check-in') {
                        if (! $line->check_in->isSameDay(\App\Support\ResortTime::databaseNow()) || $group->pendingPaymentsTotal() > 0) {
                            throw new \App\Exceptions\Reservation\InvalidReservationTransitionException('Review the arrival date and pending payments before group check-in.');
                        }
                        $service->checkIn($line, $request->user());
                    } else {
                        $service->checkOut($line, $request->user());
                    }
                }
            }, 5);
        } catch (\App\Exceptions\Reservation\InvalidReservationTransitionException $e) {
            return back()->with('error', $e->getMessage());
        }
        return back()->with('success', $operation === 'check-in' ? 'Group checked in.' : 'Group checked out.');
    }

    public function show(ReservationGroup $group): View
    {
        $group->load([
            'reservations.facility.facilityType',
            'reservations.swimmingSession',
            'reservations.payments.paymentDestination',
            'directPayments.paymentDestination',
            'user',
            'approver',
        ]);

        return view('admin.reservations.group-show', compact('group'));
    }

    /**
     * Printable summary document for the entire booking group.
     */
    public function printSummary(ReservationGroup $group): View
    {
        $group->load([
            'reservations.facility.facilityType',
            'reservations.swimmingSession',
            'reservations.payments.paymentDestination',
            'directPayments.paymentDestination',
            'user',
            'approver',
        ]);

        return view('admin.reservations.group-print', compact('group'));
    }

    /**
     * Approve entire grouped reservation.
     */
    public function approve(Request $request, ReservationGroup $group): RedirectResponse
    {
        try {
            $this->bookingService->approveGroup($group, $request->user());
            return redirect()->back()->with('success', 'Group booking approved.');
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    /**
     * Provide quote for grouped reservation.
     */
    public function provideQuote(Request $request, ReservationGroup $group): RedirectResponse
    {
        $validated = $request->validate([
            'quoted_total' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $this->bookingService->provideQuote(
                $group,
                $request->user(),
                (float) $validated['quoted_total'],
                $validated['notes'] ?? null
            );

            return redirect()->back()->with('success', 'Group quote saved.');
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    /**
     * Reject entire grouped reservation.
     */
    public function reject(Request $request, ReservationGroup $group): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'refund_status' => ['nullable', 'string', 'in:processed,waived,pending'],
            'refund_amount' => ['nullable', 'numeric', 'min:0'],
            'refund_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $this->bookingService->rejectGroup(
                $group,
                $request->user(),
                $validated['reason'],
                $validated['refund_status'] ?? null,
                isset($validated['refund_amount']) ? (float) $validated['refund_amount'] : null,
                $validated['refund_notes'] ?? null
            );

            return redirect()->back()->with('success', 'Group reservation request rejected.');
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function recordRefund(Request $request, ReservationGroup $group): RedirectResponse
    {
        abort_unless($request->user()->hasRole(\App\Enums\Role::Administrator->value), 403);
        $data = $request->validate([
            'refund_amount' => ['required', 'numeric', 'min:0.01', 'decimal:0,2'],
            'refund_reference' => ['required', 'string', 'max:255'],
        ]);
        try {
            $this->bookingService->recordFullRefund($group, $request->user(), (float) $data['refund_amount'], $data['refund_reference']);
            return back()->with('success', 'Full refund recorded.');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Lookup guest by email or phone for walk-ins.
     */
    public function lookupGuest(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('query', ''));
        if (strlen($q) < 2) {
            return response()->json(['guests' => []]);
        }

        $guests = User::query()
            ->where('email', 'like', "%{$q}%")
            ->orWhere('phone', 'like', "%{$q}%")
            ->orWhere('name', 'like', "%{$q}%")
            ->limit(10)
            ->get(['id', 'name', 'email', 'phone', 'address', 'nationality', 'company', 'valid_id_type', 'gender']);

        return response()->json(['guests' => $guests]);
    }
}
