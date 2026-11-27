<x-guest-layout :wide="$step === 'details'">
    <x-reservation-selection />
    <x-slot name="photo">
        <div class="relative flex flex-1 flex-col justify-between gap-8 p-5 sm:p-6 lg:p-10 xl:p-14 text-white">
            <a href="{{ route('home') }}" class="hidden lg:flex items-center gap-3 w-fit group">
                <img src="{{ asset('images/logo-villa-mercedes-mark.png') }}" alt="" class="h-11 w-11 rounded-xl object-contain bg-white/10 ring-1 ring-white/20">
                <span class="leading-none">
                    <span class="block font-heading text-lg font-semibold">{{ config('resort.short_name') }}</span>
                    <span class="mt-1 block text-[0.65rem] font-semibold uppercase tracking-[0.18em] text-white/70">Nature Farm &amp; Resort</span>
                </span>
            </a>

            <div class="max-w-md">
                <span class="block h-px w-12 bg-accent mb-6" aria-hidden="true"></span>
                <blockquote class="font-heading text-3xl sm:text-4xl xl:text-[2.6rem] leading-[1.15] font-semibold text-balance">
                    A peaceful nature farm stay in Ilagan.
                </blockquote>
                <p class="mt-5 text-sm sm:text-base text-white/75 leading-relaxed">
                    Create an account to reserve rooms, halls, and packages, and to pick up where you left off.
                </p>
                <p class="mt-4 text-xs text-white/60">{{ config('resort.location') }}</p>
            </div>

            <dl class="hidden sm:grid grid-cols-3 gap-5 max-w-lg">
                @foreach(array_slice(config('resort.stats'), 0, 3) as $stat)
                    <div class="text-center">
                        <dt class="font-heading text-2xl xl:text-3xl font-semibold text-accent-light">{{ $stat['value'] }}</dt>
                        <dd class="mt-1 text-xs text-white/65">{{ $stat['label'] }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
    </x-slot>

    <div @class(['mb-8' => $step !== 'details', 'mb-2' => $step === 'details'])>
        <p @class(['font-semibold uppercase tracking-[0.2em] text-accent', 'text-[0.7rem]' => $step === 'details', 'text-xs' => $step !== 'details'])>
            {{ $step === 'details' ? 'Step 2 of 2' : 'Create account' }}
        </p>
        <span @class(['block h-px w-10 bg-accent/70', 'mt-1' => $step === 'details', 'mt-2' => $step !== 'details']) aria-hidden="true"></span>
        <h2 @class(['font-heading font-semibold text-text', 'mt-1 text-2xl sm:text-3xl' => $step === 'details', 'mt-3 text-2xl sm:mt-4 sm:text-4xl' => $step !== 'details'])>
            @if ($step === 'otp')
                Enter your verification code
            @elseif ($step === 'details')
                Finish your account
            @else
                Create your account
            @endif
        </h2>
        <p @class(['text-text-muted', 'mt-1 text-sm' => $step === 'details', 'mt-3 text-sm' => $step !== 'details'])>
            @if ($step === 'details')
                Add your details and a password for your verified email.
            @elseif ($step === 'otp')
                Enter the code we sent to your email.
            @else
                We’ll check this email first. If it does not already have an account, we’ll send a verification code. We use this email only to verify your account and send reservation messages.
            @endif
            Already have an account?
            <a href="{{ route('login') }}" class="font-semibold text-primary hover:underline underline-offset-2">Sign in</a>.
        </p>
    </div>

    <x-auth-session-status class="mb-6" :status="session('status')" />

    @if ($step === 'email')
        <form method="POST" action="{{ route('register.otp.send') }}" class="space-y-5" x-data="{ isSubmitting: false }" @submit="isSubmitting = true">
            @csrf

            <div>
                <x-input-label for="email" :value="__('Email address')" class="text-xs font-semibold uppercase tracking-[0.16em] text-text-muted" />
                <div class="relative mt-2">
                    <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-text-muted">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2" /><path stroke-linecap="round" stroke-linejoin="round" d="m4 7 8 6 8-6" /></svg>
                    </span>
                    <x-text-input id="email" class="block w-full rounded-xl ps-11" type="email" name="email" :value="old('email', $pendingEmail)" required autofocus autocomplete="username" spellcheck="false" enterkeyhint="go" :aria-invalid="$errors->has('email') ? 'true' : null" :aria-describedby="$errors->has('email') ? 'registration-email-error' : null" placeholder="you@email.com" />
                </div>
                <x-input-error id="registration-email-error" :messages="$errors->get('email')" class="mt-2" />
                @if ($errors->first('email') === \App\Services\RegistrationOtpService::SET_PASSWORD)
                    <p class="mt-2 text-sm">
                        <a href="{{ route('password.request') }}" class="font-semibold text-primary hover:underline">Set a password</a>
                    </p>
                @endif
            </div>

            <x-primary-button class="w-full !rounded-full !py-3" x-bind:aria-busy="isSubmitting.toString()">
                <span x-show="!isSubmitting" class="inline-flex items-center gap-2">
                    {{ __('Send Verification Code') }}
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-6-6 6 6-6 6" /></svg>
                </span>
                <span x-show="isSubmitting" x-cloak>{{ __('Sending…') }}</span>
            </x-primary-button>
        </form>
    @elseif ($step === 'otp')
        <form method="POST" action="{{ route('register.otp.verify') }}" class="space-y-5"
              x-data="{
                  digits: {{ $otpDigits }},
                  values: Array({{ $otpDigits }}).fill(''),
                  isSubmitting: false,
                  get otp() { return this.values.join(''); },
                  fillCode(raw) {
                      const code = String(raw || '').replace(/\D/g, '').slice(0, this.digits);
                      if (!code) return;

                      this.values = Array.from({ length: this.digits }, (_, index) => code[index] || '');
                      this.$nextTick(() => {
                          const inputs = this.$refs.codeInputs.querySelectorAll('input');
                          inputs[Math.min(code.length, this.digits) - 1]?.focus();
                      });
                  },
                  enterDigit(event, index) {
                      const entered = String(event.target.value || '').replace(/\D/g, '');

                      if (entered.length > 1) {
                          this.fillCode(entered);
                          return;
                      }

                      this.values[index] = entered.slice(-1);
                      if (this.values[index] && index < this.digits - 1) {
                          this.$nextTick(() => event.target.nextElementSibling?.focus());
                      }
                  }
              }"
              @submit="isSubmitting = true">
            @csrf
            <input type="hidden" name="email" value="{{ old('email', $pendingEmail) }}">
            <input type="hidden" name="otp" x-bind:value="otp">

            <p class="text-sm text-text-muted">
                Code for <span class="font-semibold text-text">{{ $pendingEmail }}</span>
            </p>

            <div>
                <x-input-label :value="__('Verification code')" class="text-xs font-semibold uppercase tracking-[0.16em] text-text-muted" />
                <div class="mt-2 flex justify-between gap-2"
                     data-otp-inputs
                     x-ref="codeInputs"
                     @paste.prevent="fillCode($event.clipboardData?.getData('text') || '')">
                    @for ($i = 0; $i < $otpDigits; $i++)
                        <input type="text"
                               inputmode="numeric"
                               maxlength="1"
                               pattern="[0-9]"
                               required
                               autocomplete="{{ $i === 0 ? 'one-time-code' : 'off' }}"
                               aria-label="Verification code digit {{ $i + 1 }} of {{ $otpDigits }}"
                               class="h-12 w-11 rounded-xl border-border bg-surface text-center text-lg font-semibold text-text shadow-sm focus:border-primary focus:ring-primary"
                               x-model="values[{{ $i }}]"
                               @input="enterDigit($event, {{ $i }})"
                               @keydown.backspace="if (!values[{{ $i }}] && {{ $i }} > 0) $event.target.previousElementSibling?.focus()">
                    @endfor
                </div>
                <x-input-error :messages="$errors->get('otp')" class="mt-2" />
                <x-input-error id="registration-email-error" :messages="$errors->get('email')" class="mt-2" />
            </div>

            <x-primary-button class="w-full !rounded-full !py-3" x-bind:aria-busy="isSubmitting.toString()">
                <span x-show="!isSubmitting" class="inline-flex items-center gap-2">
                    {{ __('Verify Email') }}
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-6-6 6 6-6 6" /></svg>
                </span>
                <span x-show="isSubmitting" x-cloak>{{ __('Verifying…') }}</span>
            </x-primary-button>
        </form>

        <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
             x-data="{ remaining: {{ $resendAvailableIn }} }"
             x-init="if (remaining > 0) { const timer = setInterval(() => { remaining--; if (remaining <= 0) clearInterval(timer); }, 1000); }">
            <form method="POST" action="{{ route('register.otp.send') }}">
                @csrf
                <input type="hidden" name="email" value="{{ $pendingEmail }}">
                <button type="submit"
                        class="text-sm font-medium text-primary hover:underline underline-offset-2 disabled:text-text-muted disabled:no-underline disabled:cursor-not-allowed"
                        x-bind:disabled="remaining > 0">
                    <span x-show="remaining <= 0">Resend code</span>
                    <span x-show="remaining > 0" x-cloak>Resend available in <span x-text="remaining"></span>s</span>
                </button>
            </form>

            <form method="POST" action="{{ route('register.otp.reset') }}">
                @csrf
                <input type="hidden" name="email" value="{{ $pendingEmail }}">
                <button type="submit" class="text-sm text-text-muted hover:text-primary">Change email</button>
            </form>
        </div>
    @else
        <div class="mb-2 rounded-xl border border-accent/30 bg-accent/10 px-3 py-2 text-sm text-text">
            Email verified: <span class="font-semibold">{{ $verifiedEmail }}</span>
        </div>

        <form method="POST" action="{{ route('register') }}" class="registration-details space-y-2"
              x-data="{
                  password: '',
                  isSubmitting: false,
                  legalDoc: null,
                  get strength() {
                      const value = this.password || '';
                      if (! value.length) return 0;
                      if (value.length < 8) return 1;
                      const mixed = /[A-Za-z]/.test(value) && /\d/.test(value);
                      if (value.length >= 12 && mixed) return 4;
                      if (mixed) return 3;
                      return 2;
                  },
                  get hint() {
                      if (! this.password) return 'Use at least 8 characters.';
                      if (this.password.length < 8) return 'Use at least 8 characters.';
                      if (this.strength === 2) return 'Add a number to make it stronger.';
                      if (this.strength === 3) return 'Good password.';
                      return 'Strong password.';
                  },
                  get legalTitle() {
                      return this.legalDoc === 'privacy' ? 'Privacy Notice' : 'Terms of Use';
                  }
              }"
              x-init="$watch('legalDoc', value => document.body.classList.toggle('overflow-y-hidden', !!value))"
              @keydown.escape.window="legalDoc = null"
              @submit="isSubmitting = true">
            @csrf
            <input type="hidden" name="email" value="{{ $verifiedEmail }}" autocomplete="username">

            <div class="grid grid-cols-1 gap-x-4 gap-y-2 md:grid-cols-2">
            <div>
                <x-input-label for="name" :value="__('Full name')" class="text-xs font-semibold uppercase tracking-[0.16em] text-text-muted" />
                <div class="relative mt-2">
                    <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-text-muted">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M20 21a8 8 0 10-16 0" /><circle cx="12" cy="7" r="4" /></svg>
                    </span>
                    <x-text-input id="name" class="block w-full rounded-xl ps-11" type="text" name="name" :value="old('name')" required autofocus autocomplete="name" placeholder="Juan Dela Cruz" maxlength="255" oninput="this.value = this.value.replace(/[^\p{L}\p{M} .'’\-]/gu, '')" />
                </div>
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="phone" :value="__('Mobile number')" class="text-xs font-semibold uppercase tracking-[0.16em] text-text-muted" />
                <div class="relative mt-2">
                    <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-text-muted">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.95.68l1.5 4.5a1 1 0 01-.5 1.21l-2.26 1.13a11.04 11.04 0 005.52 5.52l1.13-2.26a1 1 0 011.21-.5l4.5 1.5a1 1 0 01.68.95V19a2 2 0 01-2 2h-1C9.72 21 3 14.28 3 6V5z" /></svg>
                    </span>
                    <x-text-input id="phone" class="block w-full rounded-xl ps-11" type="tel" name="phone" :value="old('phone')" required autocomplete="tel" inputmode="numeric" pattern="09[0-9]{9}" minlength="11" maxlength="11" placeholder="09171234567" title="Enter exactly 11 digits beginning with 09." data-ph-mobile />
                </div>
                <x-input-error :messages="$errors->get('phone')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="gender" :value="__('Sex')" class="text-xs font-semibold uppercase tracking-[0.16em] text-text-muted" />
                <select id="gender" name="gender" required class="mt-2 block w-full rounded-xl border-border bg-surface text-text shadow-sm focus:border-primary focus:ring-primary">
                    <option value="">Select</option>
                    @foreach(\App\Enums\GuestGender::cases() as $gender)
                        <option value="{{ $gender->value }}" @selected(old('gender') === $gender->value)>{{ $gender->label() }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('gender')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="date_of_birth" :value="__('Date of birth')" class="text-xs font-semibold uppercase tracking-[0.16em] text-text-muted" />
                <x-text-input id="date_of_birth" class="mt-2 block w-full rounded-xl" type="date" name="date_of_birth" :value="old('date_of_birth')" required autocomplete="bday" max="{{ now()->subDay()->toDateString() }}" />
                <x-input-error :messages="$errors->get('date_of_birth')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="address" :value="__('Address')" class="text-xs font-semibold uppercase tracking-[0.16em] text-text-muted" />
                <x-text-input id="address" class="mt-2 block w-full rounded-xl" type="text" name="address" :value="old('address')" required autocomplete="street-address" placeholder="Street, barangay, city, province" maxlength="255" />
                <x-input-error :messages="$errors->get('address')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="nationality" :value="__('Nationality')" class="text-xs font-semibold uppercase tracking-[0.16em] text-text-muted" />
                <x-text-input id="nationality" class="mt-2 block w-full rounded-xl" type="text" name="nationality" :value="old('nationality')" required maxlength="100" placeholder="Filipino" oninput="this.value = this.value.replace(/[^\p{L}\p{M} .'’\-]/gu, '')" />
                <x-input-error :messages="$errors->get('nationality')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="valid_id_type" :value="__('Valid ID type')" class="text-xs font-semibold uppercase tracking-[0.16em] text-text-muted" />
                <select id="valid_id_type" name="valid_id_type" required class="mt-2 block w-full rounded-xl border-border bg-surface text-text shadow-sm focus:border-primary focus:ring-primary">
                    <option value="">Select</option>
                    @foreach(\App\Enums\GuestIdType::cases() as $idType)
                        <option value="{{ $idType->value }}" @selected(old('valid_id_type') === $idType->value)>{{ $idType->label() }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('valid_id_type')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="company" :value="__('Company / Agency (optional)')" class="text-xs font-semibold uppercase tracking-[0.16em] text-text-muted" />
                <x-text-input id="company" class="mt-2 block w-full rounded-xl" type="text" name="company" :value="old('company')" maxlength="255" />
                <x-input-error :messages="$errors->get('company')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="password" :value="__('Password')" class="text-xs font-semibold uppercase tracking-[0.16em] text-text-muted" />
                <x-password-input id="password" name="password" label="new password"
                    :aria-invalid="$errors->has('password') ? 'true' : null"
                    :aria-describedby="$errors->has('password') ? 'registration-password-hint registration-password-error' : 'registration-password-hint'"
                    required autocomplete="new-password" enterkeyhint="next" x-model="password" />
                <div class="mt-3" data-password-meter aria-live="polite">
                    <div class="flex gap-1.5" aria-hidden="true">
                        <span class="password-meter-bar" :class="{ 'is-on': strength >= 1 }"></span>
                        <span class="password-meter-bar" :class="{ 'is-on': strength >= 2 }"></span>
                        <span class="password-meter-bar" :class="{ 'is-on': strength >= 3 }"></span>
                        <span class="password-meter-bar" :class="{ 'is-on': strength >= 4 }"></span>
                    </div>
                    <p id="registration-password-hint" class="mt-2 text-xs text-text-muted" x-text="hint">Use at least 8 characters.</p>
                </div>
                <x-input-error id="registration-password-error" :messages="$errors->get('password')" class="mt-2" />
                <x-input-error id="registration-email-error" :messages="$errors->get('email')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="password_confirmation" :value="__('Confirm password')" class="text-xs font-semibold uppercase tracking-[0.16em] text-text-muted" />
                <x-password-input id="password_confirmation" name="password_confirmation" label="password confirmation"
                    :aria-invalid="$errors->has('password_confirmation') ? 'true' : null"
                    :aria-describedby="$errors->has('password_confirmation') ? 'registration-confirmation-error' : null"
                    required autocomplete="new-password" enterkeyhint="done" />
                <x-input-error id="registration-confirmation-error" :messages="$errors->get('password_confirmation')" class="mt-2" />
            </div>
            </div>

            <div class="rounded-xl border border-border bg-background/60 px-3 py-2">
                <div class="flex items-start gap-3 text-sm text-text">
                    <input id="terms_accepted" type="checkbox" name="terms_accepted" value="1" @checked(old('terms_accepted')) required
                           class="mt-1 rounded border-border text-primary focus:ring-primary">
                    <p>
                        <label for="terms_accepted" class="cursor-pointer">I agree to the</label>
                        <button type="button" @click.stop.prevent="legalDoc = 'terms'" class="font-semibold text-primary hover:underline">Terms of Use</button>
                        and
                        <button type="button" @click.stop.prevent="legalDoc = 'privacy'" class="font-semibold text-primary hover:underline">Privacy Notice</button>.
                    </p>
                </div>
                <x-input-error :messages="$errors->get('terms_accepted')" class="mt-2" />
            </div>

            <x-primary-button class="w-full !rounded-full !py-3" x-bind:aria-busy="isSubmitting.toString()">
                <span x-show="!isSubmitting" class="inline-flex items-center gap-2">
                    {{ __('Create account') }}
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-6-6 6 6-6 6" /></svg>
                </span>
                <span x-show="isSubmitting" x-cloak>{{ __('Creating account…') }}</span>
            </x-primary-button>

            <div
                x-show="legalDoc"
                x-cloak
                class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-0 sm:p-6"
                role="dialog"
                aria-modal="true"
                :aria-labelledby="'register-legal-title'"
                style="display: none;"
            >
                <div class="absolute inset-0 bg-text/50" @click="legalDoc = null" aria-hidden="true"></div>
                <div
                    class="relative z-10 flex max-h-[90vh] w-full max-w-3xl flex-col overflow-hidden rounded-t-2xl sm:rounded-2xl border border-border bg-surface shadow-xl"
                    @click.stop
                >
                    <div class="flex items-start justify-between gap-4 border-b border-border px-5 py-4 sm:px-6">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-accent">Legal</p>
                            <h3 id="register-legal-title" class="mt-1 font-heading text-2xl font-semibold text-text" x-text="legalTitle"></h3>
                        </div>
                        <button type="button"
                                class="rounded-lg p-2 text-text-muted hover:bg-background hover:text-text"
                                @click="legalDoc = null"
                                aria-label="Close">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                        </button>
                    </div>
                    <div class="overflow-y-auto px-5 py-5 sm:px-6 sm:py-6">
                        <div x-show="legalDoc === 'terms'">
                            @include('pages.partials.terms-body', ['asModal' => true])
                        </div>
                        <div x-show="legalDoc === 'privacy'">
                            @include('pages.partials.privacy-body', ['asModal' => true])
                        </div>
                    </div>
                    <div class="border-t border-border px-5 py-4 sm:px-6">
                        <button type="button"
                                class="w-full rounded-full bg-primary px-4 py-2.5 text-sm font-semibold text-white hover:bg-primary-dark"
                                @click="legalDoc = null">
                            Close
                        </button>
                    </div>
                </div>
            </div>
        </form>

        <form method="POST" action="{{ route('register.otp.reset') }}" class="mt-1">
            @csrf
            <input type="hidden" name="email" value="{{ $verifiedEmail }}" autocomplete="username">
            <button type="submit" class="text-sm text-text-muted hover:text-primary">Change email</button>
        </form>
    @endif
</x-guest-layout>
