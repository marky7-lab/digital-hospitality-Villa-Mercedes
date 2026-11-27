<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PaymentMethod;
use App\Exceptions\Payment\InvalidPaymentAmountException;
use App\Exceptions\Payment\InvalidPaymentTransitionException;
use App\Exceptions\Payment\UnauthorizedPaymentActionException;
use App\Http\Controllers\Controller;
use App\Models\Facility;
use App\Models\FacilityType;
use App\Models\Payment;
use App\Models\Reservation;
use App\Services\FileUploadService;
use App\Services\PaymentService;
use App\Support\LayoutBadges;
use App\Support\LiveFilter;
use App\Support\ResortTime;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminPaymentController extends Controller
{
    public function storeOnSite(Request $request, Reservation $reservation): RedirectResponse
    {
        $data = $this->onSiteDetails($request);
        try {
            $this->service->recordOnSite($reservation, $request->user(), $data);
        } catch (InvalidPaymentAmountException|UnauthorizedPaymentActionException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'On-site payment recorded.');
    }

    private function onSiteDetails(Request $request): array
    {
        $request->validate(['collected_at' => ['required', 'date']]);
        $request->merge(['collected_at' => Carbon::parse($request->input('collected_at'), ResortTime::timezone())->setTimezone(config('app.timezone'))->toDateTimeString()]);
        return $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', Rule::in(PaymentMethod::values())],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'collected_at' => ['required', 'date', 'before_or_equal:now'],
            'reconciliation_note' => ['nullable', 'string', 'max:500'], 'funds_received' => ['accepted'],
        ]);
    }

    public function storeGroupBalance(Request $request, \App\Models\ReservationGroup $group): RedirectResponse
    {
        $data = $this->onSiteDetails($request);
        try {
            $this->service->settleGroupBalance($group, $request->user(), $data);
        } catch (InvalidPaymentAmountException|UnauthorizedPaymentActionException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Group balance payment recorded.');
    }

    public function __construct(
        private readonly PaymentService $service,
        private readonly FileUploadService $fileUploadService
    ) {}

    public function index(Request $request): View
    {
        $query = Payment::with(['reservation.user', 'reservation.facility.facilityType', 'reservation.reservationGroup', 'reservationGroup.user']);

        // Filter by Payment Status
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        // Filter by Payment Method
        if ($request->filled('method')) {
            $query->where('method', $request->input('method'));
        }

        // Filter by Date Range
        if ($request->filled('date_from')) {
            $query->where('payment_date', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_until')) {
            $query->where('payment_date', '<=', $request->input('date_until'));
        }

        // Filter by Facility
        if ($request->filled('facility_id')) {
            $query->where(fn ($q) => $q
                ->whereHas('reservation', fn ($r) => $r->where('facility_id', $request->input('facility_id')))
                ->orWhereHas('reservationGroup.reservations', fn ($r) => $r->where('facility_id', $request->input('facility_id'))));
        }

        // Filter by Facility Type
        if ($request->filled('facility_type')) {
            $typeModel = FacilityType::where('slug', $request->input('facility_type'))->first();
            if ($typeModel) {
                $query->where(fn ($q) => $q
                    ->whereHas('reservation.facility', fn ($f) => $f->where('facility_type_id', $typeModel->id))
                    ->orWhereHas('reservationGroup.reservations.facility', fn ($f) => $f->where('facility_type_id', $typeModel->id)));
            }
        }

        // Search the reservation’s guest details, including walk-ins where the
        // account holder may be a staff member rather than the guest.
        if ($request->filled('search')) {
            $search = (string) $request->input('search');
            $matchGroup = fn ($group) => $group->where(function ($q) use ($search) {
                foreach (['group_code', 'guest_name', 'guest_phone', 'guest_email'] as $column) {
                    $q->orWhere($column, 'like', '%'.$search.'%');
                }
            });
            $query->where(fn ($q) => $q
                ->whereHas('reservation', fn ($r) => $r->matchingGuestLookup($search))
                ->orWhereHas('reservationGroup', $matchGroup)
                ->orWhereHas('reservation.reservationGroup', $matchGroup));
        }

        // The same count the sidebar badge warns on, so the notice here and the
        // badge there are one number rather than two guesses at the same thing.
        $overdueReviews = LayoutBadges::overduePaymentCount();
        // Escalate the oldest waiting proofs before other payments.
        // Page by booking so a group's payments cannot split across pages.
        $bookingKey = "CASE WHEN COALESCE(payments.reservation_group_id, (SELECT reservation_group_id FROM reservations WHERE reservations.id = payments.reservation_id)) IS NOT NULL THEN CONCAT('group-', COALESCE(payments.reservation_group_id, (SELECT reservation_group_id FROM reservations WHERE reservations.id = payments.reservation_id))) ELSE CONCAT('reservation-', COALESCE(payments.reservation_id, CONCAT('payment-', payments.id))) END";
        $payments = (clone $query)->selectRaw("$bookingKey AS booking_key")
            ->selectRaw("MIN(CASE WHEN status = 'pending' THEN 0 ELSE 1 END) AS review_priority")
            ->selectRaw("MIN(CASE WHEN status = 'pending' THEN created_at END) AS waiting_since")
            ->selectRaw('MAX(created_at) AS latest_payment')
            ->groupBy('booking_key')
            ->orderBy('review_priority')
            ->orderBy('waiting_since')
            ->orderByDesc('latest_payment')
            ->orderBy('booking_key')
            ->paginate(20);

        $records = (clone $query)->whereIn(\Illuminate\Support\Facades\DB::raw($bookingKey), $payments->pluck('booking_key'))
            ->orderBy('created_at')->get()
            ->groupBy(fn ($payment) => ($groupId = $payment->reservation_group_id ?? $payment->reservation?->reservation_group_id)
                ? 'group-'.$groupId
                : 'reservation-'.($payment->reservation_id ?? 'payment-'.$payment->id));
        $payments->setCollection($payments->getCollection()->map(fn ($row) => $records->get($row->booking_key)));

        if (LiveFilter::wantsPartial($request)) {
            return view('admin.payments.partials.results', compact('payments'));
        }

        // Data for dropdowns
        $facilityTypes = FacilityType::where('is_active', true)->get();
        $facilities = Facility::where('is_active', true)->orderBy('name')->get();

        return view('admin.payments.index', compact('payments', 'facilityTypes', 'facilities', 'overdueReviews'));
    }

    public function show(Payment $payment): View
    {
        $payment->load([
            'reservation.user',
            'reservation.facility.facilityType',
            'reservation.payments',
            'reservationGroup.user',
            'reservationGroup.reservations.facility',
            'reservationGroup.directPayments',
            'verifiedBy',
        ]);

        return view('admin.payments.show', compact('payment'));
    }

    public function verify(Request $request, Payment $payment): RedirectResponse
    {
        $request->validate(['overpayment_note' => ['nullable', 'string', 'max:500'], 'funds_received' => [$payment->source === 'customer_deposit' ? 'accepted' : 'nullable']]);

        try {
            $this->service->verifyPayment($payment, $request->user(), $request->input('overpayment_note'));
        } catch (InvalidPaymentAmountException|InvalidPaymentTransitionException|UnauthorizedPaymentActionException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        $group = $payment->reservationGroup ?? $payment->reservation?->reservationGroup;
        $reservation = $payment->reservation;
        $destination = $group ? route('admin.reservation-groups.show', $group) : route('admin.reservations.show', $reservation);
        if ($payment->source === 'customer_deposit') {
            try {
                if ($group?->isPending() && $group->source === \App\Enums\ReservationSource::Online
                    && ! $group->requires_quote && $group->verifiedPaymentsTotal() >= $group->deposit_required) {
                    app(\App\Services\GroupedBookingService::class)->approveGroup($group, $request->user());
                    return redirect($destination)->with('success', 'Payment verified and booking confirmed.');
                }
                if (! $group && $reservation?->status === \App\Enums\ReservationStatus::Pending
                    && $reservation->reservation_source === \App\Enums\ReservationSource::Online
                    && $reservation->getVerifiedTotalPaid() >= $reservation->requiredDepositAmount()) {
                    app(\App\Services\ReservationService::class)->changeStatus($reservation, \App\Enums\ReservationStatus::Approved, $request->user());
                    return redirect($destination)->with('success', 'Payment verified and booking confirmed.');
                }
            } catch (\Illuminate\Validation\ValidationException|\App\Exceptions\Reservation\InvalidReservationTransitionException $exception) {
                return redirect($destination)->with('error', 'Payment verified. Booking needs review. '.$exception->getMessage());
            } catch (\Exception $exception) {
                report($exception);
                return redirect($destination)->with('error', 'Payment verified. Open the booking to review and retry confirmation.');
            }
        }

        if ($payment->reservation_group_id) {
            return redirect()
                ->route('admin.reservation-groups.show', $payment->reservation_group_id)
                ->with('success', 'Group payment verified. The guest can see the updated balance.');
        }

        return redirect()
            ->route('admin.reservations.show', $payment->reservation_id)
            ->with('success', 'Payment verified. The guest can see the updated balance.');
    }

    public function reject(Request $request, Payment $payment): RedirectResponse
    {
        $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'funds_not_received' => [$payment->source === 'customer_deposit' ? 'accepted' : 'nullable'],
        ]);

        try {
            $this->service->rejectPayment($payment, $request->user(), $request->input('reason'));
        } catch (InvalidPaymentTransitionException|UnauthorizedPaymentActionException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        if ($payment->reservation_group_id) {
            return redirect()
                ->route('admin.reservation-groups.show', $payment->reservation_group_id)
                ->with('success', 'Group payment proof declined. The guest has been notified.');
        }

        return redirect()
            ->route('admin.reservations.show', $payment->reservation_id)
            ->with('success', 'Payment proof declined. The guest has been notified and the amount no longer appears under review.');
    }

    public function requestTopUp(Request $request, Reservation $reservation): RedirectResponse
    {
        $request->validate(['reason' => ['required', 'string', 'max:500']]);
        try {
            $this->service->requestDepositTopUp($reservation, $request->user(), $request->string('reason')->toString());
        } catch (InvalidPaymentAmountException|UnauthorizedPaymentActionException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'The guest can now see the additional deposit amount and its new deadline.');
    }

    public function viewProof(Payment $payment): StreamedResponse
    {
        return $this->fileUploadService->view((string) $payment->proof_path);
    }

    public function downloadProof(Payment $payment): StreamedResponse
    {
        return $this->fileUploadService->download((string) $payment->proof_path);
    }
}
