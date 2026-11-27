@php
    // The record's vital signs, the same on both facets: what this booking costs,
    // what is still owed, and what to do about it.
    $remaining = $reservation->getRemainingBalance();
    $paymentState = $reservation->guestPaymentState();
    $deadline = $reservation->deposit_due_at?->copy()->timezone('Asia/Manila');
    $paymentsUrl = route('customer.payments.index', [$reservation, ...request()->only('tab', 'page')]);
    $refund = ($reservation->reservationGroup?->booking_workflow ?? $reservation->booking_workflow ?? [])['refund'] ?? null;
@endphp

{{--
    One line, not a panel. The record used to quote the money twice — a
    "Pricing" card in the left column and a "Payment" panel in the right, each
    with its own copy of the total — so a guest comparing them had to work out
    that they were the same number, and the deposit instructions competed with
    the bed count. The figures stay here because "what do I owe" is a question
    worth answering from the record; the ledger, the deposit form and the
    receipt history live on the Payments tab.
--}}
<div class="rounded-2xl border border-border bg-surface px-5 py-4 sm:px-6">
    <div class="flex flex-wrap items-center justify-between gap-x-6 gap-y-3">
        <div class="min-w-0 text-sm">
            <p class="font-semibold text-text">
                @if(in_array($refund['status'] ?? null, ['approved', 'returned'], true))
                    <x-amount :value="$reservation->total_price" /> saved total &middot; cancelled, no check in balance collectible
                @elseif($remaining > 0)
                    <x-amount :value="$remaining" /> remaining of <x-amount :value="$reservation->total_price" />
                @else
                    <x-amount :value="$reservation->total_price" /> total &middot; fully paid
                @endif
            </p>

            <p class="mt-1 text-text-muted">
                @switch($paymentState)
                    @case(\App\Enums\GuestPaymentState::PaymentAvailable)
                        @if($reservation->policy_version === config('resort.policy.confirmed_version'))
                            @if($reservation->getDepositAvailableAmount() > 0)
                                At least <x-amount :value="$reservation->getDepositAvailableAmount()" /> remains toward your 50% down payment.
                            @else
                                Your minimum down payment is verified.
                            @endif
                            You may pay the remaining <x-amount :value="$remaining" /> online, or settle it at physical check-in. New proof remains under review until verified.
                        @elseif($reservation->deposit_required !== null)
                            Submit the required deposit <x-amount :value="$reservation->requiredDepositAmount()" />
                            @if($deadline)
                                by <span class="font-semibold text-text">{{ $deadline->format('M d, Y g:i A') }}</span> (Philippine time)
                            @endif
                            to keep this booking. Staff confirm the booking once they verify the transfer.
                        @else
                            Pay any part of the balance now, or settle it at the resort on check in.
                        @endif
                        @break
                    @case(\App\Enums\GuestPaymentState::ReceiptPending)
                        Your receipt is with staff for verification. A receipt is not a confirmation until they check the transfer.
                        @break
                    @case(\App\Enums\GuestPaymentState::OnSiteBalance)
                        Nothing to pay online. Settle the balance at the front desk on check in.
                        @break
                    @case(\App\Enums\GuestPaymentState::AwaitingApproval)
                        @if(config('payments.deposit_workflow'))
                            Waiting for the resort to review this request and verify deposit payment. You will receive updates as staff review your booking.
                        @else
                            Waiting for the resort. Do not transfer funds until staff post the amount and its instructions.
                        @endif
                        @break
                    @case(\App\Enums\GuestPaymentState::Closed)
                        Payment is closed for this reservation.
                        @break
                    @default
                        Thank you &mdash; nothing further to pay on this booking.
                @endswitch
            </p>
        </div>

        @if($paymentState === \App\Enums\GuestPaymentState::PaymentAvailable)
            <x-primary-button tag="a" :href="$paymentsUrl" class="w-full justify-center sm:w-auto">{{ $reservation->policy_version === config('resort.policy.confirmed_version') ? 'Submit payment proof' : 'Submit deposit' }}</x-primary-button>
        @elseif($paymentState !== \App\Enums\GuestPaymentState::FullyPaid)
            <x-secondary-button tag="a" :href="$paymentsUrl" class="w-full justify-center sm:w-auto">Payment history</x-secondary-button>
        @else
            <a href="{{ $paymentsUrl }}" class="text-sm font-semibold text-primary hover:underline">Payment history &rarr;</a>
        @endif
    </div>
</div>

{{-- An overpayment is a warning about money that needs a person, not a ledger to
     browse, so it is the one money notice that survives on the record. The proof
     of it is on the Payments tab. --}}
<x-payment-overpayment :reservation="$reservation" />
