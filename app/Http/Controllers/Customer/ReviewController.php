<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\GuestReview;
use App\Models\Reservation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReviewController extends Controller
{
    public function store(Request $request, Reservation $reservation)
    {
        abort_unless($reservation->user_id === $request->user()->id, 403);
        $data = $request->validate(['rating' => ['required', 'integer', 'between:1,5'], 'body' => ['nullable', 'string', 'max:2000'], 'public_consent' => ['required', 'boolean']]);
        DB::transaction(function () use ($reservation, $request, $data) {
            $locked = Reservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->canReview(), 403, 'You can review after staff record checkout.');
            $review = GuestReview::where('reservation_id', $locked->id)->lockForUpdate()->first();
            abort_if($review && ($review->moderated_at || $review->is_published), 409, 'This review has already been moderated. Please contact the resort about a correction.');
            $review ??= new GuestReview(['user_id' => $request->user()->id, 'reservation_id' => $locked->id, 'reviewed_reservation_id' => $locked->id]);
            $review->fill([...$data, 'is_published' => false, 'stay_label' => $locked->facility->facilityType?->name ?? 'Resort visit']);
            $review->save();
            activity()->performedOn($review)->causedBy($request->user())->event('guest_review_submitted')->log('Post-stay feedback submitted for moderation.');
        }, 5);
        return redirect()->route('customer.reservations.show', $reservation)->with('success', 'Thanks for sharing your feedback. The resort team has received it.');
    }
}
