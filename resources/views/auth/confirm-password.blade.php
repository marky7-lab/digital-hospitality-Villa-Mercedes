<x-guest-layout>
    <div class="mb-8">
        <h2 class="font-display text-2xl font-bold sm:text-3xl text-text">Confirm your password</h2>
        <p class="mt-2 text-text-muted">This is a secure area. Please confirm your password before continuing.</p>
    </div>

    <form method="POST" action="{{ route('password.confirm') }}" class="space-y-5">
        @csrf

        <div>
            <x-input-label for="password" :value="__('Password')" />
            <x-password-input id="password" name="password"
                :aria-invalid="$errors->has('password') ? 'true' : null"
                :aria-describedby="$errors->has('password') ? 'password-error' : null"
                required autocomplete="current-password" enterkeyhint="go" />
            <x-input-error id="password-error" :messages="$errors->get('password')" class="mt-2" />
        </div>

        <x-primary-button class="w-full">
            {{ __('Confirm') }}
        </x-primary-button>
    </form>
</x-guest-layout>
