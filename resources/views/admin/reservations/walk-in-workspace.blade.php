<x-admin-layout>
    <x-slot name="header">Walk-in workspace</x-slot>

    <x-admin.page-header
        title="Walk-in workspace"
        :breadcrumbs="[
            ['label' => 'Reservations', 'url' => route('admin.reservations.index')],
            ['label' => 'Walk-in workspace'],
        ]"
    />

    @php
        $walkInConfig = [
            'facilities' => $bookableFacilities->map(fn($f) => [
                'id' => $f->id,
                'name' => $f->name,
                'type' => $f->facilityType?->slug,
                'type_label' => $f->facilityType?->name ?? 'Space',
                'max_capacity' => $f->maxGuestCount(),
                'included_capacity' => $f->includedGuestCount(),
                'pax_tiers' => $f->paxTiers(),
                'is_cottage' => $f->isCottage(),
                'is_hall' => $f->isFunctionHall(),
                'is_room' => $f->isRoom(),
                'is_package' => $f->isPackage(),
                'description' => $f->description,
                'status' => $f->status,
                'is_active' => (bool) $f->is_active,
                'photos' => collect([$f->cover_image_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($f->cover_image_path) : null])
                    ->merge($f->galleryImages->where('is_active', true)->map(fn ($image) => $image->url()))->filter()->unique()->values(),
                'amenities' => $f->amenities->pluck('name'),
                'rate' => ($rate = $f->rateForPaxTier(null)) ? ['amount' => $rate->amount, 'unit' => $rate->unit] : null,
                'schedule' => app(\App\Services\ReservationSchedule::class)->configuration($f),
            ]),
            'sessions' => $swimmingSessions->map(fn($s) => [
                'id' => $s->id,
                'slug' => $s->slug,
                'name' => $s->name,
                'start_time' => substr($s->starts_at, 0, 5),
                'end_time' => substr($s->ends_at, 0, 5),
            ]),
            'availabilityUrl' => route('admin.reservations.walk-in.availability'),
            'storeUrl' => route('admin.reservations.walk-in.store'),
            'lookupUrl' => route('admin.reservations.lookup-guest'),
            'csrfToken' => csrf_token(),
            'today' => \App\Support\ResortTime::today()->toDateString(),
        ];
    @endphp

    <div x-data="walkInWorkspace()" data-admin-walkin-workspace class="space-y-6 pb-24">
        {{-- Service Quick-View Drawer --}}
        <div x-show="detailFacility" x-cloak @keydown.escape.window="closeDetails()" @keydown.tab="trapDetailFocus($event)" class="fixed inset-0 z-50" role="presentation">
            <div class="absolute inset-0 bg-black/60 backdrop-blur-xs transition-opacity" @click="closeDetails()"></div>
            <section role="dialog" aria-modal="true" aria-labelledby="admin-service-detail-title" class="absolute inset-y-0 right-0 w-full sm:max-w-xl overflow-y-auto bg-surface shadow-2xl p-6 border-l border-border" x-ref="detailSheet" tabindex="-1">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <span class="text-xs font-bold uppercase tracking-wider text-primary" x-text="detailFacility?.type_label"></span>
                        <h2 id="admin-service-detail-title" class="font-heading text-2xl font-bold text-text mt-0.5" x-text="detailFacility?.name"></h2>
                    </div>
                    <button type="button" @click="closeDetails()" class="rounded-lg border border-border p-2 text-text-muted hover:text-text hover:bg-muted" aria-label="Close service details">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                <template x-if="detailFacility?.photos.length">
                    <div class="mt-4 flex gap-2 overflow-x-auto pb-2">
                        <template x-for="photo in detailFacility.photos" :key="photo">
                            <img :src="photo" :alt="detailFacility.name" class="h-44 w-60 shrink-0 rounded-xl object-cover border border-border">
                        </template>
                    </div>
                </template>

                <div class="mt-4 space-y-4 text-sm">
                    <div>
                        <h3 class="font-semibold text-text uppercase text-xs tracking-wider">Description</h3>
                        <p class="mt-1 text-text-muted" x-text="detailFacility?.description || 'Description not yet published.'"></p>
                    </div>

                    <div>
                        <h3 class="font-semibold text-text uppercase text-xs tracking-wider">Inclusions &amp; Amenities</h3>
                        <p class="mt-1 text-text-muted" x-text="detailFacility?.amenities.length ? detailFacility.amenities.join(' · ') : 'Standard resort amenities.'"></p>
                    </div>

                    <div class="grid grid-cols-2 gap-3 p-3 bg-muted/40 rounded-xl border border-border">
                        <div>
                            <span class="text-xs text-text-muted uppercase">Capacity</span>
                            <p class="font-semibold text-text mt-0.5" x-text="detailFacility ? detailFacility.included_capacity + ' included (up to ' + detailFacility.max_capacity + ' guests)' : ''"></p>
                        </div>
                        <div>
                            <span class="text-xs text-text-muted uppercase">Base Rate</span>
                            <p class="font-bold text-primary mt-0.5" x-text="detailFacility?.rate ? formatPesos(detailFacility.rate.amount) + ' ' + detailFacility.rate.unit : 'Rate on inquiry'"></p>
                        </div>
                        <div>
                            <span class="text-xs text-text-muted uppercase">Operational Status</span>
                            <p class="font-semibold capitalize text-text mt-0.5" x-text="detailFacility?.status || 'available'"></p>
                        </div>
                        <div>
                            <span class="text-xs text-text-muted uppercase">Schedule Type</span>
                            <p class="font-semibold capitalize text-text mt-0.5" x-text="detailFacility ? detailFacility.schedule.mode : ''"></p>
                        </div>
                    </div>

                    <p class="text-xs text-text-muted">Strict operational rules apply: date, time, and session controls must be validated before booking.</p>
                </div>

                <div class="mt-6">
                    <button type="button" @click="addLine(detailFacility.id); closeDetails()" :disabled="lines.some(line => Number(line.facility_id) === detailFacility?.id)" class="w-full py-3 rounded-xl bg-primary text-white font-semibold hover:bg-primary-hover disabled:opacity-50 transition shadow-sm">
                        <span x-text="lines.some(line => Number(line.facility_id) === detailFacility?.id) ? 'Already in booking' : 'Add to walk-in booking'"></span>
                    </button>
                </div>
            </section>
        </div>

        {{-- Error Summary --}}
        <div x-show="errorMessage" x-cloak class="rounded-xl border border-danger bg-danger-light p-4 text-danger flex items-start gap-3" role="alert">
            <svg class="w-5 h-5 shrink-0 mt-0.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            <div class="flex-1">
                <p class="font-semibold text-sm">Action failed</p>
                <p class="text-sm mt-0.5" x-text="errorMessage"></p>
            </div>
            <button type="button" @click="errorMessage = ''" class="text-danger hover:opacity-75">&times;</button>
        </div>

        {{-- 4-Stage Progress Header --}}
        <div class="bg-surface rounded-xl border border-border p-3 shadow-xs">
            <ol class="flex items-center justify-between max-w-2xl mx-auto text-xs sm:text-sm font-semibold">
                <li class="flex items-center gap-2 cursor-pointer" @click="step = 1">
                    <span class="w-7 h-7 rounded-full flex items-center justify-center font-bold text-xs" :class="step === 1 ? 'bg-primary text-white ring-2 ring-primary/20' : (step > 1 ? 'bg-success text-white' : 'bg-muted text-text-muted')">1</span>
                    <span :class="step === 1 ? 'text-primary' : 'text-text-muted'">Guest</span>
                </li>
                <span class="w-6 sm:w-12 h-0.5 bg-border"></span>
                <li class="flex items-center gap-2 cursor-pointer" @click="goToStep(2)">
                    <span class="w-7 h-7 rounded-full flex items-center justify-center font-bold text-xs" :class="step === 2 ? 'bg-primary text-white ring-2 ring-primary/20' : (step > 2 ? 'bg-success text-white' : 'bg-muted text-text-muted')">2</span>
                    <span :class="step === 2 ? 'text-primary' : 'text-text-muted'">Services</span>
                </li>
                <span class="w-6 sm:w-12 h-0.5 bg-border"></span>
                <li class="flex items-center gap-2 cursor-pointer" @click="goToStep(3)">
                    <span class="w-7 h-7 rounded-full flex items-center justify-center font-bold text-xs" :class="step === 3 ? 'bg-primary text-white ring-2 ring-primary/20' : (step > 3 ? 'bg-success text-white' : 'bg-muted text-text-muted')">3</span>
                    <span :class="step === 3 ? 'text-primary' : 'text-text-muted'">Schedule</span>
                </li>
                <span class="w-6 sm:w-12 h-0.5 bg-border"></span>
                <li class="flex items-center gap-2 cursor-pointer" @click="goToStep(4)">
                    <span class="w-7 h-7 rounded-full flex items-center justify-center font-bold text-xs" :class="step === 4 ? 'bg-primary text-white ring-2 ring-primary/20' : 'bg-muted text-text-muted'">4</span>
                    <span :class="step === 4 ? 'text-primary' : 'text-text-muted'">Review &amp; Create</span>
                </li>
            </ol>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-[1.6fr_1fr] gap-8 items-start">
            
            {{-- Left Column: Interactive Stages --}}
            <div class="space-y-6">

                {{-- STAGE 1: Guest Identification --}}
                <div x-show="step === 1" class="rounded-xl border border-border bg-surface p-6 shadow-sm space-y-4">
                    <div>
                        <h2 class="font-heading text-lg font-semibold text-text">1. Guest Identification</h2>
                        <p class="text-xs text-text-muted">Identify existing registered customers or create a walk-in guest sheet.</p>
                    </div>

                    <div class="relative">
                        <label class="admin-label">Search existing customer by email, phone, or name</label>
                        <input type="text" x-model="searchQuery" @input.debounce.300ms="searchGuest()" placeholder="e.g. maria@example.com or 09171234567" class="admin-input mt-1">
                        
                        <template x-if="searchResults.length > 0">
                            <ul class="absolute z-20 mt-1 w-full rounded-lg border border-border bg-surface shadow-lg max-h-48 overflow-y-auto divide-y divide-border">
                                <template x-for="cust in searchResults" :key="cust.id">
                                    <li @click="selectGuest(cust)" class="p-3 hover:bg-muted cursor-pointer text-sm">
                                        <p class="font-semibold text-text" x-text="cust.name"></p>
                                        <p class="text-xs text-text-muted" x-text="cust.email + ' · ' + cust.phone"></p>
                                    </li>
                                </template>
                            </ul>
                        </template>
                    </div>

                    <div x-ref="guestFields" class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-2">
                        <div>
                            <label class="admin-label">Full name</label>
                            <input type="text" x-model.trim="guest.guest_name" maxlength="255" pattern="(?=.*\p{L})[\p{L}\p{M} .'’\-]+" title="Use letters, spaces, apostrophes, periods, or hyphens." class="admin-input mt-1" required>
                        </div>
                        <div>
                            <label class="admin-label">Email address</label>
                            <input type="email" x-model.trim="guest.guest_email" maxlength="255" class="admin-input mt-1" required>
                        </div>
                        <div>
                            <label for="walk-in-phone" class="admin-label">Mobile number</label>
                            <input id="walk-in-phone" type="tel" inputmode="numeric" autocomplete="tel-national" pattern="09[0-9]{9}" maxlength="11" data-ph-mobile x-model="guest.guest_phone" placeholder="09171234567" aria-describedby="walk-in-phone-help" class="admin-input mt-1" required>
                            <p id="walk-in-phone-help" class="admin-help">Enter 11 digits starting with 09.</p>
                        </div>
                        <div>
                            <label class="admin-label">Place of origin / City</label>
                            <input type="text" x-model.trim="guest.guest_place_of_origin" maxlength="255" class="admin-input mt-1" required>
                        </div>
                        <div>
                            <label class="admin-label">Nationality</label>
                            <input type="text" x-model.trim="guest.guest_nationality" maxlength="100" class="admin-input mt-1" required>
                        </div>
                        <div>
                            <label class="admin-label">Company / Group (optional)</label>
                            <input type="text" x-model.trim="guest.guest_company" maxlength="255" class="admin-input mt-1">
                        </div>
                        <div>
                            <label class="admin-label">Valid ID type</label>
                            <select x-model="guest.guest_valid_id" class="admin-input mt-1" required>
                                <option value="">Select ID type…</option>
                                @foreach (\App\Enums\GuestIdType::cases() as $idType)
                                    <option value="{{ $idType->value }}">{{ $idType->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="admin-label">Gender</label>
                            <select x-model="guest.guest_gender" class="admin-input mt-1" required>
                                <option value="">Select gender…</option>
                                @foreach (\App\Enums\GuestGender::cases() as $gender)
                                    <option value="{{ $gender->value }}">{{ $gender->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="flex justify-end pt-3">
                        <button type="button" @click="goToStep(2)" class="px-5 py-2.5 rounded-lg bg-primary text-white font-semibold hover:bg-primary-hover transition text-sm">
                            Next: Select Services &rarr;
                        </button>
                    </div>
                </div>

                {{-- STAGE 2: Visual Catalog --}}
                <div x-show="step === 2" class="space-y-4">
                    <div class="rounded-xl border border-border bg-surface p-6 shadow-sm space-y-4">
                        <div class="flex flex-wrap items-center justify-between gap-4">
                            <div>
                                <h2 class="font-heading text-lg font-semibold text-text">2. Visual Service Catalog</h2>
                                <p class="text-xs text-text-muted">Browse spaces and services to add to this walk-in booking.</p>
                            </div>
                        </div>

                        {{-- Filters --}}
                        <div class="flex gap-2 overflow-x-auto pb-1" aria-label="Service categories">
                            <template x-for="category in ['All', 'room', 'cottage', 'function_hall', 'resort_package', 'other']" :key="category">
                                <button type="button" @click="categoryFilter = category" class="shrink-0 rounded-full px-4 py-1.5 text-xs font-semibold border border-border transition" :class="categoryFilter === category ? 'bg-primary text-white border-primary' : 'bg-surface text-text hover:bg-muted'" x-text="category === 'All' ? 'All spaces' : category.replaceAll('_', ' ')"></button>
                            </template>
                        </div>

                        {{-- Cards Grid --}}
                        <div class="grid gap-4 sm:grid-cols-2">
                            <template x-for="fac in filteredFacilities" :key="fac.id">
                                <article class="overflow-hidden rounded-xl border border-border bg-surface shadow-xs flex flex-col justify-between">
                                    <div>
                                        <template x-if="fac.photos.length">
                                            <img :src="fac.photos[0]" :alt="fac.name" class="h-36 w-full object-cover">
                                        </template>
                                        <template x-if="!fac.photos.length">
                                            <div class="h-36 bg-muted flex items-center justify-center text-xs text-text-muted font-medium">Photo not available</div>
                                        </template>
                                        <div class="p-3.5 space-y-1.5">
                                            <div class="flex items-center justify-between text-[11px] font-semibold">
                                                <span class="uppercase tracking-wide text-primary" x-text="fac.type_label"></span>
                                                <span :class="fac.status === 'available' ? 'text-success' : 'text-danger'" x-text="fac.status"></span>
                                            </div>
                                            <h3 class="font-heading text-base font-bold text-text truncate" x-text="fac.name"></h3>
                                            <p class="text-xs text-text-muted line-clamp-2" x-text="fac.description || 'Verified resort facility.'"></p>
                                            <p class="text-[11px] text-text-muted" x-text="'Capacity: ' + fac.included_capacity + ' incl, up to ' + fac.max_capacity + ' pax'"></p>
                                            <p class="font-bold text-sm text-text" x-text="fac.rate ? formatPesos(fac.rate.amount) + ' ' + fac.rate.unit : 'Rate on inquiry'"></p>
                                        </div>
                                    </div>
                                    <div class="p-3.5 pt-0 flex gap-2">
                                        <button type="button" @click="openDetails(fac, $event.currentTarget)" class="flex-1 rounded-lg border border-border px-3 py-1.5 text-xs font-semibold text-text hover:bg-muted">Details</button>
                                        <button type="button" @click="addLine(fac.id)" :disabled="lines.some(l => Number(l.facility_id) === fac.id)" class="flex-1 rounded-lg bg-primary px-3 py-1.5 text-xs font-semibold text-white hover:bg-primary-hover disabled:opacity-50">
                                            <span x-text="lines.some(l => Number(l.facility_id) === fac.id) ? 'Added' : 'Add line'"></span>
                                        </button>
                                    </div>
                                </article>
                            </template>
                        </div>

                        <div class="flex items-center justify-between pt-3 border-t border-border">
                            <button type="button" @click="step = 1" class="text-xs font-semibold text-text-muted hover:underline">&larr; Back to guest</button>
                            <button type="button" @click="goToStep(3)" :disabled="lines.length === 0" class="px-5 py-2.5 rounded-lg bg-primary text-white font-semibold hover:bg-primary-hover disabled:opacity-50 transition text-sm">
                                Configure Schedules (<span x-text="lines.length"></span>) &rarr;
                            </button>
                        </div>
                    </div>
                </div>

                {{-- STAGE 3: Strict Schedule Controls --}}
                <div x-show="step === 3" x-ref="scheduleFields" class="space-y-4">
                    <div class="rounded-xl border border-border bg-surface p-6 shadow-sm space-y-4">
                        <div class="flex items-center justify-between">
                            <div>
                                <h2 class="font-heading text-lg font-semibold text-text">3. Strict Schedule &amp; Availability</h2>
                                <p class="text-xs text-text-muted">Operational hours, fixed check-in/out policies, and conflict checking.</p>
                            </div>
                            <button type="button" @click="step = 2" class="text-xs font-semibold text-primary hover:underline">+ Add more services</button>
                        </div>

                        <template x-if="lines.length === 0">
                            <div class="p-8 text-center border border-dashed border-border rounded-xl">
                                <p class="text-sm text-text-muted">No services added to booking yet.</p>
                                <button type="button" @click="step = 2" class="mt-2 text-sm font-semibold text-primary hover:underline">Select services from catalog</button>
                            </div>
                        </template>

                        <div class="space-y-4">
                            <template x-for="(line, index) in lines" :key="index">
                                <div class="rounded-xl border border-border p-4 bg-muted/20 space-y-3" :class="lineCardBorderClass(index)">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center gap-2">
                                            <span class="w-5 h-5 rounded-full bg-primary/10 text-primary font-bold text-xs flex items-center justify-center" x-text="index + 1"></span>
                                            <h3 class="font-heading text-sm font-bold text-text" x-text="facilityName(line.facility_id)"></h3>
                                        </div>
                                        <button type="button" @click="removeLine(index)" class="text-xs text-danger hover:underline">Remove</button>
                                    </div>

                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                                        {{-- Cottage: Visit Date + Active Session --}}
                                        <template x-if="isCottage(line.facility_id)">
                                            <div class="contents">
                                                <div>
                                                    <label class="admin-label">Visit date</label>
                                                    <input type="date" x-model="line.date" @change="onInputChanged()" :min="today" class="admin-input mt-1">
                                                </div>
                                                <div>
                                                    <label class="admin-label">Swimming session</label>
                                                    <select x-model="line.swimming_session_id" @change="onInputChanged()" class="admin-input mt-1">
                                                        <template x-for="sess in config.sessions" :key="sess.id">
                                                            <option :value="sess.id" x-text="sess.name + ' (' + sess.start_time + '–' + sess.end_time + ')'"></option>
                                                        </template>
                                                    </select>
                                                </div>
                                            </div>
                                        </template>

                                        {{-- Room: Arrival Date (2PM) to Departure Date (12PM) --}}
                                        <template x-if="isRoom(line.facility_id)">
                                            <div class="contents">
                                                <div>
                                                    <label class="admin-label">Check in date (2:00 PM)</label>
                                                    <input type="date" :value="datePart(line.check_in)" @input="line.check_in = $event.target.value + ' 14:00'; onInputChanged()" :min="today" class="admin-input mt-1">
                                                </div>
                                                <div>
                                                    <label class="admin-label">Check out date (12:00 PM)</label>
                                                    <input type="date" :value="datePart(line.check_out)" @input="line.check_out = $event.target.value + (datePart(line.check_in) === $event.target.value ? ' 17:00' : ' 12:00'); if (datePart(line.check_in) === $event.target.value) { line.check_in = $event.target.value + ' 09:00'; line.early_check_in_hours = 0; } onInputChanged()" :min="datePart(line.check_in) || today" class="admin-input mt-1">
                                                </div>
                                            </div>
                                        </template>

                                        {{-- Hall: Event Date + Slot --}}
                                        <template x-if="isHall(line.facility_id)">
                                            <div class="contents">
                                                <div>
                                                    <label class="admin-label">Event date</label>
                                                    <input type="date" :value="datePart(line.check_in)" @change="setHallSlot(line, $event.target.value, line.hall_slot || '09:00|13:00'); onInputChanged()" :min="today" class="admin-input mt-1">
                                                </div>
                                                <div>
                                                    <label class="admin-label">Time block</label>
                                                    <select x-model="line.hall_slot" @change="setHallSlot(line, datePart(line.check_in), line.hall_slot); onInputChanged()" class="admin-input mt-1">
                                                        <template x-if="facility(line.facility_id)?.schedule.mode === 'opal'">
                                                            <optgroup label="Opal Hall">
                                                                <option value="09:00|17:00">9:00 AM – 5:00 PM</option>
                                                                <option value="17:00|22:00">5:00 PM – 10:00 PM</option>
                                                            </optgroup>
                                                        </template>
                                                        <template x-if="facility(line.facility_id)?.schedule.mode !== 'opal'">
                                                            <optgroup label="Standard blocks">
                                                                <option value="09:00|13:00">9:00 AM – 1:00 PM</option>
                                                                <option value="13:00|17:00">1:00 PM – 5:00 PM</option>
                                                                <option value="17:00|21:00">5:00 PM – 9:00 PM</option>
                                                            </optgroup>
                                                        </template>
                                                    </select>
                                                </div>
                                            </div>
                                        </template>

                                        {{-- Package --}}
                                        <template x-if="isPackage(line.facility_id)">
                                            <div class="contents">
                                                <div>
                                                    <label class="admin-label">Package check in date</label>
                                                    <input type="date" :value="datePart(line.check_in)" @change="setPackageDate(line, $event.target.value); onInputChanged()" :min="today" class="admin-input mt-1">
                                                </div>
                                                <div class="flex items-center text-xs text-text-muted pt-4">
                                                    <span x-text="facility(line.facility_id)?.schedule.label || 'Standard package hours'"></span>
                                                </div>
                                            </div>
                                        </template>

                                        {{-- Other or Manual fallback --}}
                                        <template x-if="!isCottage(line.facility_id) && !isRoom(line.facility_id) && !isHall(line.facility_id) && !isPackage(line.facility_id)">
                                            <div class="contents">
                                                <div>
                                                    <label class="admin-label">Check-in</label>
                                                    <input type="datetime-local" x-model="line.check_in" @change="onInputChanged()" class="admin-input mt-1">
                                                </div>
                                                <div>
                                                    <label class="admin-label">Check-out</label>
                                                    <input type="datetime-local" x-model="line.check_out" @change="onInputChanged()" class="admin-input mt-1">
                                                </div>
                                            </div>
                                        </template>

                                        <div>
                                            <label class="admin-label">Infants under one (room/package; free and excluded from guest count)<input type="number" min="0" max="500" x-model.number="line.infants_count" @change="onInputChanged()" class="admin-input"></label>
                                            <label class="admin-label">Older children (included in guest count)<input type="number" min="0" :max="line.guest_count" x-model.number="line.children_count" @change="onInputChanged()" class="admin-input"></label>
                                            <label class="admin-label">Party / Guest count</label>
                                            <input type="number" x-model.number="line.guest_count" @change="onInputChanged()" min="1" :max="maxCapacity(line.facility_id)" class="admin-input mt-1" required>
                                        </div>

                                        <template x-if="isRoom(line.facility_id)">
                                            <div>                                                    <div x-show="datePart(line.check_in) === datePart(line.check_out)">
                                                        <label class="admin-label">Same-day check in<input type="time" :value="line.check_in.slice(11,16)" @input="line.check_in = datePart(line.check_in) + ' ' + $event.target.value; line.early_check_in_hours = 0; onInputChanged()" class="admin-input"></label>
                                                        <label class="admin-label">Same-day check out<input type="time" :value="line.check_out.slice(11,16)" @input="line.check_out = datePart(line.check_in) + ' ' + $event.target.value; onInputChanged()" class="admin-input"></label>
                                                        <p class="text-xs">One full room base rate, no automatic early or late fee.</p>
                                                    </div>
                                                    <label class="admin-label">Staff-arranged early check in (hours)</label>
                                                <select x-model.number="line.early_check_in_hours" @change="onInputChanged()" class="admin-input mt-1">
                                                    <option value="0">Standard (None)</option>
                                                    <option value="1">1 hour</option>
                                                    <option value="2">2 hours</option>
                                                    <template x-for="hours in [3,4,5,6,7,8,9,10,11,12]" :key="hours"><option :value="hours" x-text="hours + ' hours (staff arrangement)'"></option></template>
                                                </select>
                                            </div>
                                        </template>
                                    </div>

                                    <div class="pt-2 flex justify-between items-center text-xs border-t border-border">
                                        <div class="flex items-center gap-1.5 font-medium">
                                            <template x-if="checking">
                                                <span class="text-text-muted">Checking…</span>
                                            </template>
                                            <template x-if="!checking && lineEstimate(index) !== null">
                                                <span :class="lineIsAvailable(index) ? 'text-success font-semibold' : 'text-danger font-semibold'" x-text="lineStatusText(index)"></span>
                                            </template>
                                            <template x-if="!checking && lineEstimate(index) === null">
                                                <span class="text-text-muted">Choose schedule to verify</span>
                                            </template>
                                        </div>
                                        <span class="font-bold text-sm text-text" x-text="formatPesos(lineEstimate(index))"></span>
                                    </div>
                                </div>
                            </template>
                        </div>

                        <div class="flex items-center justify-between pt-3 border-t border-border">
                            <button type="button" @click="step = 2" class="text-xs font-semibold text-text-muted hover:underline">&larr; Back to catalog</button>
                            <button type="button" @click="goToStep(4)" class="px-5 py-2.5 rounded-lg bg-primary text-white font-semibold hover:bg-primary-hover transition text-sm">
                                Review &amp; Create &rarr;
                            </button>
                        </div>
                    </div>
                </div>

                {{-- STAGE 4: Review, Overrides & Finalization --}}
                <div x-show="step === 4" class="rounded-xl border border-border bg-surface p-6 shadow-sm space-y-5">
                    <div>
                        <h2 class="font-heading text-lg font-semibold text-text">4. Review &amp; Create Walk-in</h2>
                        <p class="text-xs text-text-muted">Verify guest details, overall stay window, and line quotations before saving.</p>
                    </div>

                    {{-- Guest Summary Card --}}
                    <div class="p-4 bg-muted/30 rounded-xl border border-border space-y-1 text-xs">
                        <span class="font-bold uppercase tracking-wider text-primary">Guest Identification</span>
                        <p class="text-sm font-bold text-text" x-text="guest.guest_name"></p>
                        <p class="text-text-muted" x-text="guest.guest_email + ' · ' + guest.guest_phone + ' · ' + guest.guest_place_of_origin"></p>
                    </div>

                    {{-- Service Line Summary --}}
                    <div class="divide-y divide-border">
                        <template x-for="(line, index) in lines" :key="index">
                            <div class="py-3 flex items-center justify-between text-xs">
                                <div>
                                    <p class="font-bold text-text text-sm" x-text="facilityName(line.facility_id)"></p>
                                    <p class="text-text-muted" x-text="lineScheduleLabel(line) + ' · ' + line.guest_count + ' guests'"></p>
                                </div>
                                <div class="text-right">
                                    <p class="font-bold text-text text-sm" x-text="formatPesos(lineEstimate(index))"></p>
                                    <span :class="lineIsAvailable(index) ? 'text-success' : 'text-danger'" x-text="lineIsAvailable(index) ? 'Verified Available' : 'Conflict Detected'"></span>
                                </div>
                            </div>
                        </template>
                    </div>

                    {{-- Creation Mode Switch --}}
                    <div class="p-4 rounded-xl border border-border bg-surface space-y-2">
                        <label class="admin-label font-semibold">Walk-in Approval Action</label>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                            <label class="flex items-start gap-2 p-3 rounded-lg border border-border cursor-pointer hover:bg-muted" :class="creationAction === 'approve' ? 'border-primary ring-1 ring-primary bg-primary-light/10' : ''">
                                <input type="radio" value="approve" x-model="creationAction" class="mt-0.5 text-primary">
                                <div>
                                    <span class="font-bold text-text">Create and Approve Immediately</span>
                                    <p class="text-text-muted mt-0.5">Locks physical occupancy immediately. Suitable when front desk confirms availability.</p>
                                </div>
                            </label>
                            <label class="flex items-start gap-2 p-3 rounded-lg border border-border cursor-pointer hover:bg-muted" :class="creationAction === 'pending' ? 'border-primary ring-1 ring-primary bg-primary-light/10' : ''">
                                <input type="radio" value="pending" x-model="creationAction" class="mt-0.5 text-primary">
                                <div>
                                    <span class="font-bold text-text">Create Pending Request</span>
                                    <p class="text-text-muted mt-0.5">Saves into reservation decision queue without immediate formal approval.</p>
                                </div>
                            </label>
                        </div>
                    </div>

                    {{-- Staff Notes --}}
                    <div>
                        <label class="block text-sm"><input type="checkbox" x-model="petsIncluded"> Pets included: <x-amount :value="500" /> once per booking; diapers and cage or leash required; no pool entry.</label>
                        <label class="admin-label">Internal front desk notes</label>
                        <textarea x-model="adminNotes" rows="2" maxlength="1000" class="admin-input mt-1 text-xs" placeholder="Onsite remarks, early check-in justifications, payment details…"></textarea>
                    </div>

                    {{-- Controlled Administrative Override Mechanism --}}
                    <div class="rounded-xl border border-warning/40 bg-warning/5 p-4 space-y-2">
                        <div class="flex items-center justify-between">
                            <label class="text-xs font-bold uppercase tracking-wider text-text flex items-center gap-1.5">
                                <svg class="w-4 h-4 text-warning" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                Administrative Override Request
                            </label>
                            <span class="text-[11px] text-text-muted">Requires mandatory audit reason</span>
                        </div>
                        <input type="text" x-model="overrideReason" maxlength="1000" placeholder="Management justification for operational scheduling exception…" class="admin-input text-xs">
                        <p class="text-[11px] text-text-muted">Overrides do not allow double occupancy on closed facilities; all exceptions are logged in the back-office audit trail.</p>
                    </div>

                    <div class="flex items-center justify-between pt-3 border-t border-border">
                        <button type="button" @click="step = 3" class="text-xs font-semibold text-text-muted hover:underline">&larr; Back to schedules</button>
                        <button type="button" @click="submitWalkIn()" :disabled="submitting || lines.length === 0" class="px-6 py-3 rounded-lg bg-primary text-white font-semibold hover:bg-primary-hover disabled:opacity-50 transition shadow-sm text-sm">
                            <span x-show="!submitting" x-text="creationAction === 'approve' ? 'Create Approved Walk-in Booking' : 'Create Pending Booking Request'"></span>
                            <span x-show="submitting">Saving grouped booking…</span>
                        </button>
                    </div>
                </div>

            </div>

            {{-- Right Column: Persistent Sticky Basket --}}
            <div class="sticky top-20 rounded-xl border border-border bg-surface p-6 shadow-sm space-y-4">
                <div class="flex items-center justify-between border-b border-border pb-3">
                    <h3 class="font-heading text-lg font-semibold text-text">Booking Basket</h3>
                    <button type="button" @click="checkAvailability()" :disabled="checking" class="text-xs font-semibold text-primary hover:underline flex items-center gap-1">
                        <svg class="w-3.5 h-3.5" :class="{ 'animate-spin': checking }" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                        Revalidate
                    </button>
                </div>

                {{-- Basket Mini Line Items --}}
                <div class="space-y-3 max-h-64 overflow-y-auto divide-y divide-border">
                    <template x-for="(line, index) in lines" :key="index">
                        <div class="pt-2 first:pt-0 space-y-1">
                            <div class="flex items-start justify-between gap-2">
                                <span class="font-semibold text-xs text-text truncate" x-text="facilityName(line.facility_id)"></span>
                                <span class="text-xs font-bold text-text" x-text="formatPesos(lineEstimate(index))"></span>
                            </div>
                            <div class="flex items-center justify-between text-[11px] text-text-muted">
                                <span x-text="line.guest_count + ' pax'"></span>
                                <span :class="lineIsAvailable(index) ? 'text-success' : 'text-danger'" x-text="lineStatusText(index)"></span>
                            </div>
                        </div>
                    </template>
                </div>

                <div class="space-y-2 text-sm border-t border-border pt-4">
                    <div class="flex justify-between text-text-muted">
                        <span>Selected lines</span>
                        <span class="font-semibold text-text" x-text="lines.length"></span>
                    </div>
                    <div class="flex justify-between text-text-muted">
                        <span>Overall stay</span>
                        <span class="text-xs font-medium text-text text-right" x-text="overallStayLabel"></span>
                    </div>
                    <div class="flex justify-between text-text-muted pt-1 border-t border-border">
                        <span class="font-bold text-text">Total Quoted</span>
                        <span class="font-bold text-lg text-primary" x-text="formatPesos(totalEstimate)"></span>
                    </div>
                    <div class="flex justify-between text-xs text-text-muted">
                        <span>Required 50% Deposit</span>
                        <span class="font-semibold text-text" x-text="formatPesos(Math.ceil(Math.round(totalEstimate * 100) / 2) / 100)"></span>
                    </div>
                </div>

                <div class="pt-2">
                    <button type="button" @click="submitWalkIn()" :disabled="submitting || lines.length === 0" class="w-full py-3 rounded-lg bg-primary text-white font-semibold hover:bg-primary-hover disabled:opacity-50 transition shadow-sm text-sm">
                        <span x-show="!submitting" x-text="creationAction === 'approve' ? 'Create &amp; Approve' : 'Submit Pending'"></span>
                        <span x-show="submitting">Processing walk-in…</span>
                    </button>
                </div>
            </div>

        </div>
    </div>

    <script type="application/json" id="walk-in-config">@json($walkInConfig)</script>

    <script>
        document.addEventListener('alpine:init', () => {
            const config = JSON.parse(document.getElementById('walk-in-config').textContent);

            Alpine.data('walkInWorkspace', () => ({
                config: config,
                step: 1,
                today: config.today,
                lines: [],
                searchQuery: '',
                searchResults: [],
                categoryFilter: 'All',
                detailFacility: null,
                detailOpener: null,
                evaluation: null,
                checking: false,
                submitting: false,
                errorMessage: '',
                adminNotes: '',
                petsIncluded: false,
                overrideReason: '',
                creationAction: 'approve',
                guest: {
                    existing_user_id: null,
                    guest_name: '',
                    guest_email: '',
                    guest_phone: '',
                    guest_place_of_origin: '',
                    guest_nationality: 'Filipino',
                    guest_company: '',
                    guest_valid_id: '',
                    guest_gender: '',
                },

                get filteredFacilities() {
                    return this.categoryFilter === 'All'
                        ? this.config.facilities
                        : this.config.facilities.filter(f => f.type === this.categoryFilter);
                },

                init() {
                    const defaultFac = this.config.facilities[0];
                    if (defaultFac) {
                        this.addLine(defaultFac.id);
                    }
                },

                openDetails(facility, opener) {
                    this.detailFacility = facility;
                    this.detailOpener = opener;
                    this.$nextTick(() => this.$refs.detailSheet?.focus());
                },

                closeDetails() {
                    this.detailFacility = null;
                    this.$nextTick(() => this.detailOpener?.focus());
                },

                trapDetailFocus(event) {
                    const controls = [...this.$refs.detailSheet.querySelectorAll('button:not([disabled]), a[href], input, select')];
                    if (!controls.length) return;
                    const first = controls[0], last = controls[controls.length - 1];
                    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
                    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
                },

                addLine(facilityId) {
                    if (this.lines.some(l => Number(l.facility_id) === Number(facilityId))) return;
                    const defaultFac = this.facility(facilityId);
                    this.lines.push({
                        facility_id: defaultFac ? defaultFac.id : '',
                        check_in: defaultFac && defaultFac.is_room ? (this.today + ' 14:00') : '',
                        check_out: defaultFac && defaultFac.is_room ? (new Date(Date.now() + 86400000).toISOString().slice(0, 10) + ' 12:00') : '',
                        date: this.today,
                        swimming_session_id: this.config.sessions[0]?.id || '',
                        session: this.config.sessions[0]?.slug || '',
                        hall_slot: '09:00|13:00',
                        guest_count: 1,
                        infants_count: 0,
                        children_count: 0,
                        early_check_in_hours: 0,
                        late_check_out_hours: 0,
                    });
                    this.onInputChanged();
                },

                removeLine(index) {
                    this.lines.splice(index, 1);
                    this.onInputChanged();
                },

                facility(id) {
                    return this.config.facilities.find(f => f.id === Number(id));
                },

                facilityName(id) {
                    return this.facility(id)?.name || 'Selected space';
                },

                isCottage(id) { return Boolean(this.facility(id)?.is_cottage); },
                isRoom(id) { return Boolean(this.facility(id)?.is_room); },
                isHall(id) { return Boolean(this.facility(id)?.is_hall); },
                isPackage(id) { return Boolean(this.facility(id)?.is_package); },
                maxCapacity(id) { return this.facility(id)?.max_capacity || 10; },

                datePart(value) { return value ? String(value).slice(0, 10) : ''; },

                setHallSlot(line, date, slot) {
                    if (!date || !slot) return;
                    const [start, end] = slot.split('|');
                    line.hall_slot = slot;
                    line.check_in = date + ' ' + start;
                    line.check_out = date + ' ' + end;
                },

                setPackageDate(line, date) {
                    const schedule = this.facility(line.facility_id)?.schedule;
                    if (!date || !schedule?.start || !schedule?.end) return;
                    line.check_in = date + ' ' + schedule.start;
                    const end = new Date(date + 'T12:00:00');
                    if (schedule.overnight) end.setDate(end.getDate() + 1);
                    line.check_out = end.toISOString().slice(0, 10) + ' ' + schedule.end;
                },

                onInputChanged() {
                    clearTimeout(this.timer);
                    this.timer = setTimeout(() => this.checkAvailability(), 400);
                },

                async checkAvailability() {
                    if (this.lines.length === 0) return;
                    this.checking = true;
                    try {
                        const res = await fetch(this.config.availabilityUrl, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': this.config.csrfToken,
                            },
                            body: JSON.stringify({ lines: this.lines }),
                        });
                        const data = await res.json();
                        if (res.ok && data.success) {
                            this.evaluation = data.data;
                        }
                    } catch (e) {
                        // ignore background errors
                    } finally {
                        this.checking = false;
                    }
                },

                get totalEstimate() {
                    return this.evaluation ? this.evaluation.total_estimate + (this.petsIncluded ? 500 : 0) : 0;
                },

                get overallStayLabel() {
                    if (!this.evaluation || !this.evaluation.visit_window) return 'Calculated on schedule';
                    return this.evaluation.visit_window.start.slice(0, 10) + ' to ' + this.evaluation.visit_window.end.slice(0, 10);
                },

                lineEstimate(index) {
                    return this.evaluation?.lines?.[index]?.quoted_amount ?? null;
                },

                lineIsAvailable(index) {
                    return Boolean(this.evaluation?.lines?.[index]?.is_available);
                },

                lineStatusText(index) {
                    const l = this.evaluation?.lines?.[index];
                    if (!l) return 'Checking…';
                    return l.is_available ? 'Available' : ((l.status || 'Unavailable') + ': ' + (l.reason || 'Choose a different period.'));
                },

                lineCardBorderClass(index) {
                    if (!this.evaluation?.lines?.[index]) return '';
                    return this.lineIsAvailable(index) ? 'border-success/60' : 'border-danger/60';
                },

                lineScheduleLabel(line) {
                    if (this.isCottage(line.facility_id)) {
                        return (line.date || 'Date') + ' · Session ' + (line.swimming_session_id || 'unselected');
                    }
                    return (line.check_in || 'Check-in') + ' to ' + (line.check_out || 'Check-out');
                },

                formatPesos(amount) {
                    return window.pesos(amount);
                },

                async searchGuest() {
                    if (this.searchQuery.trim().length < 2) {
                        this.searchResults = [];
                        return;
                    }
                    try {
                        const res = await fetch(this.config.lookupUrl + '?query=' + encodeURIComponent(this.searchQuery));
                        const data = await res.json();
                        this.searchResults = data.guests || [];
                    } catch (e) {
                        this.searchResults = [];
                    }
                },

                selectGuest(cust) {
                    this.guest.existing_user_id = cust.id;
                    this.guest.guest_name = cust.name;
                    this.guest.guest_email = cust.email;
                    this.guest.guest_phone = (cust.phone || '').replace(/[\s-]/g, '');
                    this.guest.guest_place_of_origin = cust.address || '';
                    this.guest.guest_nationality = cust.nationality || 'Filipino';
                    this.guest.guest_company = cust.company || '';
                    this.guest.guest_valid_id = cust.valid_id_type || '';
                    this.guest.guest_gender = cust.gender || '';
                    this.searchResults = [];
                    this.searchQuery = cust.name;
                },

                validateGuestFields() {
                    const invalid = this.$refs.guestFields.querySelector('input:invalid, select:invalid');
                    if (!invalid) return true;

                    this.step = 1;
                    this.$nextTick(() => invalid.reportValidity());
                    return false;
                },

                goToStep(step) {
                    if (!this.validateGuestFields()) return;
                    if (step >= 3 && !this.lines.length) {
                        this.step = 2;
                        return;
                    }
                    if (step === 4 && this.step === 3) {
                        const invalid = [...this.$refs.scheduleFields.querySelectorAll('input:invalid, select:invalid')]
                            .find(field => field.getClientRects().length);
                        if (invalid) {
                            invalid.reportValidity();
                            return;
                        }
                    }
                    this.step = step;
                },

                async submitWalkIn() {
                    if (!this.validateGuestFields()) return;
                    this.submitting = true;
                    this.errorMessage = '';

                    try {
                        const res = await fetch(this.config.storeUrl, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': this.config.csrfToken,
                            },
                            body: JSON.stringify({
                                lines: this.lines,
                                admin_notes: this.adminNotes,
                                pets_included: this.petsIncluded,
                                override_reason: this.overrideReason,
                                creation_action: this.creationAction,
                                ...this.guest,
                            }),
                        });

                        const data = await res.json();
                        if (res.ok && data.success) {
                            window.location.href = data.redirect_url;
                        } else {
                            this.errorMessage = Object.values(data.errors || {}).flat()[0] || data.message || 'Walk-in submission failed.';
                        }
                    } catch (e) {
                        this.errorMessage = 'Network connection failed.';
                    } finally {
                        this.submitting = false;
                    }
                }
            }));
        });
    </script>
</x-admin-layout>
