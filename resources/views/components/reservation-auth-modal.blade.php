@guest
<dialog data-reservation-auth data-intent-url="{{ route('reservation.intent') }}" data-register-url="{{ route('register') }}"
    aria-labelledby="reservation-auth-title" aria-describedby="reservation-auth-description"
    class="w-[calc(100%-2rem)] max-w-md rounded-2xl border border-border bg-surface p-6 text-text shadow-xl backdrop:bg-black/50">
    <div class="flex items-start justify-between gap-4">
        <h2 id="reservation-auth-title" class="font-heading text-3xl font-semibold">Sign in to reserve</h2>
        <button type="button" data-auth-close aria-label="Close sign-in dialog" class="min-h-[44px] min-w-[44px] rounded-lg hover:bg-background">✕</button>
    </div>
    <p id="reservation-auth-description" class="mt-2 text-sm text-text-muted">Sign in or create an account to continue with <strong data-selected-service>your selected service</strong>. Your selection will be kept.</p>
    <p data-auth-status role="status" class="mt-3 text-sm text-text-muted"></p>
    <p data-auth-error role="alert" hidden class="mt-3 rounded-lg bg-danger-light p-3 text-sm text-danger"></p>
    <form data-reservation-login method="POST" action="{{ route('login') }}" class="mt-5 space-y-4">
        @csrf
        <div><x-input-label for="reservation-login-email" value="Email address" /><x-text-input id="reservation-login-email" name="email" type="email" required autocomplete="username" class="mt-1 w-full" /></div>
        <div>
            <x-input-label for="reservation-login-password" value="Password" />
            <div class="relative mt-1">
                <x-text-input id="reservation-login-password" name="password" type="password" data-custom-password-reveal required autocomplete="current-password" class="w-full !pr-14" />
                <button type="button" data-auth-password-toggle aria-label="Show password" aria-pressed="false" aria-controls="reservation-login-password" class="absolute inset-y-0 right-1 flex min-h-11 min-w-11 items-center justify-center bg-transparent text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                    <svg data-eye-open xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-5 w-5" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                    <svg data-eye-closed xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="hidden h-5 w-5" aria-hidden="true"><path d="m3 3 18 18"/><path d="M10.6 5.1A10.8 10.8 0 0 1 12 5c6.5 0 10 7 10 7a14 14 0 0 1-3.1 3.8M6.2 6.2C3.5 8.3 2 12 2 12s3.5 7 10 7a10.2 10.2 0 0 0 4.1-.9"/><path d="M10 10a3 3 0 0 0 4 4"/></svg>
                </button>
            </div>
        </div>
        <div class="flex flex-wrap justify-between gap-3 text-sm">
            <label><input type="checkbox" name="remember" value="1" class="rounded border-border text-primary"> Keep me signed in</label>
            <a href="{{ route('password.request') }}" class="text-primary hover:underline">Forgot password?</a>
        </div>
        <button data-auth-submit type="submit" disabled class="w-full rounded-lg bg-primary px-4 py-3 font-semibold text-white disabled:bg-primary/85 disabled:text-white">Sign in and continue</button>
    </form>
    <div class="mt-5 border-t border-border pt-5">
        <p class="text-sm text-text-muted">First visit?</p>
        <button data-auth-signup type="button" disabled class="mt-2 w-full rounded-lg border border-primary px-4 py-3 font-semibold text-primary disabled:border-primary disabled:text-primary">Create an account and continue</button>
        <p class="mt-2 text-xs text-text-muted">Verify your email and finish signup. We’ll bring you back to this reservation.</p>
    </div>
</dialog>
@endguest
