<?php

namespace App\Http\Controllers;

use App\Models\Facility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReservationIntentController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        // Check if multi-line group intent is provided
        if ($request->has('lines') && is_array($request->input('lines'))) {
            $validated = $request->validate([
                'lines' => ['required', 'array', 'min:1'],
                'lines.*.facility_id' => ['required', 'integer', 'exists:facilities,id'],
                'lines.*.check_in' => ['nullable', 'string'],
                'lines.*.check_out' => ['nullable', 'string'],
                'lines.*.date' => ['nullable', 'date_format:Y-m-d'],
                'lines.*.swimming_session_id' => ['nullable', 'integer', 'exists:swimming_sessions,id'],
                'lines.*.session' => ['nullable', 'string'],
                'lines.*.guest_count' => ['nullable', 'integer', 'min:1'],
                'lines.*.pax_tier' => ['nullable', 'integer', 'min:1'],
                'lines.*.early_check_in_hours' => ['nullable', 'integer', 'min:0', 'max:2'],
                'lines.*.late_check_out_hours' => ['nullable', 'integer', 'min:0', 'max:12'],
                'lines.*.special_requests' => ['nullable', 'string', 'max:1000'],
            ]);

            $url = route('customer.grouped-booking.workspace', ['resume' => 1]);
            $request->session()->put('grouped_booking.intent', ['lines' => $validated['lines']]);
            $request->session()->put('url.intended', $url);

            return response()->json([
                'name' => 'Group Booking (' . count($validated['lines']) . ' services)',
                'redirect' => $url,
                'authenticated' => $request->user() !== null,
            ]);
        }

        // Single facility intent (backward-compatibility & prefill into workspace)
        $data = $request->validate([
            'facility_id' => ['required', 'integer', 'exists:facilities,id'],
            'check_in' => ['nullable', 'date_format:Y-m-d'],
            'check_out' => ['nullable', 'date_format:Y-m-d'],
            'arrival' => ['nullable', 'date_format:Y-m-d H:i'],
            'departure' => ['nullable', 'date_format:Y-m-d H:i'],
            'guests' => ['nullable', 'integer', 'min:1'],
            'early_check_in_hours' => ['nullable', 'integer', 'min:0', 'max:2'],
            'pax_tier' => ['nullable', 'integer', 'min:1'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'session' => ['nullable', 'string', 'max:50', 'exists:swimming_sessions,slug'],
            'availability_checked' => ['nullable', 'in:1'],
        ]);
        $facility = Facility::query()->findOrFail($data['facility_id']);
        abort_unless($facility->is_active && $facility->isOnlineBookable(), 404);

        $lineItem = [
            'facility_id' => $facility->id,
            'check_in' => $data['arrival'] ?? (isset($data['check_in']) ? $data['check_in'] . ' 14:00' : null),
            'check_out' => $data['departure'] ?? (isset($data['check_out']) ? $data['check_out'] . ' 12:00' : null),
            'date' => $data['date'] ?? $data['check_in'] ?? null,
            'session' => $data['session'] ?? null,
            'guest_count' => (int) ($data['guests'] ?? 0),
            'pax_tier' => $data['pax_tier'] ?? null,
            'early_check_in_hours' => $data['early_check_in_hours'] ?? 0,
        ];

        $request->session()->put('grouped_booking.intent', ['lines' => [$lineItem]]);

        unset($data['facility_id']);
        $url = route('customer.grouped-booking.workspace', [
            'facility_id' => $facility->id,
            ...array_filter($data, fn ($value) => $value !== null),
        ]);

        $request->session()->put('url.intended', $url);
        $request->session()->put('reservation.selection', ['name' => $facility->name, 'url' => $url]);

        return response()->json(['name' => $facility->name, 'redirect' => $url, 'authenticated' => $request->user() !== null]);
    }
}
