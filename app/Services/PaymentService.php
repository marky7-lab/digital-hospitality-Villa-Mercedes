<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Enums\Role;
use App\Exceptions\Payment\InvalidPaymentAmountException;
use App\Exceptions\Payment\InvalidPaymentTransitionException;
use App\Exceptions\Payment\PaymentNotEligibleException;
use App\Exceptions\Payment\PaymentOwnershipException;
use App\Exceptions\Payment\UnauthorizedPaymentActionException;
use App\Models\Payment;
use App\Models\PaymentDestination;
use App\Models\Reservation;
use App\Models\User;
use App\Notifications\Reservation\DepositUpdateNotification;
use App\Support\DepositDeadline;
use App\Support\Money;
use App\Support\ResortTime;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    /**
     * @param  string|int|float  $amount
     */
    public function createPayment(
        Reservation $reservation,
        User $submitter,
        $amount,
        PaymentMethod $method,
        ?string $proofReference = null,
        array $details = []
    ): Payment {
        if (! is_numeric($amount) || ! is_finite((float) $amount) || round((float) $amount * 100) < 1) {
            throw new InvalidPaymentAmountException('Payment amount must be a positive value.');
        }

        return DB::transaction(function () use ($reservation, $submitter, $amount, $method, $proofReference, $details) {
            $reservation = $this->lockReservationLedger($reservation->id);
            $isStaff = $submitter->hasRole([Role::Administrator->value, 'staff']);

            if ($submitter->id !== $reservation->user_id && ! $isStaff) {
                throw new PaymentOwnershipException('You are not authorized to submit a payment for this reservation.');
            }

            if (! $isStaff && ! $reservation->canAcceptGuestPayment()) {
                throw new PaymentNotEligibleException(
                    config('payments.deposit_workflow') ? 'The 50% deposit opens once the resort approves this reservation, and closes at the deadline that approval sets. Contact staff if it has already closed.' : 'Payment is available after the resort confirms this reservation, and only while a balance remains.'
                );
            }

            $deposit = config('payments.deposit_workflow') && ! $isStaff;
            $destination = null;
            if ($deposit) {
                $destination = PaymentDestination::whereKey($details['payment_destination_id'] ?? 0)
                    ->where('method', $method->value)->lockForUpdate()->first();
                if (! $destination || (! $destination->is_active && $destination->updated_at->lt($reservation->created_at)) || ! $proofReference) {
                    throw new PaymentNotEligibleException('Choose the resort receiving account used for this booking and upload proof of payment.');
                }
            }
            $workflow = $reservation->reservationGroup?->booking_workflow ?? $reservation->booking_workflow ?? [];
            if (! $isStaff && (($workflow['child_charge']['status'] ?? null) === 'disputed' || isset($workflow['refund']))) {
                throw new PaymentNotEligibleException('This booking is held for staff discussion. Do not transfer another payment.');
            }
            $balance = $deposit && $reservation->policy_version !== config('resort.policy.confirmed_version') ? $reservation->getDepositAvailableAmount() : $reservation->getAvailablePaymentAmount();
            if ($deposit && $reservation->policy_version === config('resort.policy.confirmed_version')
                && (int) round((float) $amount * 100) < (int) round($reservation->getDepositAvailableAmount() * 100)) {
                throw new InvalidPaymentAmountException('Submit at least the remaining 50% down payment, or pay the full balance online.');
            }

            if ((int) round((float) $amount * 100) > (int) round($balance * 100)) {
                throw new InvalidPaymentAmountException(
                    'Payment amount cannot exceed '.Money::of($balance).' after verified payments and pending submissions.'
                );
            }

            // Determine deterministic decimal amount
            $decimalAmount = number_format((float) $amount, 2, '.', '');

            $payment = Payment::create([
                'reservation_id' => $reservation->id,
                'amount' => $decimalAmount,
                'method' => $method->value,
                'reference_number' => isset($details['reference_number']) ? strtoupper(trim($details['reference_number'])) : null,
                'payment_date' => $details['payment_date'] ?? now()->toDateString(),
                'payment_destination_id' => $destination?->id,
                'destination_snapshot' => $destination?->snapshot(),
                'source' => $deposit ? 'customer_deposit' : 'legacy',
                'proof_hash' => $details['proof_hash'] ?? null,
                'proof_path' => $proofReference,
                'status' => PaymentStatus::Pending->value,
                'verified_by' => null,
                'verified_at' => null,
                'rejection_reason' => null,
            ]);

            activity()
                ->performedOn($payment)
                ->causedBy($submitter)
                ->event('payment_submitted')
                ->log("Payment submitted for reservation {$reservation->reservation_code}");

            if ($deposit) {
                DB::afterCommit(fn () => $reservation->user?->notify(new DepositUpdateNotification($reservation->id, 'We received your deposit receipt. Our team will check the payment.')));
            }

            return $payment;
        }, 5);
    }

    public function verifyPayment(Payment $payment, User $verifier, ?string $overpaymentNote = null): void
    {
        if (! $verifier->hasRole([Role::Administrator->value, 'staff'])) {
            throw new UnauthorizedPaymentActionException('Only authorized staff can verify payments.');
        }

        DB::transaction(function () use ($payment, $verifier, $overpaymentNote) {
            $reservation = $payment->reservation_id ? $this->lockReservationLedger($payment->reservation_id) : null;
            $group = $payment->reservation_group_id ? \App\Models\ReservationGroup::whereKey($payment->reservation_group_id)->lockForUpdate()->first() : null;

            // Lock payment row
            $lockedPayment = Payment::where('id', $payment->id)->lockForUpdate()->firstOrFail();

            if ($lockedPayment->status !== PaymentStatus::Pending) {
                throw new InvalidPaymentTransitionException("Cannot verify a payment that is currently {$lockedPayment->status->value}.");
            }

            $remainingBalance = $reservation ? $reservation->getRemainingBalance() : ($group ? $group->outstandingBalance() : 0);
            $excess = max(0, (int) round((float) $lockedPayment->amount * 100) - (int) round($remainingBalance * 100));

            $duplicate = $lockedPayment->reference_number && Payment::where('method', $lockedPayment->method->value)
                ->where('reference_number', $lockedPayment->reference_number)->where('id', '!=', $lockedPayment->id)
                ->where('status', PaymentStatus::Verified)->exists();
            if ($duplicate && blank($overpaymentNote)) {
                throw new InvalidPaymentAmountException('This reference was already verified. Reconcile the transaction and enter a note before verifying.');
            }
            if ($excess > 0 && blank($overpaymentNote)) {
                throw new InvalidPaymentAmountException('This payment exceeds the remaining balance. Confirm the funds received and enter an overpayment reconciliation note before verifying it.');
            }

            $lockedPayment->update([
                'status' => PaymentStatus::Verified->value,
                'verified_by' => $verifier->id,
                'verified_at' => now(),
                'reconciliation_note' => $overpaymentNote,
            ]);

            if ($reservation) {
                $reservation->unsetRelation('payments');
                if ($lockedPayment->source === 'customer_deposit') {
                    if ($reservation->status === ReservationStatus::Approved && $reservation->getDepositAvailableAmount() > 0) {
                        $reservation->update(['deposit_due_at' => DepositDeadline::forReservation($reservation)]);
                    }
                    DB::afterCommit(fn () => $reservation->user?->notify(new DepositUpdateNotification($reservation->id, 'We checked your deposit. Open your booking to see its status and whether you still owe part of the deposit.')));
                }
            }

            if ($group) {
                $recipient = $group->user;
                if ($recipient) {
                    DB::afterCommit(fn () => $recipient->notify(new \App\Notifications\Reservation\GroupDepositVerifiedMail($group, $lockedPayment)));
                }
            }

            $this->queueReceipt($lockedPayment, $reservation?->user ?? $group?->user);

            activity()
                ->performedOn($lockedPayment)
                ->causedBy($verifier)
                ->event('payment_verified')
                ->withProperties([
                    'previous_status' => PaymentStatus::Pending->value,
                    'new_status' => PaymentStatus::Verified->value,
                    'overpayment_centavos' => $excess,
                    'overpayment_note' => $excess > 0 ? trim($overpaymentNote) : null,
                ])
                ->log("Payment verified by {$verifier->name}");
        }, 5);
    }

    /**
     * Verification driven by the payment provider rather than a staff member.
     * There is no human verifier here on purpose: the gateway confirmed the
     * funds, and recording a staff account as the verifier would be a lie in
     * the audit trail.
     *
     * @throws InvalidPaymentTransitionException
     */
    public function verifyPaymentViaGateway(Payment $payment, string $gatewayReference): void
    {
        DB::transaction(function () use ($payment, $gatewayReference) {
            $reservation = $this->lockReservationLedger($payment->reservation_id);
            $lockedPayment = Payment::withTrashed()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($lockedPayment->reference_number && $lockedPayment->reference_number !== $gatewayReference) {
                throw new InvalidPaymentTransitionException('The gateway reference does not match this payment.');
            }

            if ($lockedPayment->status === PaymentStatus::Verified) {
                return;
            }

            if ($lockedPayment->status !== PaymentStatus::Pending) {
                throw new InvalidPaymentTransitionException("Cannot verify a payment that is currently {$lockedPayment->status->value}.");
            }

            if ($lockedPayment->trashed()) {
                $lockedPayment->restore();
            }

            $lockedPayment->update([
                'status' => PaymentStatus::Verified->value,
                'reference_number' => $gatewayReference,
                'verified_at' => now(),
            ]);

            $this->queueReceipt($lockedPayment, $reservation->user);

            activity()
                ->performedOn($lockedPayment)
                ->event('payment_verified')
                ->withProperties([
                    'gateway_reference' => $gatewayReference,
                    'overpayment_centavos' => $this->overpaymentCents($reservation, $lockedPayment),
                ])
                ->log('Payment verified automatically by the payment gateway.');
        }, 5);
    }

    public function rejectPayment(Payment $payment, User $verifier, string $reason): void
    {
        if (! $verifier->hasRole([Role::Administrator->value, 'staff'])) {
            throw new UnauthorizedPaymentActionException('Only authorized staff can reject payments.');
        }

        DB::transaction(function () use ($payment, $verifier, $reason) {
            $reservation = $payment->reservation_id ? $this->lockReservationLedger($payment->reservation_id) : null;
            // Lock payment row
            $lockedPayment = Payment::where('id', $payment->id)->lockForUpdate()->firstOrFail();

            if ($lockedPayment->status !== PaymentStatus::Pending) {
                throw new InvalidPaymentTransitionException("Cannot reject a payment that is currently {$lockedPayment->status->value}.");
            }

            $lockedPayment->update([
                'status' => PaymentStatus::Rejected->value,
                'rejection_reason' => $reason,
                'verified_by' => $verifier->id,
                'verified_at' => now(),
            ]);

            if ($reservation && $lockedPayment->source === 'customer_deposit') {
                $reservation->update(['deposit_due_at' => DepositDeadline::forReservation($reservation)]);
                DB::afterCommit(fn () => $reservation->user?->notify(new DepositUpdateNotification($reservation->id, 'We couldn’t verify your deposit receipt. Here’s what needs attention: '.$reason)));
            }

            activity()
                ->performedOn($lockedPayment)
                ->causedBy($verifier)
                ->event('payment_rejected')
                ->withProperties(['previous_status' => PaymentStatus::Pending->value, 'new_status' => PaymentStatus::Rejected->value])
                ->log("Payment rejected by {$verifier->name}");
        }, 5);
    }

    private function queueReceipt(Payment $payment, ?User $guest): void
    {
        if ($guest && ! $guest->hasRole(Role::Administrator->value)) {
            DB::afterCommit(fn () => $guest->notify(new \App\Notifications\Reservation\VerifiedPaymentReceipt($payment)));
        }
    }

    private function lockReservationLedger(int $reservationId): Reservation
    {
        $identity = Reservation::whereKey($reservationId)->firstOrFail();
        if ($identity->reservation_group_id) {
            \App\Models\ReservationGroup::whereKey($identity->reservation_group_id)->lockForUpdate()->firstOrFail();
        }
        $reservation = Reservation::whereKey($reservationId)->lockForUpdate()->firstOrFail();
        $reservation->setRelation('payments', $reservation->payments()->orderBy('id')->lockForUpdate()->get());

        return $reservation;
    }

    public function requestDepositTopUp(Reservation $reservation, User $actor, string $reason): void
    {
        if (! $actor->hasRole('Administrator')) {
            throw new UnauthorizedPaymentActionException('Only administrators can request a top-up.');
        }
        DB::transaction(function () use ($reservation, $actor, $reason) {
            $locked = $this->lockReservationLedger($reservation->id);
            if ($locked->deposit_required === null || ! in_array($locked->status, [ReservationStatus::Pending, ReservationStatus::Approved], true)
                || $locked->getPendingVerificationTotal() > 0 || $locked->getDepositAvailableAmount() <= 0 || DepositDeadline::forReservation($locked)->lte(now())) {
                throw new InvalidPaymentAmountException('Check any receipts that are still under review first. You can ask for an additional deposit only if money is still due and the deadline has not passed.');
            }
            $locked->update(['deposit_due_at' => DepositDeadline::forReservation($locked)]);
            $message = 'Please pay the remaining deposit of '.Money::of($locked->getDepositAvailableAmount()).' by '.$locked->deposit_due_at->copy()->timezone(ResortTime::timezone())->format('M d, Y g:i A').' Philippine time. '.$reason;
            activity()->performedOn($locked)->causedBy($actor)->event('deposit_top_up_requested')->withProperties(['reason' => $reason])->log($message);
            DB::afterCommit(fn () => $locked->user?->notify(new DepositUpdateNotification($locked->id, $message)));
        }, 5);
    }

    private function overpaymentCents(Reservation $reservation, Payment $payment): int
    {
        return max(0, (int) round((float) $payment->amount * 100)
            - (int) round($reservation->getRemainingBalance() * 100));
    }

    public function settleGroupBalance(\App\Models\ReservationGroup $group, User $actor, array $data): void
    {
        DB::transaction(function () use ($group, $actor, $data) {
            $group = $group->newQuery()->lockForUpdate()->findOrFail($group->id);
            if (! $group->isApproved() || isset($group->booking_workflow['refund']) || $group->pendingPaymentsTotal() > 0
                || ($group->booking_workflow['child_charge']['status'] ?? null) === 'disputed') {
                throw new InvalidPaymentAmountException('Review the booking and any pending proof before recording payment.');
            }
            $due = $group->outstandingBalance();
            if ($due <= 0 || round($data['amount'] * 100) !== round($due * 100)) {
                throw new InvalidPaymentAmountException('The group balance has changed. Review the current amount before recording payment.');
            }

            $lines = $group->reservations()->orderBy('id')->lockForUpdate()->get();
            $balances = $lines->mapWithKeys(fn ($line) => [$line->id => $line->getRemainingBalance()]);
            if ((int) round($balances->sum() * 100) !== (int) round($due * 100)) {
                throw new InvalidPaymentAmountException('Reconcile the service balances before recording this group payment.');
            }
            foreach ($lines as $line) {
                if ($balances[$line->id] > 0) {
                    $this->recordOnSite($line, $actor, ['amount' => $balances[$line->id]] + $data);
                }
            }
        }, 5);
    }

    public function recordOnSite(Reservation $reservation, User $actor, array $data): Payment
    {
        if (! $actor->hasRole('Administrator')) {
            throw new UnauthorizedPaymentActionException('Only administrators can record collected payments.');
        }

        return DB::transaction(function () use ($reservation, $actor, $data) {
            $locked = $this->lockReservationLedger($reservation->id);
            $amount = (float) $data['amount'];
            if (! is_finite($amount) || round($amount * 100) < 1) {
                throw new InvalidPaymentAmountException('Enter a positive amount.');
            }
            if ($locked->getPendingVerificationTotal() > 0) {
                throw new InvalidPaymentAmountException('Resolve pending proofs before recording another payment.');
            }
            if (($amount > $locked->getRemainingBalance() || $locked->status !== ReservationStatus::Approved) && blank($data['reconciliation_note'] ?? null)) {
                throw new InvalidPaymentAmountException('Enter a reconciliation note for excess funds or a payment on an unconfirmed reservation.');
            }
            $payment = $locked->payments()->create([
                'amount' => number_format($amount, 2, '.', ''), 'method' => $data['method'],
                'reference_number' => isset($data['reference_number']) ? strtoupper(trim($data['reference_number'])) : null,
                'payment_date' => Carbon::parse($data['collected_at'])->timezone(ResortTime::timezone())->toDateString(),
                'collected_at' => $data['collected_at'], 'source' => 'onsite', 'status' => PaymentStatus::Verified,
                'verified_by' => $actor->id, 'verified_at' => now(), 'reconciliation_note' => $data['reconciliation_note'] ?? null,
            ]);
            $this->queueReceipt($payment, $locked->user);
            activity()->performedOn($payment)->causedBy($actor)->event('onsite_payment_recorded')->log('Payment received at the resort.');
            $locked->unsetRelation('payments');
            // Recording money no longer confirms a booking either: approval is a
            // decision staff make on the reservation, and it is what tells the
            // guest the 50% QR step is open. A cash entry is not that decision.
            DB::afterCommit(fn () => $locked->user?->notify(new DepositUpdateNotification($locked->id, 'The resort recorded a payment you made on site. You can see it in your payment history.')));

            return $payment;
        }, 5);
    }
}
