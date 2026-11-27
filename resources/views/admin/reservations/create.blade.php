@php
    $walkInFacilities = $facilities->map(fn ($facility) => [
        'id' => $facility->id,
        'name' => $facility->name,
        'type' => $facility->facilityType?->slug,
        'capacity' => $facility->capacity !== null ? (int) $facility->capacity : null,
        'max_capacity' => $facility->effectiveMaxCapacity(),
        'has_capacity_limit' => $facility->effectiveMaxCapacity() !== null,
    ])->values();

    $walkInFacilitySections = collect([
        ['slug' => 'room', 'label' => 'Room', 'placeholder' => 'Select a room'],
        ['slug' => 'function_hall', 'label' => 'Function hall', 'placeholder' => 'Select a function hall'],
        ['slug' => 'resort_package', 'label' => 'Package', 'placeholder' => 'Select a package'],
        ['slug' => 'cottage', 'label' => 'Cottage', 'placeholder' => 'Select a cottage'],
    ])->map(function (array $section) use ($facilities) {
        $section['facilities'] = $facilities
            ->filter(fn ($facility) => ($facility->facilityType?->slug ?? null) === $section['slug'])
            ->sortBy('name', SORT_NATURAL)
            ->values();

        return $section;
    })->filter(fn (array $section) => $section['facilities']->isNotEmpty())->values();

    $maxAdditionalHours = config('resort.stay.max_additional_hours', 12);
@endphp

<x-admin-layout>
    <x-slot name="header">New walk-in</x-slot>

    <x-admin.page-header
        title="New walk-in"
        :breadcrumbs="[
            ['label' => 'Reservations', 'url' => route('admin.reservations.index')],
            ['label' => 'New walk-in'],
        ]"
    />

    <div
        class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]"
        x-data="walkInReservation({
            facilities: {{ \Illuminate\Support\Js::from($walkInFacilities) }},
            facilityId: {{ \Illuminate\Support\Js::from(old('facility_id')) }},
            guestCount: {{ (int) old('guestCount', 1) }},
            otherGuestNames: {{ \Illuminate\Support\Js::from(array_map(
                static fn (array $guest): array => [
                    'name' => $guest['name'],
                    'valid_id' => $guest['valid_id'] ?? '',
                ],
                \App\Support\GuestInformation::normalizeOtherGuests(old('other_guest_names', []), keepEmpty: true)
            )) }},
            checkIn: {{ \Illuminate\Support\Js::from(old('check_in')) }},
            checkOut: {{ \Illuminate\Support\Js::from(old('check_out')) }}
        })"
    >
        {{-- The summary leads on a phone (context before the fields) and becomes
             the right-hand rail on desktop, so the same markup serves both. --}}
        <aside class="min-w-0 lg:col-start-2 lg:row-start-1 lg:sticky lg:top-24 lg:self-start" aria-label="Booking summary">
            <x-admin.panel title="Before you submit">
                <dl class="space-y-3 text-sm">
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-text-muted">Space</dt>
                        <dd class="mt-0.5 font-medium text-text" x-text="selectedFacility?.name || 'Not selected yet'"></dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-text-muted">Stay</dt>
                        <dd class="mt-0.5 text-text" x-text="stayWindowLabel"></dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-text-muted">Guests</dt>
                        <dd class="mt-0.5 text-text">
                            <span x-text="guestCount"></span> in total
                            <span x-show="otherGuestNames.filter((guest) => String(guest.name || '').trim() !== '').length > 0" x-cloak>
                                , with <span x-text="otherGuestNames.filter((guest) => String(guest.name || '').trim() !== '').length"></span> companion
                                <span x-text="otherGuestNames.filter((guest) => String(guest.name || '').trim() !== '').length === 1 ? '' : 's'"></span> named
                            </span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-text-muted">Approval</dt>
                        <dd class="mt-0.5 text-text">Approved immediately, and the space is blocked for other bookings right away.</dd>
                    </div>
                </dl>

                <p class="mt-4 flex items-start gap-2 rounded-lg bg-muted p-3 text-xs text-text-muted">
                    <svg class="mt-0.5 h-4 w-4 shrink-0 text-warning" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M12 9v4m0 4h.01M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
                    </svg>
                    Check the space and the dates against what the guest asked for. Correcting an approved walk-in means cancelling it and filing a new booking.
                </p>
            </x-admin.panel>
        </aside>

        <form method="POST" action="{{ route('admin.reservations.store') }}" class="min-w-0 lg:col-start-1 lg:row-start-1">
            @csrf

            <div class="space-y-6">
                <x-admin.panel
                    title="Guest"
                    description="Kept on the reservation for the resort's record. It is not stored on your staff account."
                >
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label for="guest_name" class="admin-label">Full name <span class="text-danger" aria-hidden="true">*</span></label>
                            <input type="text" name="guest_name" id="guest_name" value="{{ old('guest_name') }}" required maxlength="255" autocomplete="name"
                                   oninput="this.value = this.value.replace(/[^\p{L}\p{M} .'’\-]/gu, '')" aria-required="true" @error('guest_name') aria-invalid="true" @enderror class="admin-input mt-1">
                            <x-input-error class="mt-1" :messages="$errors->get('guest_name')" />
                        </div>

                        <div>
                            <label for="guest_phone" class="admin-label">Contact number <span class="text-danger" aria-hidden="true">*</span></label>
                            <input type="tel" name="guest_phone" id="guest_phone" value="{{ old('guest_phone') }}" required minlength="11" maxlength="11"
                                   inputmode="numeric" autocomplete="tel" pattern="09[0-9]{9}" placeholder="09171234567" title="Enter exactly 11 digits beginning with 09." data-ph-mobile aria-required="true" @error('guest_phone') aria-invalid="true" @enderror class="admin-input mt-1">
                            <x-input-error class="mt-1" :messages="$errors->get('guest_phone')" />
                        </div>

                        <div>
                            <label for="guest_gender" class="admin-label">Gender <span class="text-danger" aria-hidden="true">*</span></label>
                            <select name="guest_gender" id="guest_gender" required aria-required="true" @error('guest_gender') aria-invalid="true" @enderror class="admin-input mt-1">
                                <option value="">Select gender</option>
                                @foreach (\App\Enums\GuestGender::cases() as $gender)
                                    <option value="{{ $gender->value }}" @selected(old('guest_gender') === $gender->value)>{{ $gender->label() }}</option>
                                @endforeach
                            </select>
                            <x-input-error class="mt-1" :messages="$errors->get('guest_gender')" />
                        </div>

                        <div>
                            <label for="guest_valid_id" class="admin-label">ID presented <span class="text-danger" aria-hidden="true">*</span></label>
                            <select name="guest_valid_id" id="guest_valid_id" required aria-required="true" @error('guest_valid_id') aria-invalid="true" @enderror class="admin-input mt-1">
                                <option value="">Select ID type</option>
                                @foreach (\App\Enums\GuestIdType::cases() as $idType)
                                    <option value="{{ $idType->value }}" @selected(old('guest_valid_id') === $idType->value)>{{ $idType->label() }}</option>
                                @endforeach
                            </select>
                            <x-input-error class="mt-1" :messages="$errors->get('guest_valid_id')" />
                        </div>

                        <div>
                            <label for="guest_place_of_origin" class="admin-label">Address <span class="text-danger" aria-hidden="true">*</span></label>
                            <input type="text" name="guest_place_of_origin" id="guest_place_of_origin" value="{{ old('guest_place_of_origin') }}" required maxlength="255"
                                   placeholder="Street, barangay, city, province" aria-required="true" @error('guest_place_of_origin') aria-invalid="true" @enderror class="admin-input mt-1">
                            <x-input-error class="mt-1" :messages="$errors->get('guest_place_of_origin')" />
                        </div>

                        <div>
                            <label for="guest_nationality" class="admin-label">Nationality <span class="text-danger" aria-hidden="true">*</span></label>
                            <input type="text" name="guest_nationality" id="guest_nationality" value="{{ old('guest_nationality') }}" required maxlength="100"
                                   placeholder="Filipino" oninput="this.value = this.value.replace(/[^\p{L}\p{M} .'’\-]/gu, '')" aria-required="true" @error('guest_nationality') aria-invalid="true" @enderror class="admin-input mt-1">
                            <x-input-error class="mt-1" :messages="$errors->get('guest_nationality')" />
                        </div>

                        <div>
                            <label for="guest_email" class="admin-label">Email <span class="text-danger" aria-hidden="true">*</span></label>
                            <input type="email" name="guest_email" id="guest_email" value="{{ old('guest_email') }}" required maxlength="255" autocomplete="email"
                                   aria-required="true" @error('guest_email') aria-invalid="true" @enderror class="admin-input mt-1">
                            <x-input-error class="mt-1" :messages="$errors->get('guest_email')" />
                        </div>

                        <div>
                            <label for="guest_company" class="admin-label">Company or agency <span class="text-text-muted">(optional)</span></label>
                            <input type="text" name="guest_company" id="guest_company" value="{{ old('guest_company') }}" maxlength="255"
                                   @error('guest_company') aria-invalid="true" @enderror class="admin-input mt-1">
                            <x-input-error class="mt-1" :messages="$errors->get('guest_company')" />
                        </div>
                    </div>
                </x-admin.panel>

                <x-admin.panel
                    title="Stay"
                    description="Pick the space first, then the times. The space name and its guest limit follow your choice."
                >
                    <fieldset>
                        <legend class="admin-label">Space <span class="text-danger" aria-hidden="true">*</span></legend>
                        <p class="admin-help mb-3">Choose one option. The space you pick is held as soon as this reservation is created.</p>

                        <input type="hidden" name="facility_id" :value="facilityId">

                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            @foreach ($walkInFacilitySections as $section)
                                <div>
                                    <label for="facility_{{ $section['slug'] }}" class="admin-label">{{ $section['label'] }}</label>
                                    <select
                                        id="facility_{{ $section['slug'] }}"
                                        x-model="selections.{{ $section['slug'] }}"
                                        @change="selectFacility('{{ $section['slug'] }}', $event.target.value)"
                                        class="admin-input mt-1"
                                        :class="facilityId && selections.{{ $section['slug'] }} === String(facilityId) ? 'border-primary ring-1 ring-primary' : ''"
                                    >
                                        <option value="">{{ $section['placeholder'] }}</option>
                                        @foreach ($section['facilities'] as $facility)
                                            <option value="{{ $facility->id }}">{{ $facility->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endforeach
                        </div>

                        <p class="admin-help" x-show="selectedFacility" x-cloak>
                            <span class="font-medium text-text" x-text="selectedFacility?.name"></span>
                            <span x-show="hasCapacityLimit"> accepts up to <span x-text="maxCapacity"></span> guests<span x-show="includedCapacity">, with <span x-text="includedCapacity"></span> included</span>.</span>
                            <span x-show="! hasCapacityLimit"> has no fixed guest limit, so the guest count is for staff preparation only.</span>
                        </p>
                        <p class="admin-help" x-show="! selectedFacility" x-cloak>No space selected yet.</p>

                        <x-input-error class="mt-1" :messages="$errors->get('facility_id')" />
                    </fieldset>

                    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <div>
                            <label for="check_in" class="admin-label">Check-in <span class="text-danger" aria-hidden="true">*</span></label>
                            <input type="datetime-local" name="check_in" id="check_in" value="{{ old('check_in') }}" required
                                   x-model="checkIn" aria-required="true" @error('check_in') aria-invalid="true" @enderror class="admin-input mt-1">
                            <x-input-error class="mt-1" :messages="$errors->get('check_in')" />
                        </div>

                        <div>
                            <label for="check_out" class="admin-label">Check-out <span class="text-danger" aria-hidden="true">*</span></label>
                            <input type="datetime-local" name="check_out" id="check_out" value="{{ old('check_out') }}" required
                                   x-model="checkOut" aria-required="true" @error('check_out') aria-invalid="true" @enderror class="admin-input mt-1">
                            <x-input-error class="mt-1" :messages="$errors->get('check_out')" />
                        </div>

                        <div>
                            <label for="guestCount" class="admin-label">Guests <span class="text-danger" aria-hidden="true">*</span></label>
                            <input type="number" name="guestCount" id="guestCount" min="1" :max="hasCapacityLimit ? maxCapacity : null"
                                   x-model.number="guestCount" required aria-required="true" @error('guestCount') aria-invalid="true" @enderror class="admin-input mt-1">
                            <p class="admin-help" x-show="hasCapacityLimit" x-cloak>Maximum <span x-text="maxCapacity"></span> guests for this space.</p>
                            <x-input-error class="mt-1" :messages="$errors->get('guestCount')" />
                        </div>

                        <div>
                            <label for="early_check_in_hours" class="admin-label">Early check-in (hours)</label>
                            <input type="number" name="early_check_in_hours" id="early_check_in_hours" min="0" max="{{ $maxAdditionalHours }}"
                                   value="{{ old('early_check_in_hours', 0) }}" class="admin-input mt-1">
                        </div>

                        <div>
                            <label for="late_check_out_hours" class="admin-label">Late check-out (hours)</label>
                            <input type="number" name="late_check_out_hours" id="late_check_out_hours" min="0" max="{{ $maxAdditionalHours }}"
                                   value="{{ old('late_check_out_hours', 0) }}" class="admin-input mt-1">
                        </div>
                    </div>
                </x-admin.panel>

                <x-admin.panel
                    title="Party"
                    description="Add companion names and a valid ID type in case they arrive early, or they are the ones staying."
                >
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <p class="admin-help">
                            <span x-show="hasCapacityLimit" x-cloak>This space takes up to <span x-text="maxCapacity"></span> guests, including the guest above.</span>
                            <span x-show="! hasCapacityLimit" x-cloak>This space has no fixed guest limit.</span>
                        </p>
                        <x-admin.button type="button" variant="secondary" size="sm" x-show="canAddOtherGuest" x-cloak @click="addOtherGuest()">
                            Add companion
                        </x-admin.button>
                    </div>

                    <div class="mt-3 space-y-3" x-show="otherGuestNames.length > 0" x-cloak>
                        <template x-for="(guest, index) in otherGuestNames" :key="index">
                            <div class="rounded-xl border border-border bg-muted/40 p-3 space-y-3">
                                <div class="flex items-center gap-2">
                                    <label class="w-6 shrink-0 text-xs font-semibold text-text-muted" :for="`companion-${index}`" x-text="index + 1"></label>
                                    <input type="text" :name="`other_guest_names[${index}][name]`" x-model="guest.name" maxlength="255" class="admin-input" @input="guest.name = String($event.target.value || '').replace(/[^\p{L}\p{M} .'’\-]/gu, '')"
                                           :id="`companion-${index}`" :aria-label="`Companion ${index + 1} name`" placeholder="Companion full name">
                                    <x-admin.button type="button" variant="ghost" size="sm" @click="removeOtherGuest(index)">Remove</x-admin.button>
                                </div>
                                <div class="sm:pl-8">
                                    <label class="admin-label" :for="`companion-id-${index}`">Valid ID type (optional)</label>
                                    <select :name="`other_guest_names[${index}][valid_id]`" x-model="guest.valid_id" class="admin-input mt-1"
                                            :id="`companion-id-${index}`" :aria-label="`Companion ${index + 1} valid ID type`">
                                        <option value="">Select if available</option>
                                        @foreach (\App\Enums\GuestIdType::cases() as $idType)
                                            <option value="{{ $idType->value }}">{{ $idType->label() }}</option>
                                        @endforeach
                                    </select>
                                    <p class="admin-help mt-1">Needed if this companion arrives early or is the one staying.</p>
                                </div>
                            </div>
                        </template>
                    </div>

                    <p class="admin-help" x-show="hasCapacityLimit && ! canAddOtherGuest && maxCompanions === 0" x-cloak>This space has no extra guest-name slots.</p>
                    <p class="admin-help" x-show="hasCapacityLimit && ! canAddOtherGuest && maxCompanions > 0 && otherGuestNames.length >= maxCompanions" x-cloak>Every companion slot for this space is used.</p>

                    <x-input-error class="mt-1" :messages="$errors->get('other_guest_names')" />
                    <x-input-error class="mt-1" :messages="$errors->get('other_guest_names.*.name')" />
                    <x-input-error class="mt-1" :messages="$errors->get('other_guest_names.*.valid_id')" />
                </x-admin.panel>

                <x-admin.panel title="Notes" description="Internal only. Guests never see this.">
                    <label for="admin_notes" class="admin-label">Internal note</label>
                    <textarea name="admin_notes" id="admin_notes" rows="3" class="admin-input mt-1">{{ old('admin_notes') }}</textarea>
                </x-admin.panel>
            </div>

            <div class="sticky bottom-0 mt-6 flex flex-col-reverse gap-2 border-t border-border bg-surface py-4 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-xs text-text-muted">Information is recorded for the resort's filing and security records.</p>
                <div class="flex flex-col-reverse gap-2 sm:flex-row">
                    <x-admin.button variant="secondary" :href="route('admin.reservations.index')">Cancel</x-admin.button>
                    <x-admin.button type="submit" variant="primary">Create walk-in reservation</x-admin.button>
                </div>
            </div>
        </form>

    </div>
</x-admin-layout>
