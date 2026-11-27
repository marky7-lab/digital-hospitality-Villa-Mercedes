<?php

namespace App\Http\Controllers\Customer;

use App\Enums\PaymentMethod;
use App\Enums\ReservationStatus;
use App\Exceptions\Payment\InvalidPaymentAmountException;
use App\Exceptions\Payment\PaymentGatewayException;
use App\Exceptions\Payment\PaymentNotEligibleException;
use App\Exceptions\Payment\PaymentOwnershipException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\StartOnlinePaymentRequest;
use App\Http\Requests\Customer\StorePaymentRequest;
use App\Models\Payment;
use App\Models\Reservation;
use App\Services\Customer\CustomerPaymentService;
use App\Services\FileUploadService;
use App\Services\Payments\OnlinePaymentService;
use App\Support\Money;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerPaymentController extends Controller
{
    public function __construct(
        private readonly CustomerPaymentService $paymentService,
        private readonly FileUploadService $fileUploadService,
        private readonly OnlinePaymentService $onlinePaymentService
    ) {}

    public function index(Request $request, Reservation $reservation): View
    {
        $payments = $this->paymentService->getPaymentsForReservation($request->user(), $reservation);

        return view('customer.payments.index', [
            'reservation' => $reservation,
            'payments' => $payments,
            'onlineCheckoutAvailable' => $this->onlinePaymentService->isAvailable(),
            'gatewayLabel' => $this->onlinePaymentService->gatewayLabel(),
            'destinations' => \App\Models\PaymentDestination::where('is_active', true)->get(),
            'proofDestinations' => \App\Models\PaymentDestination::where('is_active', true)->orWhere('updated_at', '>=', $reservation->created_at)->get(),
        ]);
    }

    public function groupIndex(Request $request, \App\Models\ReservationGroup $group): View
    {
        abort_unless($group->user_id === $request->user()->id, 403);
        $group->load(['reservations' => fn ($query) => $query->where('user_id', $request->user()->id)->with('facility')]);
        $payments = Payment::query()->where(function ($query) use ($group) {
            $query->whereIn('reservation_id', $group->reservations->modelKeys())
                ->orWhere(fn ($direct) => $direct->where('reservation_group_id', $group->id)->whereNull('reservation_id'));
        })->with('verifiedBy')->orderByDesc('created_at')->orderByDesc('id')->get();

        return view('customer.payments.group', compact('group', 'payments'));
    }

    public function store(StorePaymentRequest $request, Reservation $reservation): RedirectResponse
    {
        abort_unless($reservation->user_id === $request->user()->id, 403);
        // 1. Store the uploaded proof
        $fileInfo = $this->fileUploadService->store($request->file('proof'));
        $proofPath = $fileInfo['path'];

        try {
            // 2. Delegate to service to create payment
            $payment = $this->paymentService->submitPayment(
                $request->user(),
                $reservation,
                $request->validated('amount'),
                PaymentMethod::from($request->validated('payment_method')),
                $proofPath,
                [...$request->validated(), 'proof_hash' => hash_file('sha256', $request->file('proof')->getRealPath())]
            );

            return redirect()->route('customer.reservations.index', ['tab' => $reservation->fresh()->status === ReservationStatus::Pending ? 'pending' : 'upcoming'])
                ->with(
                    'success',
                    'Proof for '.Money::of($payment->amount).' was submitted. The admin will review the transfer. It appears as Under review until verified; Verified paid and the final balance update after approval.'
                );

        } catch (PaymentOwnershipException $e) {
            $this->fileUploadService->delete($proofPath);
            abort(403, $e->getMessage());
        } catch (InvalidPaymentAmountException|PaymentNotEligibleException $e) {
            $this->fileUploadService->delete($proofPath);

            return redirect()->route('customer.payments.index', $reservation)
                ->with('error', $e->getMessage());
        } catch (Exception $e) {
            // A post-commit notification can fail after the ledger was saved.
            // Retain the private proof when persistence is uncertain.
            report($e);
            return redirect()->route('customer.reservations.show', $reservation)
                ->with('error', 'We couldn’t update this page. Check your payment history or contact the resort before uploading your receipt again. Please don’t send another payment.');
        }
    }

    public function checkout(StartOnlinePaymentRequest $request, Reservation $reservation): RedirectResponse
    {
        try {
            $url = $this->onlinePaymentService->startCheckout(
                $request->user(),
                $reservation,
                (float) $request->validated('amount'),
                PaymentMethod::from($request->validated('payment_method'))
            );

            return redirect()->away($url);
        } catch (PaymentOwnershipException $e) {
            abort(403, $e->getMessage());
        } catch (InvalidPaymentAmountException|PaymentNotEligibleException|PaymentGatewayException $e) {
            return redirect()->route('customer.payments.index', $reservation)
                ->with('error', $e->getMessage());
        }
    }

    /**
     * Landing point after the guest finishes at the provider. The provider's own
     * API is the source of truth here, not this redirect.
     */
    public function returnFromGateway(Request $request, Payment $payment): RedirectResponse
    {
        $reservation = $payment->reservation;

        try {
            $paid = $this->onlinePaymentService->confirm($request->user(), $payment);
        } catch (PaymentOwnershipException $e) {
            abort(403, $e->getMessage());
        } catch (PaymentGatewayException|\App\Exceptions\Payment\InvalidPaymentTransitionException $e) {
            return redirect()->route('customer.payments.index', $reservation)
                ->with('error', $e->getMessage().' Your payment may still be processing. Refresh this page in a few minutes.');
        }

        return redirect()->route('customer.payments.index', $reservation)
            ->with(
                $paid ? 'success' : 'error',
                $paid
                    ? 'Payment received. Your reservation ledger has been updated.'
                    : 'We have not received a confirmation from the payment provider yet. It may still be processing.'
            );
    }

    public function cancelGateway(Request $request, Payment $payment): RedirectResponse
    {
        $reservation = $payment->reservation;

        try {
            $this->onlinePaymentService->abandon($request->user(), $payment);
        } catch (PaymentOwnershipException $e) {
            abort(403, $e->getMessage());
        } catch (PaymentGatewayException|\App\Exceptions\Payment\InvalidPaymentTransitionException $e) {
            return redirect()->route('customer.payments.index', $reservation)->with('error', $e->getMessage());
        }

        $recorded = Payment::withTrashed()->findOrFail($payment->id)->status === \App\Enums\PaymentStatus::Verified;

        return redirect()->route('customer.payments.index', $reservation)
            ->with($recorded ? 'success' : 'error', $recorded
                ? 'Payment received. Your reservation ledger has been updated.'
                : 'Checkout was left before payment confirmation. Check your payment account before trying again.');
    }

    public function receipt(Request $request, Payment $payment): View
    {
        $reservation = $payment->reservation;
        $group = $payment->reservation_group_id ? $payment->reservationGroup : null;
        abort_unless($reservation ? $reservation->user_id === $request->user()->id : $group?->user_id === $request->user()->id, 403);
        abort_unless($payment->status === \App\Enums\PaymentStatus::Verified, 404, 'Payment proof is under review; a receipt is available only after verification.');
        $reservations = $reservation ? collect([$reservation]) : $group->reservations()->with('facility')->get();
        $verifiedPaid = $reservation ? $reservation->getVerifiedTotalPaid() : $group->verifiedPaymentsTotal();
        $pendingPaid = $reservation ? $reservation->getPendingVerificationTotal() : $group->pendingPaymentsTotal();
        $balance = $reservation ? $reservation->getRemainingBalance() : $group->outstandingBalance();

        return view('customer.payments.receipt', compact('payment', 'group', 'reservations', 'verifiedPaid', 'pendingPaid', 'balance'));
    }

    private function authorizePaymentDocument(Request $request, Payment $payment): void
    {
        if ($payment->reservation_id) {
            $this->paymentService->getPaymentsForReservation($request->user(), $payment->reservation);
        } else {
            abort_unless($payment->reservationGroup?->user_id === $request->user()->id, 403);
        }
        abort_unless(filled($payment->proof_path), 404);
    }

    public function viewProof(Request $request, Payment $payment): StreamedResponse
    {
        $this->authorizePaymentDocument($request, $payment);

        return $this->fileUploadService->view((string) $payment->proof_path);
    }

    public function downloadProof(Request $request, Payment $payment): StreamedResponse
    {
        // Ownership verification (throws exception if not owned)
        $this->authorizePaymentDocument($request, $payment);

        return $this->fileUploadService->download((string) $payment->proof_path);
    }
}
