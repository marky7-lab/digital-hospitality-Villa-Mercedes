<section>
    <header>
        <h2 class="text-lg font-medium text-text">
            {{ __('Profile Information') }}
        </h2>

        <p class="mt-1 text-sm text-text-muted">
            {{ __("Update your account details. These also prefill your guest information when you book.") }}
        </p>
    </header>

    <form id="send-verification" method="post" action="{{ route('verification.send') }}">
        @csrf
    </form>

    <form method="post" action="{{ route('profile.update') }}" class="mt-6 space-y-6">
        @csrf
        @method('patch')

        <div>
            <x-input-label for="name" :value="__('Name')" />
            <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name', $user->name)" required autofocus autocomplete="name" maxlength="255" oninput="this.value = this.value.replace(/[^\p{L}\p{M} .'’\-]/gu, '')" />
            <x-input-error class="mt-2" :messages="$errors->get('name')" />
        </div>

        <div>
            <x-input-label for="phone" :value="__('Mobile number')" />
            <x-text-input id="phone" name="phone" type="tel" class="mt-1 block w-full" :value="old('phone', $user->phone)" autocomplete="tel" inputmode="numeric" pattern="09[0-9]{9}" minlength="11" maxlength="11" placeholder="09171234567" title="Enter exactly 11 digits beginning with 09." data-ph-mobile />
            <x-input-error class="mt-2" :messages="$errors->get('phone')" />
        </div>

        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" :value="old('email', $user->email)" required autocomplete="username" />
            <x-input-error class="mt-2" :messages="$errors->get('email')" />

            @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
                <div>
                    <p class="text-sm mt-2 text-text">
                        {{ __('Your email address is unverified.') }}

                        <button form="send-verification" class="underline text-sm text-text-muted hover:text-text rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary">
                            {{ __('Click here to re-send the verification email.') }}
                        </button>
                    </p>

                    @if (session('status') === 'verification-link-sent')
                        <p class="mt-2 font-medium text-sm text-success">
                            {{ __('A new verification link has been sent to your email address.') }}
                        </p>
                    @endif
                </div>
            @endif
        </div>

        <div>
            <x-input-label for="gender" :value="__('Gender')" />
            <select id="gender" name="gender" class="mt-1 block w-full border-border focus:border-primary focus:ring-primary rounded-md shadow-sm">
                <option value="">Select</option>
                @foreach(\App\Enums\GuestGender::cases() as $gender)
                    <option value="{{ $gender->value }}" @selected(old('gender', $user->gender?->value) === $gender->value)>{{ $gender->label() }}</option>
                @endforeach
            </select>
            <x-input-error class="mt-2" :messages="$errors->get('gender')" />
        </div>

        <div>
            <x-input-label for="date_of_birth" :value="__('Date of birth')" />
            <x-text-input id="date_of_birth" name="date_of_birth" type="date" class="mt-1 block w-full" :value="old('date_of_birth', optional($user->date_of_birth)->format('Y-m-d'))" autocomplete="bday" max="{{ now()->subDay()->toDateString() }}" />
            <x-input-error class="mt-2" :messages="$errors->get('date_of_birth')" />
        </div>

        <div>
            <x-input-label for="address" :value="__('Address')" />
            <x-text-input id="address" name="address" type="text" class="mt-1 block w-full" :value="old('address', $user->address)" autocomplete="street-address" maxlength="255" />
            <x-input-error class="mt-2" :messages="$errors->get('address')" />
        </div>

        <div>
            <x-input-label for="nationality" :value="__('Nationality')" />
            <x-text-input id="nationality" name="nationality" type="text" class="mt-1 block w-full" :value="old('nationality', $user->nationality)" maxlength="100" placeholder="Filipino" oninput="this.value = this.value.replace(/[^\p{L}\p{M} .'’\-]/gu, '')" />
            <x-input-error class="mt-2" :messages="$errors->get('nationality')" />
        </div>

        <div>
            <x-input-label for="valid_id_type" :value="__('Valid ID type')" />
            <select id="valid_id_type" name="valid_id_type" class="mt-1 block w-full border-border focus:border-primary focus:ring-primary rounded-md shadow-sm">
                <option value="">Select</option>
                @foreach(\App\Enums\GuestIdType::cases() as $idType)
                    <option value="{{ $idType->value }}" @selected(old('valid_id_type', $user->valid_id_type?->value) === $idType->value)>{{ $idType->label() }}</option>
                @endforeach
            </select>
            <x-input-error class="mt-2" :messages="$errors->get('valid_id_type')" />
        </div>

        <div>
            <x-input-label for="company" :value="__('Company / Agency (optional)')" />
            <x-text-input id="company" name="company" type="text" class="mt-1 block w-full" :value="old('company', $user->company)" maxlength="255" />
            <x-input-error class="mt-2" :messages="$errors->get('company')" />
        </div>

        <div class="flex items-center gap-4">
            <x-primary-button>{{ __('Save') }}</x-primary-button>

            @if (session('status') === 'profile-updated')
                <p
                    x-data="{ show: true }"
                    x-show="show"
                    x-transition
                    x-init="setTimeout(() => show = false, 2000)"
                    class="text-sm text-text-muted"
                >{{ __('Saved.') }}</p>
            @endif
        </div>
    </form>
</section>
