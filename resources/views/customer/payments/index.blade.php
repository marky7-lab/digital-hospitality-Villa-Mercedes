<x-customer-layout>
    @php
        $remaining = $reservation->getRemainingBalance();
        $availableToSubmit = config('payments.deposit_workflow') && $reservation->policy_version !== config('resort.policy.confirmed_version') ? $reservation->getDepositAvailableAmount() : $reservation->getAvailablePaymentAmount();
        $paymentState = $reservation->guestPaymentState();
        $canPay = $reservation->canAcceptGuestPayment();
        $pendingVerification = $reservation->getPendingVerificationTotal();
    @endphp

    <div class="py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <x-customer.page-header
                :breadcrumbs="[
                    ['label' => 'My reservations', 'href' => route('customer.reservations.index', request()->only('tab', 'page'))],
                    ['label' => $reservation->reservation_code, 'href' => route('customer.reservations.show', [$reservation, ...request()->only('tab', 'page')])],
                    ['label' => 'Payments'],
                ]"
                eyebrow="Secure payment"
                title="Deposit & payment history"
                :description="$reservation->facility->name"
            />

            <x-customer.reservation-tabs :reservation="$reservation" />
            @include('customer.reservations.partials.booking-discussions', ['reservation' => $reservation])

            @if ($errors->any())
                <x-alert type="danger" title="There were errors with your submission">
                    <ul class="list-disc pl-5 space-y-1">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </x-alert>
            @endif

            {{--
                One notice per event: the layout already prints the flash message
                at the top of the page. This block used to print it a second time
                with a different title, so a single failed submission produced two
                alerts saying the same thing and a guest had to work out whether
                they were two problems or one.
            --}}
            @if($paymentState === \App\Enums\GuestPaymentState::AwaitingApproval)
                <x-alert type="info" :title="config('payments.deposit_workflow') ? 'Review pending' : 'Waiting for resort approval'">
                    @if(config('payments.deposit_workflow'))
                        Your request is with the resort for review and payment verification. We email you the moment staff review your submission.
                    @else
                        This request requires staff assistance. Review its status or contact the resort before transferring funds.
                    @endif
                </x-alert>
            @elseif($paymentState === \App\Enums\GuestPaymentState::ReceiptPending)
                <x-alert type="info" title="Receipt awaiting verification">
                    Staff are checking your transfer. An uploaded receipt does not confirm payment. Any deposit shortfall will be shown below.
                </x-alert>
            @elseif($paymentState === \App\Enums\GuestPaymentState::Closed)
                <x-alert type="info" title="Payment is closed for this reservation">
                    This reservation can no longer accept new payments.
                </x-alert>
            @endif

            <x-payment-overpayment :reservation="$reservation" />

            <div class="bg-surface border border-border rounded-2xl shadow-sm overflow-hidden">
                <div class="px-5 py-4 sm:px-6 border-b border-border flex justify-between items-center gap-3">
                    <div>
                        <h2 class="font-heading text-2xl font-semibold text-text">{{ $reservation->reservation_code }}</h2>
                        <p class="mt-1 text-sm text-text-muted">{{ $reservation->facility->name }}</p>
                    </div>
                    <x-badge :status="$reservation->status" />
                </div>
                <div class="px-5 py-5 sm:p-6 bg-background">
                    <dl class="grid grid-cols-1 gap-x-4 gap-y-6 sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-text-muted">Total</dt>
                            <dd class="mt-1 text-lg font-medium text-text"><x-amount :value="$reservation->total_price" /></dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-text-muted">Verified paid</dt>
                            <dd class="mt-1 text-lg font-medium text-success"><x-amount :value="$reservation->getVerifiedTotalPaid()" /></dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-text-muted">Awaiting verification</dt>
                            <dd class="mt-1 text-lg font-medium text-text"><x-amount :value="$pendingVerification" /></dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-text-muted">Remaining balance</dt>
                            @if($remaining > 0)
                                <dd class="mt-1 font-heading text-3xl font-semibold text-primary"><x-amount :value="$remaining" /></dd>
                            @else
                                <dd class="mt-1 font-heading text-2xl font-semibold text-success">Fully paid</dd>
                            @endif
                        </div>
                    </dl>
                </div>
            </div>

            <div class="space-y-6">
                @if($canPay && config('payments.deposit_workflow'))
                    @include('customer.payments.deposit')
                @endif

                @if($canPay && $onlineCheckoutAvailable)
                    <div class="bg-surface border border-border rounded-2xl shadow-sm overflow-hidden">
                        <div class="px-5 py-4 sm:px-6 border-b border-border">
                            <h2 class="font-heading text-2xl font-semibold text-text">Pay online</h2>
                            <p class="mt-1 text-sm text-text-muted">
                                {{ $gatewayLabel }}. Recorded as paid only after provider verification &mdash; no proof to upload. You are only charged the amount you enter.
                            </p>
                        </div>
                        <div class="px-5 py-5 sm:p-6">
                            <form action="{{ route('customer.payments.checkout', $reservation) }}" method="POST" class="space-y-4" x-data="{ isRedirecting: false }" @submit="isRedirecting = true">
                                @csrf

                                <div>
                                    <x-input-label for="online_amount" value="Amount (₱)" />
                                    <x-text-input type="number" step="0.01" min="1" max="{{ $availableToSubmit }}"
                                           name="amount" id="online_amount" required
                                           value="{{ old('amount', $availableToSubmit) }}"
                                           class="mt-1 block w-full" />
                                </div>

                                <div>
                                    <x-input-label for="online_method" value="E-wallet" />
                                    <select name="payment_method" id="online_method" required
                                            class="mt-1 block w-full rounded-md border-border text-text focus:border-primary focus:ring-primary">
                                        <option value="{{ \App\Enums\PaymentMethod::GCash->value }}">GCash</option>
                                        <option value="{{ \App\Enums\PaymentMethod::Maya->value }}">Maya</option>
                                    </select>
                                </div>

                                <x-primary-button type="submit" class="w-full justify-center !py-3" x-bind:disabled="isRedirecting">
                                    <span x-show="!isRedirecting">Continue to secure checkout</span>
                                    <span x-show="isRedirecting" x-cloak>Redirecting&hellip;</span>
                                </x-primary-button>

                                <p class="text-xs text-text-muted">
                                    You will be taken to the payment provider and returned here once done.
                                </p>
                            </form>
                        </div>
                    </div>
                @endif

                @if($canPay && ! config('payments.deposit_workflow'))
                    <div class="bg-surface border border-border rounded-2xl shadow-sm overflow-hidden">
                        <div class="px-5 py-4 sm:px-6 border-b border-border">
                            <h2 class="font-heading text-2xl font-semibold text-text">Submit a receipt</h2>
                            <p class="mt-1 text-sm text-text-muted">
                                Send the amount to a posted account below, then upload the receipt for staff to verify. Staff usually review receipts within one business day. You are only submitting the amount you enter.
                            </p>
                        </div>
                        <div class="px-5 py-5 sm:p-6" x-data="{ isSubmitting: false }">
                            @php
                                $recipients = collect(config('payments.recipients', []))
                                    ->filter(fn ($recipient) => filled($recipient['account_number'] ?? null));
                            @endphp
                            @if($recipients->isNotEmpty())
                                <div class="mb-6 rounded-xl border border-border bg-background p-4">
                                    <p class="text-sm font-semibold text-text">Send payment to</p>
                                    <dl class="mt-3 space-y-3 text-sm">
                                        @foreach($recipients as $recipient)
                                            <div>
                                                <dt class="text-text-muted">{{ $recipient['method'] }}@if(! empty($recipient['bank'])) · {{ $recipient['bank'] }}@endif</dt>
                                                <dd class="mt-0.5 font-medium text-text">
                                                    {{ $recipient['account_name'] }}
                                                    <span class="block font-mono">{{ $recipient['account_number'] }}</span>
                                                </dd>
                                            </div>
                                        @endforeach
                                    </dl>
                                </div>
                            @endif
                            <form action="{{ route('customer.payments.store', $reservation) }}" method="POST" enctype="multipart/form-data" @submit="isSubmitting = true">
                                @csrf

                                <div class="space-y-6">
                                    <div>
                                        <x-input-label for="amount" value="Amount (₱)" />
                                        <x-text-input type="number" step="0.01" min="0.01" max="{{ $availableToSubmit }}" name="amount" id="amount"
                                               class="mt-1 block w-full"
                                               value="{{ old('amount', $availableToSubmit) }}" required />
                                    </div>

                                    <div>
                                        <x-input-label for="payment_method" value="Payment method" />
                                        <select id="payment_method" name="payment_method" required class="mt-1 block w-full rounded-md border-border text-text focus:border-primary focus:ring-primary">
                                            <option value="" disabled selected>Select a method</option>
                                            @foreach(\App\Enums\PaymentMethod::cases() as $method)
                                                <option value="{{ $method->value }}" @selected(old('payment_method') === $method->value)>{{ $method->label() }}</option>
                                            @endforeach
                                        </select>
                                    </div>


                                    <div>
                                        <x-input-label for="proof" value="Proof of payment" />
                                        <x-customer.payment-proof-input />
                                    </div>

                                    <x-primary-button type="submit" class="w-full justify-center !py-3" x-bind:disabled="isSubmitting">
                                        <span x-show="!isSubmitting">Submit receipt for verification</span>
                                        <span x-show="isSubmitting" x-cloak class="flex items-center">
                                            <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                            </svg>
                                            Submitting...
                                        </span>
                                    </x-primary-button>
                                </div>
                            </form>
                        </div>
                    </div>
                @elseif(! ($canPay && config('payments.deposit_workflow')))
                    <div class="bg-surface border border-border rounded-2xl shadow-sm p-6 text-center">
                        @if($paymentState === \App\Enums\GuestPaymentState::AwaitingApproval)
                            <h2 class="font-heading text-2xl font-semibold text-text">Waiting for resort approval</h2>
                            <p class="mt-2 text-sm text-text-muted">
                                Contact staff to review this request. Do not transfer money without current payment instructions.
                            </p>
                        @elseif($paymentState === \App\Enums\GuestPaymentState::Closed)
                            <h2 class="font-heading text-2xl font-semibold text-text">Payment closed</h2>
                            <p class="mt-2 text-sm text-text-muted">
                                This reservation can no longer accept new payments.
                            </p>
                        @elseif($paymentState === \App\Enums\GuestPaymentState::ReceiptPending)
                            <h2 class="font-heading text-2xl font-semibold text-text">Awaiting verification</h2>
                            <p class="mt-2 text-sm text-text-muted">Your proof is under review. Payment is only confirmed after staff verify the incoming funds.</p>
                        @elseif($paymentState === \App\Enums\GuestPaymentState::OnSiteBalance)
                            <h2 class="font-heading text-2xl">Balance payable at check in</h2>
                            <p class="mt-2">Remaining balance payable at the resort upon check in. Staff will record the payment in this history.</p>
                        @elseif($canPay)
                            <p>Use the QR deposit instructions above. The remaining balance is paid at check in.</p>
                        @else
                            <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-success-light mb-4">
                                <svg class="h-6 w-6 text-success" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                </svg>
                            </div>
                            <h2 class="font-heading text-2xl font-semibold text-text">Fully paid</h2>
                            <p class="mt-2 text-sm text-text-muted">
                                Thank you. This reservation has no remaining balance.
                            </p>
                        @endif
                    </div>
                @endif

                <div class="bg-surface border border-border rounded-2xl shadow-sm overflow-hidden">
                    <div class="px-5 py-4 sm:px-6 border-b border-border">
                        <h2 class="font-heading text-2xl font-semibold text-text">Payment history</h2>
                    </div>

                    @if($payments->isEmpty())
                        <div class="p-6">
                            <x-empty-state icon="cash" title="No payments yet" description="You have not submitted any payments for this reservation." />
                        </div>
                    @else
                        <div class="payment-history">
                            <table class="min-w-full divide-y divide-border">
                                <thead class="bg-background">
                                    <tr>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-text-muted">Date</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-text-muted">Amount</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-text-muted">Method &amp; ref</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-text-muted">Status</th>
                                        <th scope="col" class="relative px-6 py-3">
                                            <span class="sr-only">Actions</span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="bg-surface divide-y divide-border">
                                    @foreach($payments as $payment)
                                        <tr>
                                            <td data-label="Date" class="px-6 py-4 whitespace-nowrap text-sm text-text-muted">
                                                {{ $payment->created_at->format('M d, Y h:i A') }}
                                            </td>
                                            <td data-label="Amount" class="px-6 py-4 whitespace-nowrap text-sm font-medium text-text">
                                                <x-amount :value="$payment->amount" />
                                            </td>
                                            <td data-label="Method & reference" class="px-6 py-4 whitespace-nowrap text-sm text-text-muted">
                                                <div class="font-medium text-text">{{ $payment->method->label() }}</div>
                                                @if($payment->source === 'onsite')
                                                    <div class="text-xs">Recorded at resort by {{ $payment->verifiedBy?->name ?? 'staff' }}</div>
                                                    <div class="text-xs">{{ $payment->collected_at?->copy()->timezone('Asia/Manila')->format('M d, Y g:i A') }} Philippine time</div>
                                                @endif
                                                @if($payment->reference_number)
                                                    <div class="text-xs">Ref: {{ $payment->reference_number }}</div>
                                                @endif
                                            </td>
                                            <td data-label="Status" class="px-6 py-4 whitespace-nowrap">
                                                <x-badge :status="$payment->status" />
                                                @if($payment->status === \App\Enums\PaymentStatus::Rejected && $payment->rejection_reason)
                                                    <div class="mt-1 text-xs text-danger max-w-xs whitespace-normal break-words" title="{{ $payment->rejection_reason }}">
                                                        {{ $payment->rejection_reason }}
                                                    </div>
                                                @endif
                                                @if($payment->status === \App\Enums\PaymentStatus::Pending)
                                                    <div class="mt-1 text-xs text-text-muted">
                                                        Under review — not yet verified paid.
                                                    </div>
                                                @endif
                                                @if($payment->status === \App\Enums\PaymentStatus::Verified && $payment->verified_at)
                                                    <div class="mt-1 text-xs text-success">
                                                        Verified on {{ $payment->verified_at->format('M d, Y') }}
                                                    </div>
                                                @endif
                                            </td>
                                            <td data-label="Receipt" class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                                @if($payment->status === \App\Enums\PaymentStatus::Verified)
                                                    <a href="{{ route('customer.payments.receipt', $payment) }}" class="block mb-2 text-primary hover:underline">Itemized payment receipt</a>
                                                @endif
                                                @if(! $payment->proof_path)
                                                    <span class="text-xs text-text-muted">No receipt attached</span>
                                                @else
                                                <a href="{{ route('customer.payments.view', $payment) }}" target="_blank" rel="noopener" class="text-primary hover:underline inline-flex items-center">
                                                    <svg class="mr-1 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                                    </svg>
                                                    View proof
                                                </a>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>

        </div>
    </div>
</x-customer-layout>
