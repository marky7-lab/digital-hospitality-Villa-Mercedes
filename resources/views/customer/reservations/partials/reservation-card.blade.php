@php
    $remaining = $reservation->getRemainingBalance();
    $verifiedPaid = $reservation->getVerifiedTotalPaid();
    $pendingVerification = $reservation->getPendingVerificationTotal();
    $balanceIfVerified = max(0, round($remaining - $pendingVerification, 2));
    $paymentState = $reservation->guestPaymentState();
    $canPay = $reservation->canAcceptGuestPayment();
    $refundStatus = ($reservation->reservationGroup?->booking_workflow ?? $reservation->booking_workflow ?? [])['refund']['status'] ?? null;
    $statusLabel = match ($reservation->status) {
        \App\Enums\ReservationStatus::Pending => 'Awaiting confirmation',
        \App\Enums\ReservationStatus::Approved => $reservation->check_out->isPast()
            ? 'Stay finished' : ($reservation->check_in->isPast() ? 'Current stay' : 'Upcoming stay'),
        \App\Enums\ReservationStatus::Completed => 'Stay finished',
        \App\Enums\ReservationStatus::Rejected => 'Declined',
        default => null,
    };
    $isClosed = in_array($reservation->status, [
        \App\Enums\ReservationStatus::Cancelled, \App\Enums\ReservationStatus::Rejected,
        \App\Enums\ReservationStatus::Expired, \App\Enums\ReservationStatus::Rescheduled,
    ], true);
    $closedReason = match ($reservation->status) {
        \App\Enums\ReservationStatus::Cancelled => $reservation->cancellation_reason,
        \App\Enums\ReservationStatus::Rejected => $reservation->rejection_reason,
        \App\Enums\ReservationStatus::Expired => $reservation->expired_at ? 'Expired on '.$reservation->expired_at->format('M j, Y').'.' : null,
        default => null,
    };
    $refundApproved = in_array($refundStatus, ['approved', 'returned'], true);

    // Where this card opens, and what the one payment action on it says. Both are
    // decided here so the footer can tell whether they lead to the same page.
    $destination = \App\Enums\ReservationDestination::for($reservation);
    $paymentAction = $paymentState === \App\Enums\GuestPaymentState::AwaitingApproval
            ? ['kind' => 'status', 'label' => 'Review pending', 'note' => 'We email you when the resort verifies your request and payment. Check your reservation status here anytime.']
            : match (true) {
            $paymentState === \App\Enums\GuestPaymentState::ReceiptPending => ['kind' => 'link', 'label' => 'Receipt awaiting verification'],
            $canPay => ['kind' => 'link', 'label' => config('payments.deposit_workflow') ? 'Submit deposit' : 'Pay balance'],
            default => null,
        };

    // The card offers one link per page. A booking that is waiting on money already
    // has its payment button here, and that button goes exactly where the card's
    // link would — a second path to the same page reads as two different places.
    $showDestination = ! (\App\Enums\ReservationDestination::Payments === $destination
        && ($paymentAction['kind'] ?? null) === 'link');
@endphp

<div class="bg-surface border border-border rounded-2xl shadow-sm overflow-hidden">
    <div class="px-4 py-3 sm:px-6 sm:py-4 flex flex-col sm:flex-row sm:justify-between sm:items-start gap-3 border-b border-border">
        <div class="min-w-0">
            @if($reservation->reservation_group_id && $reservation->reservationGroup)
                <p class="text-xs font-semibold text-primary uppercase tracking-wide mb-0.5">
                    Group: {{ $reservation->reservationGroup->group_code }}
                </p>
                @if($reservation->reservationGroup->isPending() && $reservation->reservationGroup->quoted_at && ! $reservation->reservationGroup->requires_quote && ! $reservation->reservationGroup->directPayments()->exists())
                    <a href="{{ route('customer.grouped-booking.quote-checkout', $reservation->reservationGroup) }}" class="mb-2 inline-block rounded-lg bg-primary px-3 py-2 text-xs font-semibold text-white">Review quote and submit deposit</a>
                @endif
            @endif
            <h2 class="break-words font-heading text-xl sm:text-2xl font-semibold text-text">
                {{ $reservation->facility->name }}
            </h2>
            <p class="mt-1 text-sm text-text-muted">
                {{ $reservation->reservation_code }}
            </p>
        </div>
        <div class="self-start">
            <x-badge :status="$reservation->status" :label="$statusLabel" />
        </div>
    </div>

    <dl class="px-4 py-3 sm:px-6 sm:py-4 grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="sm:col-span-2">
            <dt class="text-xs font-semibold uppercase tracking-wide text-text-muted">Dates</dt>
            <dd class="mt-1 text-sm text-text">
                {{ $reservation->check_in->format('M d, Y g:i A') }}
                <span class="text-text-muted">to</span>
                {{ $reservation->check_out->format('M d, Y g:i A') }}
            </dd>
        </div>
        <div>
            <dt class="text-xs font-semibold uppercase tracking-wide text-text-muted">Guests</dt>
            <dd class="mt-1 text-sm text-text">{{ $reservation->guest_count }}</dd>
        </div>
    </dl>
    @if($closedReason)
        <p class="break-words px-5 pb-4 text-sm text-text-muted sm:px-6">{{ $closedReason }}</p>
    @endif

    <div class="px-4 py-3 sm:px-6 sm:py-4 bg-background border-t border-border flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div class="flex flex-wrap gap-x-6 gap-y-2">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-text-muted">Total</p>
                <p class="mt-0.5 text-sm font-medium text-text"><x-amount :value="$reservation->total_price" /></p>
            </div>
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-text-muted">Verified paid</p>
                <p class="mt-0.5 text-sm font-medium text-success"><x-amount :value="$verifiedPaid" /></p>
            </div>
            @if($pendingVerification > 0)
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-text-muted">Under review</p>
                    <p class="mt-0.5 text-sm font-semibold text-warning"><x-amount :value="$pendingVerification" /></p>
                </div>
            @endif
            @if(! $isClosed || $refundStatus)
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-text-muted">{{ $refundStatus ? 'Refund' : ($pendingVerification > 0 ? 'Balance if verified' : 'Balance') }}</p>
                @if($refundStatus && ! $isClosed)<a href="{{ route('customer.reservations.show', $reservation) }}#refund-flow" class="inline-flex min-h-11 items-center text-sm font-semibold text-primary underline">Track refund</a>@endif
                @if($refundApproved)
                    <p class="mt-0.5 text-sm font-semibold text-text">{{ $refundStatus === 'returned' ? 'Funds returned' : 'Refund approved, awaiting return' }}</p>
                    <p class="text-xs text-text-muted">No check in balance collectible</p>
                @elseif($refundStatus === 'requested')
                    <p class="mt-0.5 text-sm font-semibold text-text">Refund requested</p>
                @elseif(($pendingVerification > 0 ? $balanceIfVerified : $remaining) > 0)
                    <p class="mt-0.5 font-heading text-xl font-semibold text-primary"><x-amount :value="$pendingVerification > 0 ? $balanceIfVerified : $remaining" /></p>
                @elseif($pendingVerification > 0)
                    <p class="mt-0.5 text-sm font-semibold text-warning">Awaiting verification</p>
                @else
                    <p class="mt-0.5 text-sm font-semibold text-success">Fully paid</p>
                @endif
            </div>
            @endif
        </div>

        <div class="flex flex-col-reverse sm:flex-row gap-3 sm:items-center shrink-0">
            @if($reservation->canShowArrivalPass())
                <a href="{{ $reservation->arrivalPassUrl() }}"
                   class="inline-flex justify-center items-center min-h-[44px] px-4 text-sm font-semibold text-primary hover:underline focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2 rounded-lg">
                    Check in pass
                </a>
            @endif
            @if($showDestination)
                <a href="{{ url()->query($destination->url($reservation), request()->only('tab', 'page')) }}"
                   class="inline-flex justify-center items-center min-h-[44px] px-4 text-sm font-semibold text-primary hover:underline focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2 rounded-lg">
                    {{ $destination->linkLabel() }}
                </a>
            @endif

            @if($isClosed && $refundStatus)
                <x-primary-button tag="a" href="{{ route('customer.reservations.show', [$reservation, ...request()->only('tab', 'page')]) }}#refund-flow" class="justify-center">Track refund</x-primary-button>
            @elseif($isClosed && ($pendingVerification > 0 || $verifiedPaid > 0))
                <x-primary-button tag="a" href="{{ route('customer.payments.index', [$reservation, ...request()->only('tab', 'page')]) }}" class="justify-center">View payment status</x-primary-button>
            @elseif(! $isClosed && ($paymentAction['kind'] ?? null) === 'status')
                <span class="inline-flex flex-col items-center min-h-[44px] px-4 text-sm text-text-muted">
                    <span class="font-semibold">{{ $paymentAction['label'] }}</span>
                    @if(!empty($paymentAction['note']))
                        <span class="mt-0.5">{{ $paymentAction['note'] }}</span>
                    @endif
                </span>
            @elseif(! $isClosed && $paymentAction !== null)
                <x-primary-button tag="a" href="{{ route('customer.payments.index', [$reservation, ...request()->only('tab', 'page')]) }}" class="justify-center">
                    {{ $paymentAction['label'] }}
                </x-primary-button>
            @endif
        </div>
    </div>
</div>
