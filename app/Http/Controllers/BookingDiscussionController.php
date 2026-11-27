<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Exceptions\Reservation\InvalidReservationTransitionException;
use App\Exceptions\Reservation\ReservationConflictException;
use App\Models\Facility;
use App\Models\Reservation;
use App\Services\BookingPracticeService;
use App\Services\ReservationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BookingDiscussionController extends Controller
{
    public function update(Request $request, Reservation $reservation, BookingPracticeService $service): RedirectResponse
    {
        $staff = $request->user()->hasRole(Role::Administrator->value);
        abort_unless($staff || $reservation->user_id === $request->user()->id, 403);
        $actions = $staff
            ? ['request_refund', 'approve_refund', 'return_funds', 'propose_change', 'confirm_change', 'child_charge', 'resolve_child', 'arrival_discount', 'review_arrival']
            : ['request_refund', 'propose_change', 'accept_change', 'dispute_child'];
        $data = $request->validate([
            'action' => ['required', Rule::in($actions)],
            'reason' => ['required_if:action,request_refund,approve_refund,return_funds,child_charge,resolve_child,arrival_discount,dispute_child', 'nullable', 'string', 'max:1000'],
            'amount' => ['required_if:action,return_funds,child_charge,arrival_discount,accept_change', 'nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'facility_id' => ['required_if:action,propose_change', 'nullable', 'integer', 'exists:facilities,id'],
            'check_in' => ['required_if:action,propose_change', 'nullable', 'date'],
            'check_out' => ['required_if:action,propose_change', 'nullable', 'date', 'after:check_in'],
            'guest_count' => ['required_if:action,propose_change', 'nullable', 'integer', 'min:1'],
            'pax_tier' => ['nullable', 'integer', 'min:1'],
            'swimming_session_id' => ['nullable', 'integer', 'exists:swimming_sessions,id'],
            'early_check_in_hours' => ['nullable', 'integer', 'min:0', 'max:2'],
        ]);
        $actor = $request->user();
        $cents = (int) round((float) ($data['amount'] ?? 0) * 100);
        $reason = $data['reason'] ?? '';
        try {
            match ($data['action']) {
                'request_refund' => $service->requestCancellation($reservation, $actor, $reason),
                'approve_refund' => $service->approveRefund($reservation, $actor, $reason),
                'return_funds' => $service->recordReturnedFunds($reservation, $actor, $cents, $reason),
                'propose_change' => $service->proposeChange($reservation, $actor, $request->only('facility_id', 'check_in', 'check_out', 'guest_count', 'pax_tier', 'early_check_in_hours', 'swimming_session_id')),
                'accept_change' => $service->acceptChange($reservation, $actor, $cents),
                'confirm_change' => $service->confirmChange($reservation, $actor),
                'child_charge' => $service->childCharge($reservation, $actor, $cents, $reason),
                'dispute_child' => $service->discussChildCharge($reservation, $actor, 'dispute', $reason),
                'resolve_child' => $service->discussChildCharge($reservation, $actor, 'resolve', $reason),
                'arrival_discount' => $service->arrivalDiscount($reservation, $actor, $cents, $reason),
                'review_arrival' => app(ReservationService::class)->reviewArrival($reservation, $actor),
            };
        } catch (ReservationConflictException|InvalidReservationTransitionException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', match ($data['action']) {
            'propose_change' => 'The proposed dates and price are ready to review. The current dates stay booked while the change is being reviewed.',
            'accept_change' => 'Quote accepted. Staff will check availability before confirming the new dates.',
            'confirm_change' => 'The new booking dates are confirmed.',
            'arrival_discount' => 'Check-in discount recorded.',
            'review_arrival' => 'Early check-in charge reviewed.',
            'request_refund' => $staff ? 'The cancellation and refund request was saved.' : 'The resort received your cancellation and refund request.',
            'approve_refund' => 'The owner’s refund approval was recorded. Record the refund once the money is sent.',
            'return_funds' => 'The refund was recorded as sent.',
            default => 'Your booking update was saved.',
        });
    }

    public function completeCleaning(Request $request, Facility $facility, ReservationService $service): RedirectResponse
    {
        try {
            $service->completeCleaning($facility, $request->user());
        } catch (InvalidReservationTransitionException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Cleaning marked complete. Upcoming bookings and closures still determine when guests can book this space.');
    }
}
