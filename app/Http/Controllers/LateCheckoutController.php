<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
use App\Services\LateCheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LateCheckoutController extends Controller
{
    public function quote(Request $request, Reservation $reservation, LateCheckoutService $service): JsonResponse
    {
        abort_unless($reservation->user_id === $request->user()->id, 403);
        $data = $request->validate(['hours' => ['required', 'integer', 'min:1', 'max:'.config('resort.stay.max_additional_hours', 12)]]);

        return response()->json($service->quote($reservation, (int) $data['hours']));
    }

    public function store(Request $request, Reservation $reservation, LateCheckoutService $service): RedirectResponse
    {
        abort_unless($reservation->user_id === $request->user()->id, 403);
        $data = $request->validate([
            'hours' => ['required', 'integer', 'min:1', 'max:'.config('resort.stay.max_additional_hours', 12)],
            'fee_cents' => ['required', 'integer', 'min:1'],
        ]);
        $service->request($reservation, $request->user(), (int) $data['hours'], (int) $data['fee_cents']);

        return redirect()->route('customer.reservations.show', $reservation)->with('success', 'We received your late check-out request. Keep your original check-out time until we approve it. We’ll only add the fee if it’s approved.');
    }

    public function decide(Request $request, Reservation $reservation, LateCheckoutService $service): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approve,decline'],
            'fee_cents' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'required_if:decision,decline', 'string', 'max:1000'],
        ]);
        $service->decide($reservation, $request->user(), $data['decision'] === 'approve', (int) $data['fee_cents'], $data['reason'] ?? null);

        return redirect()->back()->with('success', $data['decision'] === 'approve'
            ? 'Late check-out approved. The check out time, booking total and balance are updated.'
            : 'Late check-out declined. No fee was charged.');
    }
}
