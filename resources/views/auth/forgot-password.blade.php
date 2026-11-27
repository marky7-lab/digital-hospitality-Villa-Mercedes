<x-guest-layout>
    <div class="mb-8">
        <h2 class="font-display text-2xl font-bold sm:text-3xl text-text">Forgot your password?</h2>
        <p class="mt-2 text-text-muted">Enter the email on your account and we’ll send a reset link to your inbox.</p>
    </div>

    <x-auth-session-status class="mb-6" :status="session('status')" />

    @if (session('password_reset_url'))
        <div class="mb-6 rounded-xl border border-border bg-surface p-4">
            <p class="text-sm text-text-muted">Use this link to choose a new password:</p>
            <x-primary-button tag="a" href="{{ session('password_reset_url') }}" class="mt-3 w-full">
                Choose a new password
            </x-primary-button>
        </div>
    @endif

    <form method="POST" action="{{ route('password.email') }}" class="space-y-5">
        @csrf

        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" class="block mt-2 w-full" type="email" name="email" :value="old('email')" :aria-invalid="$errors->has('email') ? 'true' : null" :aria-describedby="$errors->has('email') ? 'email-error' : null" required autofocus autocomplete="username" spellcheck="false" enterkeyhint="go" placeholder="you@email.com" />
            <x-input-error id="email-error" :messages="$errors->get('email')" class="mt-2" />
        </div>

        <x-primary-button class="w-full">
            {{ __('Email reset link') }}
        </x-primary-button>
    </form>

    <p class="mt-8 text-center text-sm text-text-muted">
        Remembered it?
        <a href="{{ route('login') }}" class="font-semibold text-primary hover:underline">Back to log in</a>
    </p>
    <p class="mt-3 text-center text-sm text-text-muted">
        Still stuck?
        <a href="{{ config('resort.phone_href') }}" class="font-medium text-primary hover:underline">{{ config('resort.phone') }}</a>
    </p>
</x-guest-layout>
