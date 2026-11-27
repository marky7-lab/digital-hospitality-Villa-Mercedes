<x-customer-layout>
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 pt-28 pb-16" x-data="cottageBooking()">
        <a href="{{ route('customer.reservations.new', ['type' => 'cottage']) }}" class="text-sm font-semibold text-primary hover:underline">← Choose another cottage</a>
        <div class="mt-5 rounded-3xl border border-border bg-surface shadow-lg overflow-hidden">
            <div class="bg-primary px-6 py-7 sm:px-8 text-white">
                <p class="text-sm font-semibold uppercase tracking-wider text-white/70">Your selected cottage</p>
                <h1 class="mt-2 font-heading text-3xl sm:text-4xl font-semibold">{{ $facility->name }}</h1>
                <p class="mt-2 text-white/80">Cottage fee is based on the selected session · pool entrance is paid separately when you arrive.</p>
            </div>

            <div class="p-6 sm:p-8">
                {{--
                    No flash alerts here: `layouts/customer` already prints the
                    session message above the page, which is the one place a
                    submission's outcome is announced. These two used to repeat
                    it through an alert component that takes its text in a slot,
                    not a `message` attribute — so `:message="session(...)"` was
                    ignored and both boxes rendered empty, on every visit, with
                    a title promising a problem and a success that had not
                    happened.
                --}}
                @if($errors->any())
                    <div id="cottage-validation-summary" role="alert" tabindex="-1" x-init="$nextTick(() => $el.focus())" class="mb-6 rounded-xl border border-danger bg-danger-light px-4 py-3 text-sm text-danger">
                        <p class="font-semibold">Review your cottage booking details.</p>
                        <p class="mt-1">Fix the following {{ Str::plural('detail', $errors->count()) }} and try again.</p>
                        <ul class="mt-2 list-disc space-y-1 pl-5">
                            @foreach($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <section aria-labelledby="visit-heading">
                    <h2 id="visit-heading" class="font-heading text-2xl font-semibold text-text">1. Choose your visit</h2>
                    <div class="mt-5 grid gap-5 sm:grid-cols-2">
                        <div>
                            <x-input-label for="visit_date" value="Visit date" />
                            <x-text-input id="visit_date" type="date" x-model="visitDate" @change="available = false" min="{{ now()->toDateString() }}" class="mt-2 block w-full" required />
                        </div>
                        <div>
                            <x-input-label for="swimming_session_id" value="Swimming session" />
                            <select id="swimming_session_id" x-model="sessionId" @change="available = false" class="mt-2 block w-full rounded-lg border-border focus:border-primary focus:ring-primary" required>
                                @foreach($sessions as $session)
                                    <option value="{{ $session->id }}">
                                        {{ $session->name }} ({{ \Carbon\Carbon::parse($session->starts_at)->format('g:i A') }}–{{ \Carbon\Carbon::parse($session->ends_at)->format('g:i A') }})
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <x-input-label for="guest_count" value="Expected visitors" />
                            <x-text-input id="guest_count" type="number" x-model.number="guestCount" @input="available = false" min="1" :max="$facility->maxGuestCount()" class="mt-2 block w-full" required />
                        </div>
                    </div>

                    <template x-if="selectedSession && !selectedSession.children_allowed">
                        <div class="mt-5 rounded-xl border border-warning bg-warning-light p-4 text-sm text-warning">
                            <strong>Night-swimming rule:</strong> Children are not allowed to swim during this session.
                        </div>
                    </template>

                    <div class="mt-5 rounded-xl bg-background p-4 text-sm text-text-muted">
                        <p><strong class="text-text">Cottage:</strong> <span x-text="formatCottageFee(selectedSession?.cottage_fee)"></span> for this session.</p>
                        <p class="mt-1"><strong class="text-text">Pool entrance:</strong> paid separately at the entrance for each swimmer; it is not included in this reservation.</p>
                    </div>

                    <button type="button" @click="checkAvailability" :disabled="loading" class="mt-5 inline-flex rounded-lg bg-primary px-5 py-3 font-semibold text-white hover:bg-primary-hover disabled:opacity-60">
                        <span x-text="loading ? 'Checking…' : 'Check cottage availability'"></span>
                    </button>
                    <p x-show="error" x-cloak class="mt-4 rounded-lg bg-danger-light px-4 py-3 text-sm text-danger" x-text="error"></p>
                    <p x-show="available" x-cloak class="mt-4 rounded-lg bg-success-light px-4 py-3 text-sm font-semibold text-success">This cottage is available. Complete the guest details below to request it.</p>
                </section>

                <form x-show="available" x-cloak method="POST" action="{{ route('customer.reservations.store') }}" class="mt-10 border-t border-border pt-8 space-y-6">
                    @csrf
                    <input type="hidden" name="facility_id" value="{{ $facility->id }}">
                    <input type="hidden" name="visit_date" :value="visitDate">
                    <input type="hidden" name="swimming_session_id" :value="sessionId">
                    <input type="hidden" name="check_in" :value="checkIn">
                    <input type="hidden" name="check_out" :value="checkOut">
                    <input type="hidden" name="guestCount" :value="guestCount">
                    <input type="hidden" name="early_check_in_hours" value="0">
                    <input type="hidden" name="late_check_out_hours" value="0">

                    <h2 class="font-heading text-2xl font-semibold text-text">2. Contact details</h2>
                    <p class="text-sm text-text-muted">Name, mobile number, and email are enough to request this cottage. Identification details can be completed at the entrance.</p>
                    <div class="grid gap-5 sm:grid-cols-2">
                        <div><x-input-label for="guest_name" value="Full name" /><x-text-input id="guest_name" name="guest_name" value="{{ old('guest_name', auth()->user()?->name) }}" class="mt-2 block w-full" required maxlength="255" autocomplete="name" oninput="this.value = this.value.replace(/[^\p{L}\p{M} .'’\-]/gu, '')" /></div>
                        <div><x-input-label for="guest_phone" value="Mobile number" /><x-text-input id="guest_phone" name="guest_phone" type="tel" value="{{ old('guest_phone', auth()->user()?->phone) }}" class="mt-2 block w-full" required autocomplete="tel" inputmode="numeric" pattern="09[0-9]{9}" minlength="11" maxlength="11" placeholder="09171234567" title="Enter exactly 11 digits beginning with 09." data-ph-mobile /></div>
                        <div class="sm:col-span-2"><x-input-label for="guest_email" value="Email address" /><x-text-input id="guest_email" name="guest_email" type="email" value="{{ old('guest_email', auth()->user()?->email) }}" class="mt-2 block w-full" required /></div>
                    </div>

                    <div>
                        <x-input-label for="special_requests" value="Preferences & additional requests (optional)" />
                        <textarea id="special_requests" name="special_requests" rows="3" maxlength="1000"
                                  class="mt-2 border-border focus:border-primary focus:ring-primary rounded-lg shadow-sm block w-full"
                                  placeholder="Anything staff should know for your visit?">{{ old('special_requests') }}</textarea>
                        <p class="mt-1 text-xs text-text-muted">Avoid sharing medical details unless needed to arrange a requested accommodation.</p>
                        <x-input-error class="mt-1" :messages="$errors->get('special_requests')" />
                    </div>

                    <div class="rounded-xl border border-border bg-background/60 px-4 py-3">
                        <div class="flex items-start gap-3 text-sm text-text">
                            <input id="policies_accepted" type="checkbox" name="policies_accepted" value="1" @checked(old('policies_accepted')) required
                                   class="mt-1 rounded border-border text-primary focus:ring-primary">
                            <p>
                                <label for="policies_accepted" class="cursor-pointer">I agree to the</label>
                                <a href="{{ route('terms') }}" target="_blank" rel="noopener noreferrer" class="font-semibold text-primary hover:underline">resort policies and rescheduling terms</a>.
                            </p>
                        </div>
                        <x-input-error class="mt-1" :messages="$errors->get('policies_accepted')" />
                    </div>

                    <div class="rounded-xl border border-border bg-background p-5">
                        <h3 class="font-semibold text-text">Reservation summary</h3>
                        <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                            <div><dt class="text-text-muted">Cottage</dt><dd class="font-semibold">{{ $facility->name }}</dd></div>
                            <div><dt class="text-text-muted">Session</dt><dd class="font-semibold" x-text="selectedSession?.name"></dd></div>
                            <div><dt class="text-text-muted">Cottage total</dt><dd class="font-semibold text-primary" x-text="formatCottageFee(quotedTotal)"></dd></div>
                            <div><dt class="text-text-muted">Pool entrance</dt><dd class="font-semibold">Pay at entrance</dd></div>
                        </dl>
                    </div>

                    <button type="submit" class="inline-flex w-full justify-center rounded-lg bg-primary px-5 py-3 font-semibold text-white hover:bg-primary-hover">
                        Submit cottage reservation
                    </button>
                </form>
            </div>
        </div>
    </div>

    @php
        $cottageBookingConfig = [
            'visitDate' => old('visit_date', $visitDate),
            'sessionId' => (string) old('swimming_session_id', $selectedSession?->id),
            'guestCount' => (int) old('guestCount', max(1, (int) request('guests', 1))),
            'available' => $errors->any(),
            'continueAfterAvailability' => request('availability_checked') === '1',
            'calculateUrl' => route('customer.booking.calculate', $facility),
            'csrfToken' => csrf_token(),
            'sessions' => $sessions->map(fn ($session) => [
                'id' => (string) $session->id,
                'name' => $session->name,
                'children_allowed' => $session->children_allowed,
                'cottage_fee' => (float) $session->cottage_fee,
            ])->values(),
        ];
    @endphp
    @push('scripts')
        <script type="application/json" id="cottage-booking-config">@json($cottageBookingConfig)</script>
        <script>
            document.addEventListener('alpine:init', () => {
                const config = JSON.parse(document.getElementById('cottage-booking-config').textContent);

                Alpine.data('cottageBooking', () => ({
                    visitDate: config.visitDate,
                    sessionId: config.sessionId,
                    guestCount: Number(config.guestCount),
                    loading: false,
                    available: Boolean(config.available),
                    error: '',
                    checkIn: '',
                    checkOut: '',
                    quotedTotal: 0,
                    sessions: config.sessions,
                    init() {
                        if (config.continueAfterAvailability && this.visitDate && this.sessionId) {
                            this.$nextTick(() => this.checkAvailability());
                        }
                    },
                    get selectedSession() {
                        return this.sessions.find((session) => session.id === String(this.sessionId));
                    },
                    // The cottage fee arrives in pesos, so it uses window.pesos
                    // (resources/js/money.js), the same format the summary and the
                    // invoice render. Not Intl currency: that decides the symbol's
                    // own spacing and would drift from the rest of the app.
                    formatCottageFee(amount) {
                        return window.pesos(amount);
                    },
                    async checkAvailability() {
                        this.loading = true;
                        this.available = false;
                        this.error = '';
                        try {
                            const response = await fetch(config.calculateUrl, {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'Accept': 'application/json',
                                    'X-CSRF-TOKEN': config.csrfToken,
                                },
                                body: JSON.stringify({
                                    visit_date: this.visitDate,
                                    swimming_session_id: this.sessionId,
                                    guestCount: this.guestCount,
                                }),
                            });
                            const data = await response.json();
                            if (! response.ok || ! data.success) {
                                throw new Error(data.message || 'This cottage is not available.');
                            }
                            this.checkIn = data.data.check_in;
                            this.checkOut = data.data.check_out;
                            this.quotedTotal = data.data.total_amount / 100;
                            this.available = true;
                        } catch (error) {
                            this.error = error.message;
                        } finally {
                            this.loading = false;
                        }
                    },
                }));
            });
        </script>
    @endpush
</x-customer-layout>
