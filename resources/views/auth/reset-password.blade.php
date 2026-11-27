<x-guest-layout>
    <div class="mb-8">
        <h2 class="font-display text-2xl font-bold sm:text-3xl text-text">Choose a new password</h2>
        <p class="mt-2 text-text-muted">Use the email address where you received the reset link, then enter your new password.</p>
    </div>

    <form method="POST" action="{{ route('password.store') }}" class="space-y-5">
        @csrf

        <input type="hidden" name="token" value="{{ $token }}">

        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" class="block mt-2 w-full" type="email" name="email" :value="old('email', $email ?? '')" :aria-invalid="$errors->has('email') ? 'true' : null" :aria-describedby="$errors->has('email') ? 'email-error' : null" required :autofocus="$errors->has('email')" autocomplete="username" spellcheck="false" enterkeyhint="next" />
            <x-input-error id="email-error" :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="password" :value="__('New password')" />
            <x-password-input id="password" name="password" label="new password"
                :autofocus="$errors->has('password') && ! $errors->has('email')"
                :aria-invalid="$errors->has('password') ? 'true' : null"
                :aria-describedby="$errors->has('password') ? 'password-hint password-error' : 'password-hint'"
                required autocomplete="new-password" enterkeyhint="next" />
            <p id="password-hint" class="mt-2 text-sm text-text-muted">{{ __('Use at least 8 characters.') }}</p>
            <x-input-error id="password-error" :messages="$errors->get('password')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="password_confirmation" :value="__('Confirm new password')" />
            <x-password-input id="password_confirmation" name="password_confirmation" label="password confirmation"
                :aria-invalid="$errors->has('password_confirmation') ? 'true' : null"
                :aria-describedby="$errors->has('password_confirmation') ? 'password_confirmation-error' : null"
                required autocomplete="new-password" enterkeyhint="done" />
            <x-input-error id="password_confirmation-error" :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        <x-primary-button class="w-full">
            {{ __('Reset password') }}
        </x-primary-button>
    </form>
</x-guest-layout>
