<x-customer-layout>
    <x-slot name="title">New reservation · {{ config('resort.name') }}</x-slot>

    @php
        $workspaceConfig = [
            'facilities' => $bookableFacilities->map(fn($f) => [
                'id' => $f->id,
                'name' => $f->name,
                'type' => $f->facilityType?->slug,
                'type_label' => match ($f->facilityType?->slug) {
                    'room' => 'Room', 'function_hall' => 'Function Halls',
                    'resort_package' => 'Resort Packages', 'cottage' => 'Cottage',
                    default => $f->facilityType?->name ?? 'Service',
                },
                'max_capacity' => $f->maxGuestCount(),
                'included_capacity' => $f->includedGuestCount(),
                'pax_tiers' => $f->paxTiers(),
                'is_cottage' => $f->isCottage(),
                'is_hall' => $f->isFunctionHall(),
                'is_room' => $f->isRoom(),
                'is_package' => $f->isPackage(),
                'description' => $f->description,
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
            'paymentDestinations' => $paymentDestinations->map(fn($d) => [
                'id' => $d->id,
                'method' => $d->method->value,
                'method_label' => $d->method->label(),
                'account_name' => $d->account_name,
                'account_number' => $d->account_number,
                'bank' => $d->bank,
                'qr_url' => $d->qr_path ? route('payment-destinations.image', $d, false) : null,
                'qr_download_url' => $d->qr_path ? route('payment-destinations.image', [$d, 'download' => 1], false) : null,
            ]),
            'policyVersion' => $policyVersion,
            'initialLines' => $initialLines,
            'initialCategory' => request()->query('type', 'selected'),
            'guestProfile' => $guestProfile,
            'availabilityUrl' => route('customer.grouped-booking.availability'),
            'intentUrl' => route('reservation.intent'),
            'loginUrl' => route('login'),
            'registerUrl' => route('register'),
            'isAuthenticated' => auth()->check(),
            'resumeAfterAuth' => request()->boolean('resume'),
            'csrfToken' => csrf_token(),
            'today' => \App\Support\ResortTime::today()->toDateString(),
        ];
    @endphp

    <div x-data="unifiedBookingWorkspace()" data-unified-booking-workspace class="bg-background min-h-[600px] pt-5 pb-32 sm:pt-9 lg:pb-12">

        {{-- Service Details Slide-over Sheet with Centered Gallery --}}
        <div :hidden="!detailFacility" x-cloak @keydown.escape.window="closeDetails()" @keydown.tab="trapDetailFocus($event)" @popstate.window="closeDetails(false)" class="fixed inset-0 z-[80] overflow-hidden" role="presentation">
            <div class="absolute inset-0 bg-black/70 backdrop-blur-xs transition-opacity" @click="closeDetails()"></div>
            <section role="dialog" aria-modal="true" aria-labelledby="service-detail-title" class="absolute inset-y-0 right-0 w-full sm:max-w-2xl overflow-y-auto bg-surface shadow-2xl p-6 sm:p-8 flex flex-col justify-between" x-ref="detailSheet" tabindex="-1">
                <div>
                    {{-- Header --}}
                    <div class="flex items-start justify-between gap-4 border-b border-border pb-4">
                        <div>
                            <span class="text-xs font-bold uppercase tracking-wider text-primary" x-text="detailFacility?.type_label"></span>
                            <h2 id="service-detail-title" class="font-heading text-2xl sm:text-3xl font-bold text-text mt-1" x-text="detailFacility?.name"></h2>
                        </div>
                        <button type="button" @click="closeDetails()" class="grid size-11 shrink-0 place-items-center rounded-xl border border-border text-text-muted hover:text-text hover:bg-muted focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" aria-label="Close service details" x-ref="detailClose">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>

                    {{-- Centered gallery with adjacent images available to click, swipe, or arrow through. --}}
                    <template x-if="detailFacility?.photos?.length">
                    <div class="mt-6"
                         @keydown.left.prevent="prevPhoto()"
                         @keydown.right.prevent="nextPhoto()"
                         @touchstart="touchStart($event)"
                         @touchend="touchEnd($event)">
                        <div class="relative flex h-56 items-center justify-center overflow-hidden rounded-2xl bg-text/95 sm:h-80" aria-label="Service photos">
                            <template x-for="(photo, pIndex) in detailFacility.photos" :key="pIndex">
                                <button type="button" x-show="photoPosition(pIndex) !== 'hidden'" @click="activePhotoIndex = pIndex"
                                        :aria-label="'Show photo ' + (pIndex + 1) + ' of ' + detailFacility.photos.length"
                                        :aria-current="activePhotoIndex === pIndex ? 'true' : null"
                                        class="absolute h-[78%] w-[72%] overflow-hidden rounded-xl border-2 border-white/70 shadow-2xl transition-all duration-300 ease-out focus-visible:!z-30 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white motion-reduce:transition-none"
                                        :class="photoPosition(pIndex) === 'active' ? 'z-20 scale-100 opacity-100' : photoPosition(pIndex) === 'previous' ? 'z-10 -translate-x-[43%] scale-75 opacity-70 hover:opacity-90' : 'z-10 translate-x-[43%] scale-75 opacity-70 hover:opacity-90'">
                                    <img :src="photo" :alt="detailFacility.name + ' photo ' + (pIndex + 1)" class="h-full w-full bg-black object-contain" loading="lazy">
                                </button>
                            </template>
                            <template x-if="detailFacility?.photos.length > 1">
                                <div class="contents">
                                    <button type="button" @click="prevPhoto()" class="absolute bottom-3 left-3 z-30 flex h-11 w-11 items-center justify-center rounded-full bg-black/75 text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white" aria-label="Previous photo">←</button>
                                    <button type="button" @click="nextPhoto()" class="absolute bottom-3 right-3 z-30 flex h-11 w-11 items-center justify-center rounded-full bg-black/75 text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white" aria-label="Next photo">→</button>
                                </div>
                            </template>
                        </div>
                        <p role="status" class="mt-2 text-center text-xs text-text-muted" x-show="detailFacility?.photos.length > 1" x-text="detailFacility ? 'Photo ' + (activePhotoIndex + 1) + ' of ' + detailFacility.photos.length : ''"></p>
                    </div>
                    </template>
                    <div x-show="detailFacility && !detailFacility.photos.length" class="mt-6 flex h-44 items-center justify-center rounded-xl border border-dashed border-border bg-muted text-sm text-text-muted">No photo published for this service</div>

                    {{-- Readable Grouped Sections --}}
                    <div class="mt-6 space-y-6">
                        {{-- Overview & Description --}}
                        <div class="p-4 rounded-xl bg-muted/30 border border-border">
                            <span class="text-xs font-bold uppercase tracking-wider text-text-muted">Overview</span>
                            <p class="mt-1.5 text-sm text-text leading-relaxed" x-text="detailFacility?.description || 'Description not yet published.'"></p>
                        </div>

                        {{-- Human-Friendly Schedule & Standard Timing --}}
                        <div class="p-4 rounded-xl border border-border bg-surface">
                            <span class="text-xs font-bold uppercase tracking-wider text-primary">Standard Schedule &amp; Hours</span>
                            <p class="text-base font-semibold text-text mt-1" x-text="humanSchedule(detailFacility)"></p>
                            <p class="text-xs text-text-muted mt-1">Philippine Time (Asia/Manila). Schedules apply when setting reservation dates.</p>
                        </div>

                        {{-- Pricing and Capacity Inclusions --}}
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div class="p-4 rounded-xl border border-border bg-surface">
                                <span class="text-xs font-bold uppercase tracking-wider text-text-muted">Standard Rate</span>
                                <p class="text-lg font-bold text-primary mt-1" x-text="detailFacility?.rate ? formatPesos(detailFacility.rate.amount) + ' ' + detailFacility.rate.unit : 'Rate available on request'"></p>
                                <p class="text-xs text-text-muted mt-0.5" x-text="detailFacility?.is_hall ? 'Function hall quotation or package tiers' : 'Base rate per booked schedule'"></p>
                            </div>
                            <div class="p-4 rounded-xl border border-border bg-surface">
                                <span class="text-xs font-bold uppercase tracking-wider text-text-muted">Guest Capacity</span>
                                <p class="text-lg font-bold text-text mt-1" x-text="detailFacility ? detailFacility.included_capacity + ' included (up to ' + detailFacility.max_capacity + ')' : ''"></p>
                                <p class="text-xs text-text-muted mt-0.5">Review the final guest count and price before payment.</p>
                            </div>
                        </div>

                        {{-- Amenities & Inclusions --}}
                        <div class="p-4 rounded-xl border border-border bg-surface">
                            <span class="text-xs font-bold uppercase tracking-wider text-text-muted">Amenities &amp; Inclusions</span>
                            <template x-if="detailFacility?.amenities && detailFacility.amenities.length > 0">
                                <div class="mt-2 flex flex-wrap gap-2">
                                    <template x-for="amenity in detailFacility.amenities" :key="amenity">
                                        <span class="px-2.5 py-1 rounded-lg bg-muted text-xs font-medium text-text" x-text="amenity"></span>
                                    </template>
                                </div>
                            </template>
                            <template x-if="!detailFacility?.amenities || detailFacility.amenities.length === 0">
                                <p class="mt-1 text-xs text-text-muted">No inclusions published for this service.</p>
                            </template>
                        </div>
                    </div>
                </div>

                {{-- Add to booking button --}}
                <div class="pt-6 border-t border-border mt-6">
                    <button type="button" @click="addLine(detailFacility.id); closeDetails()" :disabled="lines.some(line => Number(line.facility_id) === detailFacility?.id)" class="w-full rounded-xl bg-primary px-4 py-3.5 font-bold text-white hover:bg-primary-hover transition shadow-sm disabled:opacity-50 text-sm">
                        <span x-text="lines.some(line => Number(line.facility_id) === detailFacility?.id) ? 'Already Added to Booking' : 'Add to Booking Request'"></span>
                    </button>
                </div>
            </section>
        </div>

        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
            <header class="mb-4 grid gap-3 border-b border-border pb-4 sm:mb-6 sm:gap-5 sm:pb-5 lg:mb-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,24rem)] lg:items-center lg:gap-8">
                <div>
                    <h1 class="font-heading text-2xl font-bold tracking-tight text-text sm:text-4xl">New reservation</h1>
                    <p class="mt-1 text-sm leading-relaxed text-text-muted sm:text-base">Book rooms, cottages, function halls, and resort packages together.</p>
                </div>
                <nav aria-label="Booking steps">
                <ol class="grid grid-cols-4 gap-1.5 text-center">
                    <li :aria-current="step === 1 ? 'step' : null" class="px-2 flex flex-col items-center gap-1.5">
                        <span class="w-7 h-7 rounded-full flex items-center justify-center font-bold text-xs"
                              :class="step === 1 ? 'bg-primary text-white ring-4 ring-primary/20' : (step > 1 ? 'bg-success text-white' : 'bg-muted text-text-muted')">
                            <template x-if="step > 1"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg></template>
                            <template x-if="step <= 1"><span>1</span></template>
                        </span>
                        <span class="text-xs font-semibold" :class="step === 1 ? 'text-primary' : 'text-text-muted'">Services</span>
                    </li>
                    <li :aria-current="step === 2 ? 'step' : null" class="px-2 flex flex-col items-center gap-1.5">
                        <span class="w-7 h-7 rounded-full flex items-center justify-center font-bold text-xs"
                              :class="step === 2 ? 'bg-primary text-white ring-4 ring-primary/20' : (step > 2 ? 'bg-success text-white' : 'bg-muted text-text-muted')">
                            <template x-if="step > 2"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg></template>
                            <template x-if="step <= 2"><span>2</span></template>
                        </span>
                        <span class="text-xs font-semibold" :class="step === 2 ? 'text-primary' : 'text-text-muted'">Guest details</span>
                    </li>
                    <li :aria-current="step === 3 ? 'step' : null" class="px-2 flex flex-col items-center gap-1.5">
                        <span class="w-7 h-7 rounded-full flex items-center justify-center font-bold text-xs"
                              :class="step === 3 ? 'bg-primary text-white ring-4 ring-primary/20' : (step > 3 ? 'bg-success text-white' : 'bg-muted text-text-muted')">
                            <template x-if="step > 3"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg></template>
                            <template x-if="step <= 3"><span>3</span></template>
                        </span>
                        <span class="text-xs font-semibold" :class="step === 3 ? 'text-primary' : 'text-text-muted'">Review</span>
                    </li>
                    <li :aria-current="step === 4 ? 'step' : null" class="px-2 flex flex-col items-center gap-1.5">
                        <span class="w-7 h-7 rounded-full flex items-center justify-center font-bold text-xs"
                              :class="step === 4 ? 'bg-primary text-white ring-4 ring-primary/20' : 'bg-muted text-text-muted'">
                            4
                        </span>
                        <span class="text-xs font-semibold" :class="step === 4 ? 'text-primary' : 'text-text-muted'">Payment</span>
                    </li>
                </ol>
                </nav>
            </header>

            {{-- Error Summary --}}
            <div x-show="errorMessage" x-cloak class="mb-6 rounded-2xl border border-danger/40 bg-danger-light p-4 text-danger flex items-start gap-3" role="alert" tabindex="-1" x-ref="errorSummary">
                <svg class="w-5 h-5 shrink-0 mt-0.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                <div class="flex-1">
                    <p class="font-bold text-sm">Booking needs attention</p>
                    <p class="text-sm mt-0.5" x-text="errorMessage"></p>
                </div>
                <button type="button" @click="errorMessage = ''" class="text-danger hover:opacity-75 font-bold" aria-label="Dismiss">&times;</button>
            </div>

            <div class="grid grid-cols-1 items-start"
                 :class="step === 1 ? 'gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(19rem,0.53fr)] lg:gap-8' : 'mx-auto max-w-4xl gap-4'">
                
                {{-- Step content --}}
                <div class="min-w-0 space-y-6">

                    {{-- STEP 1: Services Picker & Controls --}}
                    <div :hidden="step !== 1" class="space-y-4 sm:space-y-6">
                        <h2 tabindex="-1" id="booking-step-1" class="text-lg font-bold text-text sm:text-xl">Choose your services</h2>

                        {{-- Service Categories Filter: Selected services, Room, Function Halls, Resort Packages, Cottage --}}
                        <div class="flex flex-wrap gap-2" role="group" aria-label="Service categories">
                            <template x-for="cat in categories" :key="cat.slug">
                                <button type="button" @click="categoryFilter = cat.slug" :aria-pressed="categoryFilter === cat.slug"
                                        class="min-h-11 rounded-lg border px-3 text-sm font-semibold transition-colors motion-reduce:transition-none"
                                        :class="categoryFilter === cat.slug ? 'border-primary bg-primary-light text-primary' : 'border-border bg-surface text-text hover:bg-muted'">
                                    <span x-text="cat.label"></span>
                                    <template x-if="cat.slug === 'selected' && lines.length > 0">
                                        <span class="ml-1 rounded-full bg-surface px-1.5 py-0.5 text-xs font-bold text-primary" x-text="lines.length"></span>
                                    </template>
                                </button>
                            </template>
                        </div>

                        {{-- Category View: Selected Services (Empty state or cards) --}}
                        <template x-if="categoryFilter === 'selected' && lines.length === 0">
                            <div class="rounded-2xl border border-dashed border-border bg-surface p-5 text-center space-y-3 shadow-xs sm:p-10">
                                <div class="w-12 h-12 rounded-full bg-muted mx-auto flex items-center justify-center text-text-muted">
                                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
                                </div>
                                <h3 class="font-heading text-lg font-bold text-text">No services selected yet</h3>
                                <p class="text-sm text-text-muted max-w-md mx-auto">Select a category above &mdash; <strong>Room</strong>, <strong>Function Halls</strong>, <strong>Resort Packages</strong>, or <strong>Cottage</strong> &mdash; to add services to your stay.</p>
                                <div class="pt-2 flex flex-wrap justify-center gap-2">
                                    <button type="button" @click="categoryFilter = 'room'" class="px-3 py-1.5 rounded-lg border border-border text-xs font-semibold hover:bg-muted">Browse Rooms</button>
                                    <button type="button" @click="categoryFilter = 'function_hall'" class="px-3 py-1.5 rounded-lg border border-border text-xs font-semibold hover:bg-muted">Browse Function Halls</button>
                                    <button type="button" @click="categoryFilter = 'cottage'" class="px-3 py-1.5 rounded-lg border border-border text-xs font-semibold hover:bg-muted">Browse Cottages</button>
                                </div>
                            </div>
                        </template>

                        {{-- Catalog Cards Grid (when viewing a specific category) --}}
                        <div class="grid gap-4 sm:grid-cols-2" x-show="categoryFilter !== 'selected'">
                            <template x-for="fac in filteredFacilities" :key="fac.id">
                                <article class="flex min-w-0 flex-col justify-between rounded-xl border border-border bg-surface sm:rounded-2xl sm:shadow-xs">
                                    <div>
                                        <div class="relative h-32 w-full overflow-hidden rounded-t-xl bg-muted sm:h-44 sm:rounded-t-2xl">
                                            <template x-if="fac.photos.length">
                                                <img :src="fac.photos[0]" :alt="fac.name" class="h-full w-full object-cover" loading="lazy">
                                            </template>
                                            <template x-if="!fac.photos.length">
                                                <div class="h-full w-full flex items-center justify-center text-xs text-text-muted">Photo not available</div>
                                            </template>
                                            <span class="absolute top-2.5 left-2.5 px-2.5 py-1 rounded-full bg-black/60 backdrop-blur-xs text-[11px] font-bold text-white uppercase tracking-wider" x-text="fac.type_label"></span>
                                        </div>
                                        <div class="space-y-1 p-3 sm:space-y-2 sm:p-4">
                                            <h3 class="text-pretty font-heading text-base font-bold text-text sm:text-lg" x-text="fac.name"></h3>
                                            <p class="text-xs text-text-muted line-clamp-2" x-text="fac.description || 'Description not yet published.'"></p>
                                            <p class="text-xs text-text-muted font-medium" x-text="humanSchedule(fac)"></p>
                                            <p class="font-heading font-extrabold text-primary text-base" x-text="fac.rate ? formatPesos(fac.rate.amount) + ' ' + fac.rate.unit : 'Rate on request'"></p>
                                        </div>
                                    </div>
                                    <div class="flex gap-2 px-3 pb-3 sm:px-4 sm:pb-4">
                                        <button type="button" @click="openDetails(fac, $event.currentTarget)" :aria-label="'View details for ' + fac.name" aria-haspopup="dialog" class="min-h-11 flex-1 rounded-xl border border-border px-3 py-2 text-xs font-bold text-text hover:bg-muted focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">View details</button>
                                        <button type="button" @click="addLine(fac.id)" class="min-h-11 flex-1 rounded-xl bg-primary px-3 py-2 text-xs font-bold text-white hover:bg-primary-hover disabled:opacity-50 shadow-xs" :disabled="lines.some(line => Number(line.facility_id) === fac.id)">
                                            <span x-text="lines.some(line => Number(line.facility_id) === fac.id) ? 'Added' : 'Add to booking'"></span>
                                        </button>
                                    </div>
                                </article>
                            </template>
                        </div>

                        {{-- Selected services Header & Form Cards --}}
                        <div id="selected-services" x-ref="selectedServices" tabindex="-1" class="space-y-3 border-t border-border pt-4 sm:space-y-4 sm:pt-6">
                            <div class="flex items-start justify-between gap-2 sm:items-center">
                                <div>
                                    <h3 class="font-heading text-lg font-bold text-text sm:text-xl">Selected services</h3>
                                    <p class="text-sm text-text-muted">Choose a schedule and guest count for each service.</p>
                                </div>
                                <span class="shrink-0 rounded-full bg-primary/10 px-2.5 py-1 text-xs font-bold text-primary" x-text="lines.length + (lines.length === 1 ? ' service' : ' services')"></span>
                            </div>

                            <template x-if="lines.length === 0">
                                <div class="rounded-2xl border border-dashed border-border bg-surface p-8 text-center">
                                    <p class="text-sm text-text-muted">Add a service from the list above.</p>
                                </div>
                            </template>

                            {{-- Service Line Cards --}}
                            <div class="space-y-4">
                                <template x-for="(line, index) in lines" :key="line.facility_id">
                                    <div class="relative rounded-2xl border border-border bg-surface p-3.5 shadow-xs transition-all sm:p-6"
                                         :class="lineStatusClass(index)">
                                        <div class="mb-2 flex items-start justify-between gap-3 sm:mb-4 sm:gap-4">
                                            <div class="flex items-center gap-2.5">

                                                <div>
                                                    <h4 class="font-heading text-base font-bold text-text sm:text-lg" x-text="facilityName(line.facility_id)"></h4>
                                                    <p class="text-xs text-text-muted" x-text="humanSchedule(facility(line.facility_id))"></p>
                                                </div>
                                            </div>
                                            <button type="button" @click="removeLine(index)" :aria-label="'Remove ' + facilityName(line.facility_id)" class="min-h-12 text-text-muted hover:text-danger text-xs font-semibold flex items-center gap-1">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                                Remove
                                            </button>
                                        </div>

                                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 sm:gap-4">
                                            {{-- Conditional Controls for Cottages (Visit Date + Session) --}}
                                            <template x-if="isCottage(line.facility_id)">
                                                <div class="contents">
                                                    <div>
                                                        <label :for="'booking-visit-date-' + line.facility_id" class="mb-1 block text-sm font-semibold text-text">Visit date</label>
                                                        <input :id="'booking-visit-date-' + line.facility_id" type="date" required x-model="line.date" @change="onInputChanged(index)" :min="today" class="admin-input min-h-12 w-full !text-base">
                                                    </div>
                                                    <div>
                                                        <label :for="'booking-session-' + line.facility_id" class="mb-1 block text-sm font-semibold text-text">Swimming session</label>
                                                        <select :id="'booking-session-' + line.facility_id" required x-model.number="line.swimming_session_id" @change="onInputChanged(index)" class="admin-input min-h-12 w-full !text-base">
                                                            <option value="">Choose session…</option>
                                                            @foreach($swimmingSessions as $session)
                                                                <option value="{{ $session->id }}">{{ $session->name }} ({{ \Carbon\Carbon::parse($session->starts_at)->format('g:i A') }}–{{ \Carbon\Carbon::parse($session->ends_at)->format('g:i A') }})</option>
                                                            @endforeach
                                                        </select>
                                                    </div>
                                                </div>
                                            </template>

                                            {{-- Controls for Rooms --}}
                                            <template x-if="isRoom(line.facility_id)">
                                                <div class="contents">
                                                    <div>
                                                        <label :for="'booking-check-in-' + line.facility_id" class="mb-1 block text-sm font-semibold text-text">Check-in date</label>
                                                        <input :id="'booking-check-in-' + line.facility_id" type="date" required :value="datePart(line.check_in)" @input="setRoomDate(line, 'check_in', $event.target.value)" :min="today" class="admin-input min-h-12 w-full !text-base">
                                                    </div>
                                                    <div>
                                                        <label :for="'booking-check-out-' + line.facility_id" class="mb-1 block text-sm font-semibold text-text">Check-out date</label>
                                                        <input :id="'booking-check-out-' + line.facility_id" type="date" required :value="datePart(line.check_out)" @input="setRoomDate(line, 'check_out', $event.target.value)" :min="datePart(line.check_in) || today" class="admin-input min-h-12 w-full !text-base">
                                                    </div>
                                                    <div x-show="isSameDayRoom(line)" class="col-span-full grid gap-4 sm:grid-cols-2">
                                                        <div>
                                                            <label :for="'booking-start-time-' + line.facility_id" class="mb-1 block text-sm font-semibold">Same-day check-in time</label>
                                                            <input :id="'booking-start-time-' + line.facility_id" type="time" :required="isSameDayRoom(line)" :value="line.check_in.slice(11,16)" @input="line.check_in = datePart(line.check_in) + ' ' + $event.target.value; onInputChanged(index)" class="admin-input min-h-12 w-full !text-base">
                                                        </div>
                                                        <div>
                                                            <label :for="'booking-end-time-' + line.facility_id" class="mb-1 block text-sm font-semibold">Same-day check-out time</label>
                                                            <input :id="'booking-end-time-' + line.facility_id" type="time" :required="isSameDayRoom(line)" :value="line.check_out.slice(11,16)" @input="line.check_out = datePart(line.check_out) + ' ' + $event.target.value; onInputChanged(index)" class="admin-input min-h-12 w-full !text-base">
                                                        </div>
                                                        <p class="text-sm text-text-muted sm:col-span-2">Same-day stays use the full room rate.</p>
                                                    </div>
                                                    <p x-show="!isSameDayRoom(line)" class="col-span-full text-sm text-text-muted">Overnight stays start at 2 PM and end at noon on the check-out date. For a same-day stay, choose the same date twice.</p>
                                                    <p x-show="datePart(line.check_in) === today && !isSameDayRoom(line)" class="col-span-full text-sm text-text-muted">You can still book an available room today after 2 PM at the full overnight rate.</p>
                                                    <div x-show="line.check_in && line.check_out && !isSameDayRoom(line)">
                                                        <label :for="'booking-early-check-in-' + line.facility_id" class="mb-1 block text-sm font-semibold text-text">Early check-in (optional)</label>
                                                        <select :id="'booking-early-check-in-' + line.facility_id" x-model.number="line.early_check_in_hours" @change="onInputChanged(index)" class="admin-input min-h-12 w-full !text-base">
                                                            <option value="0">Standard 2:00 PM</option>
                                                            <option value="1">1 hour early (1:00 PM)</option>
                                                            <option value="2">2 hours early (12:00 PM)</option>
                                                        </select>
                                                    </div>
                                                </div>
                                            </template>

                                            {{-- Controls for Packages --}}
                                            <template x-if="!isRoom(line.facility_id) && !isCottage(line.facility_id) && facility(line.facility_id)?.schedule.mode === 'package'">
                                                <div class="contents">
                                                    <div>
                                                        <label :for="'booking-package-date-' + line.facility_id" class="mb-1 block text-sm font-semibold text-text">Package start date</label>
                                                        <input :id="'booking-package-date-' + line.facility_id" type="date" required :value="datePart(line.check_in)" @change="setPackageDate(line, $event.target.value); onInputChanged(index)" :min="today" class="admin-input min-h-12 w-full !text-base">
                                                        <p class="mt-1 text-[11px] text-text-muted" x-text="facility(line.facility_id)?.schedule.label || 'Published package schedule'"></p>
                                                    </div>
                                                </div>
                                            </template>

                                            {{-- Controls for Function Halls --}}
                                            <template x-if="!isRoom(line.facility_id) && !isCottage(line.facility_id) && isHall(line.facility_id)">
                                                <div class="contents">
                                                    <div>
                                                        <label :for="'booking-event-date-' + line.facility_id" class="mb-1 block text-sm font-semibold text-text">Event date</label>
                                                        <input :id="'booking-event-date-' + line.facility_id" type="date" required :value="datePart(line.check_in)" @change="setHallSlot(line, $event.target.value, line.hall_slot || (facility(line.facility_id)?.schedule.mode === 'opal' ? '09:00|17:00' : '09:00|13:00')); onInputChanged(index)" :min="today" class="admin-input min-h-12 w-full !text-base">
                                                    </div>
                                                    <div>
                                                        <label :for="'booking-event-block-' + line.facility_id" class="mb-1 block text-sm font-semibold text-text">Event time block</label>
                                                        <select :id="'booking-event-block-' + line.facility_id" x-model="line.hall_slot" @change="setHallSlot(line, datePart(line.check_in), line.hall_slot); onInputChanged(index)" class="admin-input min-h-12 w-full !text-base">
                                                            <template x-if="facility(line.facility_id)?.schedule.mode === 'opal'">
                                                                <optgroup label="Opal Hall Sessions">
                                                                    <option value="09:00|17:00">Day: 9:00 AM–5:00 PM</option>
                                                                    <option value="17:00|22:00">Night: 5:00 PM–10:00 PM</option>
                                                                </optgroup>
                                                            </template>
                                                            <template x-if="facility(line.facility_id)?.schedule.mode !== 'opal'">
                                                                <optgroup label="Standard 4-hour blocks">
                                                                    <option value="09:00|13:00">9:00 AM–1:00 PM</option>
                                                                    <option value="13:00|17:00">1:00 PM–5:00 PM</option>
                                                                    <option value="17:00|21:00">5:00 PM–9:00 PM</option>
                                                                </optgroup>
                                                            </template>
                                                        </select>
                                                    </div>
                                                </div>
                                            </template>

                                            <template x-if="isRoom(line.facility_id) || facility(line.facility_id)?.schedule.mode === 'package'">
                                                <label class="block text-sm font-semibold">Infants under 1 (free of charge)<input type="number" min="0" max="500" x-model.number="line.infants_count" @change="onInputChanged(index)" class="admin-input mt-1 min-h-12 w-full !text-base"></label>
                                            </template>
                                            <div>
                                                <label :for="'booking-guest-count-' + line.facility_id" class="mb-1 block text-sm font-semibold text-text">Guests</label>
                                                <input :id="'booking-guest-count-' + line.facility_id" :aria-describedby="isRoom(line.facility_id) || facility(line.facility_id)?.schedule.mode === 'package' ? 'booking-guest-count-help-' + line.facility_id : null" type="number" required x-model.number="line.guest_count" @change="onInputChanged(index)" min="1" :max="maxCapacity(line.facility_id)" class="admin-input min-h-12 w-full !text-base">
                                                <template x-if="isRoom(line.facility_id) || facility(line.facility_id)?.schedule.mode === 'package'">
                                                    <p :id="'booking-guest-count-help-' + line.facility_id" class="mt-1 text-sm text-text-muted">Count everyone aged 1 and over, including children.</p>
                                                </template>
                                                <template x-if="!isCottage(line.facility_id)">
                                                    <p class="mt-1 text-sm text-text-muted" x-text="capacityHint(line.facility_id)"></p>
                                                </template>
                                            </div>
                                        </div>

                                        <details class="mt-4 border-t border-border pt-2">
                                            <summary class="min-h-11 cursor-pointer py-3 text-sm font-semibold text-primary">
                                                <span x-text="line.special_requests.trim() ? 'Edit special request' : 'Add a special request'"></span>
                                                <span class="sr-only" x-text="' for ' + facilityName(line.facility_id)"></span>
                                            </summary>
                                            <label :for="'booking-service-request-' + line.facility_id" class="mb-1 block text-sm font-semibold">Special request (optional)</label>
                                            <textarea :id="'booking-service-request-' + line.facility_id" :aria-describedby="'booking-service-request-help-' + line.facility_id" x-model="line.special_requests" rows="3" maxlength="1000" class="admin-input w-full !text-base"></textarea>
                                            <p :id="'booking-service-request-help-' + line.facility_id" class="mt-1 text-sm text-text-muted">The resort will confirm whether it can accommodate your request.</p>
                                        </details>

                                        {{-- Line Estimate and Status --}}
                                        <div class="mt-4 flex items-center justify-between gap-3 border-t border-border pt-3 text-sm">
                                            <div>
                                                <p x-show="lineStatus(index) === 'incomplete'" class="text-text-muted" x-text="lineMissingDetails(line)"></p>
                                                <template x-if="lineStatus(index) === 'checking'">
                                                    <span class="text-text-muted flex items-center gap-1.5"><svg class="animate-spin w-3.5 h-3.5 text-primary" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg> Checking schedule…</span>
                                                </template>
                                                <template x-if="lineStatus(index) === 'available'">
                                                    <span class="text-success font-bold flex items-center gap-1"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg> Available</span>
                                                </template>
                                                <template x-if="lineStatus(index) === 'unavailable'">
                                                    <span class="text-danger font-semibold" x-text="lineReason(index)"></span>
                                                </template>
                                                <template x-if="lineStatus(index) === 'stale'">
                                                    <span class="text-warning font-medium">Availability needs checking</span>
                                                </template>
                                            </div>
                                            <div class="font-heading font-bold text-sm text-text">
                                                <template x-if="lineStatus(index) === 'available' && lineEstimate(index) !== null">
                                                    <span x-text="formatPesos(lineEstimate(index))"></span>
                                                </template>
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>

                    {{-- STEP 2: Guest Details (Auth Handoff or Account Sheet) --}}
                    <form id="booking-guest-form" x-ref="guestFields" method="post" aria-labelledby="booking-step-2" :hidden="step !== 2" @input="$event.target.setCustomValidity('')" @submit.prevent="continueToReview()">
                        <div class="bg-surface rounded-2xl border border-border p-6 sm:p-8 shadow-xs">
                            <h2 tabindex="-1" id="booking-step-2" class="font-heading text-2xl font-bold text-text">Guest details</h2>
                            <p x-show="config.isAuthenticated" class="mt-1 text-sm text-text-muted">Enter the details of the guest the resort should contact about this booking.</p>

                            <template x-if="!config.isAuthenticated">
                                <div class="mt-6 rounded-xl border border-primary/20 bg-primary/5 p-6 text-center space-y-3">
                                    <h3 class="font-heading text-lg font-bold text-text">Sign in to complete your request</h3>
                                    <p class="text-xs text-text-muted max-w-md mx-auto">Your selected services and configured schedules will be saved so you can pick up right here.</p>
                                    <div class="pt-2 flex flex-wrap justify-center gap-3">
                                        <button type="button" @click="saveAndSignIn()" class="px-5 py-2.5 rounded-xl bg-primary text-white font-bold text-xs hover:bg-primary-hover shadow-xs">Sign in to Account</button>
                                        <button type="button" @click="saveAndRegister()" class="px-5 py-2.5 rounded-xl border border-border text-text font-bold text-xs hover:bg-muted">Create New Account</button>
                                    </div>
                                </div>
                            </template>

                            <template x-if="config.isAuthenticated">
                                <div class="mt-6 grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <label for="booking-name" class="mb-1 block text-sm font-semibold text-text">Full name *</label>
                                        <input id="booking-name" name="guest_name" autocomplete="name" maxlength="255" type="text" x-model="guest.guest_name" class="admin-input !text-base w-full min-h-12" required>
                                    </div>
                                    <div>
                                        <label for="booking-email" class="mb-1 block text-sm font-semibold text-text">Email address *</label>
                                        <input id="booking-email" name="guest_email" autocomplete="email" maxlength="255" spellcheck="false" autocapitalize="none" type="email" x-model="guest.guest_email" class="admin-input !text-base w-full min-h-12" required>
                                    </div>
                                    <div>
                                        <label for="booking-phone" class="mb-1 block text-sm font-semibold text-text">Mobile number *</label>
                                        <input id="booking-phone" name="guest_phone" autocomplete="tel-national" aria-describedby="booking-phone-help" type="tel" inputmode="numeric" pattern="09[0-9]{9}" maxlength="11" data-ph-mobile x-model="guest.guest_phone" class="admin-input !text-base w-full min-h-12" required>
                                        <p id="booking-phone-help" class="mt-1 text-sm text-text-muted">Use a Philippine mobile number starting with 09, such as 09171234567.</p>
                                    </div>
                                    <div>
                                        <label for="booking-address" class="mb-1 block text-sm font-semibold text-text">Address or place of origin *</label>
                                        <input id="booking-address" name="guest_place_of_origin" autocomplete="street-address" maxlength="255" type="text" x-model="guest.guest_place_of_origin" class="admin-input !text-base w-full min-h-12" required>
                                    </div>
                                    <div>
                                        <label for="booking-nationality" class="mb-1 block text-sm font-semibold text-text">Nationality *</label>
                                        <input id="booking-nationality" name="guest_nationality" maxlength="100" type="text" x-model="guest.guest_nationality" class="admin-input !text-base w-full min-h-12" required>
                                    </div>
                                    <div>
                                        <label for="booking-company" class="mb-1 block text-sm font-semibold text-text">Company / Organization (optional)</label>
                                        <input id="booking-company" name="guest_company" autocomplete="organization" maxlength="255" type="text" x-model="guest.guest_company" class="admin-input !text-base w-full min-h-12">
                                    </div>
                                    <div>
                                        <label for="booking-id-type" class="mb-1 block text-sm font-semibold text-text">Government ID type *</label>
                                        <select id="booking-id-type" name="guest_valid_id" x-model="guest.guest_valid_id" class="admin-input !text-base w-full min-h-12" required>
                                            <option value="">Select ID type…</option>
                                            @foreach (\App\Enums\GuestIdType::cases() as $idType)
                                                <option value="{{ $idType->value }}">{{ $idType->label() }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label for="booking-gender" class="mb-1 block text-sm font-semibold text-text">Gender *</label>
                                        <select id="booking-gender" name="guest_gender" x-model="guest.guest_gender" class="admin-input !text-base w-full min-h-12" required>
                                            <option value="">Select gender…</option>
                                            @foreach (\App\Enums\GuestGender::cases() as $gender)
                                                <option value="{{ $gender->value }}">{{ $gender->label() }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="sm:col-span-2">
                                        <label for="booking-requests" class="mb-1 block text-sm font-semibold text-text">Requests for the whole booking (optional)</label>
                                        <textarea id="booking-requests" name="special_requests" maxlength="1000" x-model="specialRequests" rows="3" class="admin-input !text-base w-full min-h-12"></textarea>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </form>

                    {{-- STEP 3: Review & Summary --}}
                    <div :hidden="step !== 3" class="space-y-6">
                        <div class="bg-surface rounded-2xl border border-border p-6 sm:p-8 shadow-xs space-y-6">
                            <div>
                                <h2 tabindex="-1" id="booking-step-3" class="font-heading text-2xl font-bold text-text">Review your booking</h2>
                                <p class="mt-1 text-sm text-text-muted">Check your dates, guest counts, and contact details before you continue.</p>
                            </div>

                            <div class="divide-y divide-border">
                                <template x-for="(line, index) in lines" :key="index">
                                    <div class="py-4 flex items-start justify-between gap-4">
                                        <div class="min-w-0">
                                            <h3 class="font-heading font-bold text-base text-text" x-text="facilityName(line.facility_id)"></h3>
                                            <p class="text-xs text-text-muted mt-0.5" x-text="lineScheduleLabel(line)"></p>
                                            <p class="text-xs text-text-muted" x-text="line.guest_count + (Number(line.guest_count) === 1 ? ' guest' : ' guests')"></p>
                                            <div x-show="line.special_requests.trim()" class="mt-2 text-sm">
                                                <p class="font-semibold">Special request</p>
                                                <p class="whitespace-pre-wrap break-words text-text-muted" x-text="line.special_requests"></p>
                                            </div>
                                        </div>
                                        <div class="text-right">
                                            <span class="font-heading font-bold text-base text-text" x-text="lineEstimate(index) !== null ? formatPesos(lineEstimate(index)) : '—'"></span>
                                            <button type="button" @click="goToStep(1)" class="ml-auto block min-h-12 text-sm text-primary hover:underline font-semibold" :aria-label="'Edit services and schedules for ' + facilityName(line.facility_id)">Edit</button>
                                        </div>
                                    </div>
                                </template>
                            </div>

                            <section class="border-t border-border pt-4" aria-labelledby="booking-contact-heading">
                                <div class="flex items-center justify-between gap-3">
                                    <h3 id="booking-contact-heading" class="font-heading font-bold text-text">Booking contact</h3>
                                    <button type="button" @click="goToStep(2)" class="min-h-12 text-sm font-semibold text-primary hover:underline">Edit guest details</button>
                                </div>
                                <p class="text-sm font-semibold" x-text="guest.guest_name"></p>
                                <p class="break-words text-sm text-text-muted" x-text="guest.guest_email"></p>
                                <p class="text-sm text-text-muted" x-text="guest.guest_phone"></p>
                                <div x-show="specialRequests.trim()" class="mt-3 text-sm">
                                    <p class="font-semibold">Requests for the whole booking</p>
                                    <p class="whitespace-pre-wrap break-words text-text-muted" x-text="specialRequests"></p>
                                </div>
                            </section>

                            <div class="space-y-3 rounded-xl border border-border bg-muted/40 p-4">
                                <label class="flex min-h-12 items-center gap-3 text-sm font-medium">
                                    <input type="checkbox" x-model="petsIncluded">
                                    <span>Bring a pet · <x-amount :value="500" /> per booking</span>
                                </label>
                                <p x-show="petsIncluded" class="text-sm text-text-muted">Bring diapers and a cage or leash. Keep pets out of the pools.</p>
                                <div class="flex flex-wrap items-baseline justify-between gap-2 border-t border-border pt-3" aria-live="polite">
                                    <span class="text-sm font-semibold">Estimated total</span>
                                    <span class="font-heading text-2xl font-bold text-primary" x-text="evaluation ? formatPesos(totalEstimate) : '—'"></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- STEP 4: Payment, Policy Version, & Checkout --}}
                    <form id="booking-checkout-form" x-ref="checkoutFields" method="post" action="{{ route('customer.grouped-booking.store') }}" enctype="multipart/form-data" aria-labelledby="booking-step-4" :hidden="step !== 4" @submit.prevent="submitGroupBooking()" novalidate>
                        @csrf
                        <div class="bg-surface rounded-2xl border border-border p-6 sm:p-8 shadow-xs space-y-6">
                            <div>
                                <h2 tabindex="-1" id="booking-step-4" class="font-heading text-2xl font-bold text-text">Payment</h2>
                                <p class="mt-1 text-sm text-text-muted">Transfer the amount shown, then upload your receipt. The resort will review your booking and verify your payment.</p>
                            </div>

                            {{-- Payment Account Picker & QR --}}
                            <div class="space-y-5">
                                <fieldset class="space-y-2" x-show="config.paymentDestinations.length">
                                    <legend class="mb-2 text-sm font-semibold">Choose how much to pay</legend>
                                    <label class="flex min-h-12 items-center gap-3 rounded-lg border border-border px-3 text-sm">
                                        <input type="radio" name="payment_percent" value="50" x-model.number="paymentPercent">
                                        <span>50% down payment</span>
                                        <strong class="ml-auto" x-text="formatPesos(depositEstimate)"></strong>
                                    </label>
                                    <label class="flex min-h-12 items-center gap-3 rounded-lg border border-border px-3 text-sm">
                                        <input type="radio" name="payment_percent" value="100" x-model.number="paymentPercent">
                                        <span>Full payment</span>
                                        <strong class="ml-auto" x-text="formatPesos(totalEstimate)"></strong>
                                    </label>
                                    <p class="pt-2 text-sm font-semibold" aria-live="polite">Amount to transfer <strong class="text-primary" x-text="formatPesos(paymentPercent === 100 ? totalEstimate : depositEstimate)"></strong></p>
                                </fieldset>
                                <fieldset>
                                    <legend class="mb-2 text-sm font-semibold">Choose a receiving account</legend>
                                    <p x-show="config.paymentDestinations.length === 0" class="rounded-lg border border-warning p-3 text-sm">The resort has not published a receiving account. Contact staff before transferring any payment.</p>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                        <template x-for="dest in config.paymentDestinations" :key="dest.id">
                                            <label
                                                 class="p-4 rounded-xl border cursor-pointer transition flex items-start gap-3"
                                                 :class="currentDestination?.id === dest.id ? 'border-primary bg-primary/5 ring-2 ring-primary/20' : 'border-border bg-surface hover:bg-muted/30'">
                                                <input type="radio" name="payment_destination_id" required :value="dest.id" x-model.number="selectedDestinationId" class="mt-1 text-primary focus:ring-primary">
                                                <div class="text-xs space-y-0.5">
                                                    <span class="font-bold text-sm text-text block" x-text="dest.bank || dest.method_label"></span>
                                                    <p class="text-text-muted" x-text="'Account: ' + dest.account_name"></p>
                                                    <p class="font-mono text-text font-semibold" x-text="dest.account_number"></p>
                                                </div>
                                            </label>
                                        </template>
                                    </div>
                                </fieldset>

                                {{-- Selected Account QR Display --}}
                                <template x-if="currentDestination?.qr_url">
                                    <div class="p-4 rounded-xl border border-border bg-surface flex flex-col sm:flex-row items-center gap-4">
                                        <img :src="currentDestination.qr_url" alt="QR code for the selected receiving account" class="w-32 h-32 rounded-lg border border-border object-contain bg-white shrink-0">
                                        <div class="space-y-2 text-center text-sm text-text-muted sm:text-left">
                                            <p class="font-bold text-text">Pay via <span x-text="currentDestination.bank || currentDestination.method_label"></span></p>
                                            <p>Scan the QR code from another device, or download it and upload it in your payment app if it supports QR uploads.</p>
                                            <p>Check the account name and amount before sending.</p>
                                            <div class="flex flex-wrap justify-center gap-x-4 sm:justify-start">
                                                <a :href="currentDestination.qr_download_url" class="inline-flex min-h-11 items-center font-semibold text-primary underline">Download QR</a>
                                                <a :href="currentDestination.qr_url" target="_blank" rel="noopener" class="inline-flex min-h-11 items-center font-semibold text-primary underline">View full size<span class="sr-only"> (opens in a new tab)</span></a>
                                            </div>
                                        </div>
                                    </div>
                                </template>

                                {{-- Proof Upload and Transaction Details --}}
                                <div x-show="currentDestination" class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-2">
                                    <div>
                                        <label for="booking-payment-date" class="mb-1 block text-sm font-semibold text-text">Date you transferred *</label>
                                        <input id="booking-payment-date" name="payment_date" type="date" x-model="paymentDate" :max="today" class="admin-input !text-base w-full min-h-12" required>
                                    </div>
                                    <div class="sm:col-span-2">
                                        <label for="booking-proof" class="mb-1 block text-sm font-semibold text-text">Upload your receipt *</label>
                                        <p id="booking-proof-help" class="mb-2 text-sm text-text-muted">Use a JPG, PNG, or PDF up to 5 MB.</p>
                                        <input id="booking-proof" name="proof" aria-describedby="booking-proof-help" type="file" @change="validateReceipt($event.target)" accept="image/jpeg,image/png,application/pdf" class="admin-input !text-base w-full min-h-12" required>
                                    </div>
                                </div>
                            </div>

                            <details class="rounded-xl border border-border p-4 text-sm">
                                <summary class="cursor-pointer font-semibold">Booking and rescheduling terms</summary>
                                <div class="mt-3 space-y-3 text-text-muted">
                                    @include('pages.partials.confirmed-booking-policy')
                                    <a href="{{ route('terms') }}" target="_blank" rel="noopener noreferrer" class="font-semibold text-primary underline">Read all terms and policies (opens in a new tab)</a>
                                </div>
                            </details>

                            {{-- Policy Agreement Checkbox --}}
                            <div class="pt-4 border-t border-border">
                                <label class="flex items-start gap-3 cursor-pointer">
                                    <input type="checkbox" name="policies_accepted" x-model="policiesAccepted" class="mt-0.5 rounded border-border text-primary focus:ring-primary" required>
                                    <span class="text-xs text-text leading-relaxed">
                                        I have reviewed my booking details and accept the booking and rescheduling terms.
                                    </span>
                                </label>
                            </div>
                        </div>
                    </form>

                </div>

                {{-- Services summary and step actions --}}
                <div id="booking-summary" :class="{ 'lg:sticky lg:top-24': step === 1 }">
                    <div :class="{ 'rounded-2xl border border-border bg-surface p-4 sm:p-5': step === 1 }">
                        <div x-show="step === 1" class="flex items-center justify-between border-b border-border pb-4">
                            <h2 class="text-lg font-bold text-text">Booking summary</h2>
                            <button type="button" @click="checkAvailability()" :disabled="checking" class="text-xs font-semibold text-primary hover:underline flex items-center gap-1">
                                <svg class="w-3.5 h-3.5" :class="{ 'animate-spin': checking }" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                Recheck
                            </button>
                        </div>

                        <div x-show="step === 1" class="py-4 space-y-2.5 text-sm">
                            <div class="flex justify-between text-text-muted">
                                <span>Services selected</span>
                                <span class="font-bold text-text" x-text="lines.length"></span>
                            </div>
                            <div class="flex justify-between text-text-muted">
                                <span>Schedule availability</span>
                                <span class="font-bold" :class="allAvailable ? 'text-success' : 'text-warning'" x-text="allAvailable ? 'Available' : 'Check schedules'"></span>
                            </div>
                            <div class="flex justify-between text-text font-bold pt-2.5 border-t border-border text-sm">
                                <span>Estimated total</span>
                                <span class="font-heading text-xl font-extrabold text-primary" x-text="allAvailable ? formatPesos(totalEstimate) : '—'"></span>
                            </div>
                        </div>

                        <div x-show="step === 1 && lines.length && !allAvailable" x-cloak class="mb-4 rounded-xl border border-warning/40 bg-warning/10 p-3.5 text-xs text-warning-dark" role="status">
                            <p class="font-bold">Schedule action required</p>
                            <p class="mt-0.5 text-text-muted" x-text="basketGuidance"></p>
                        </div>

                        <div class="pt-2">
                            <template x-if="step === 1">
                                <button type="button" @click="continueToGuestDetails()"
                                        :disabled="checking || lines.length === 0"
                                        class="w-full py-3.5 rounded-xl bg-primary text-white font-bold text-xs hover:bg-primary-hover disabled:opacity-50 transition shadow-xs">
                                    <span x-show="!checking" x-text="allAvailable ? 'Continue to Guest details →' : 'Set schedules to continue →'"></span>
                                    <span x-show="checking">Verifying availability…</span>
                                </button>
                            </template>

                            <template x-if="step === 2">
                                <div class="flex flex-col gap-2">
                                    <button type="submit" form="booking-guest-form"
                                            :disabled="checking || !config.isAuthenticated"
                                            class="w-full py-3.5 rounded-xl bg-primary text-white font-bold text-xs hover:bg-primary-hover disabled:opacity-50 transition shadow-xs">
                                        <span x-text="checking ? 'Checking availability…' : 'Review booking'"></span>
                                    </button>
                                    <button type="button" @click="goToStep(1)" class="text-xs text-text-muted hover:underline text-center py-1 font-semibold">
                                        &larr; Back to services
                                    </button>
                                </div>
                            </template>

                            <template x-if="step === 3">
                                <div class="flex flex-col gap-2">
                                    <button type="button" @click="continueToPayment()"
                                            :disabled="checking"
                                            class="w-full py-3.5 rounded-xl bg-primary text-white font-bold text-xs hover:bg-primary-hover disabled:opacity-50 transition shadow-xs">
                                        <span x-text="checking ? 'Checking availability…' : 'Continue to payment'"></span>
                                    </button>
                                    <button type="button" @click="goToStep(2)" class="text-xs text-text-muted hover:underline text-center py-1 font-semibold">
                                        &larr; Back to guest details
                                    </button>
                                </div>
                            </template>

                            <template x-if="step === 4">
                                <div class="flex flex-col gap-2">
                                    <button type="submit" form="booking-checkout-form"
                                            :disabled="submitting"
                                            class="w-full py-3.5 rounded-xl bg-primary text-white font-bold text-xs hover:bg-primary-hover disabled:opacity-50 transition shadow-xs">
                                        <span x-show="!submitting">Submit booking and receipt</span>
                                        <span x-show="submitting">Submitting request…</span>
                                    </button>
                                    <button type="button" @click="goToStep(3)" :disabled="submitting" class="text-xs text-text-muted hover:underline text-center py-1 font-semibold">
                                        &larr; Back to review
                                    </button>
                                </div>
                            </template>
                        </div>
                        <a href="{{ config('resort.messenger_url') }}" target="_blank" rel="noopener noreferrer" class="mt-4 inline-block text-xs font-semibold text-primary underline">Message the resort for help</a>
                    </div>
                </div>

            </div>
        </div>

        {{-- Mobile Bottom Bar --}}
        <div x-show="lines.length && step === 1" x-cloak class="fixed inset-x-0 bottom-0 z-40 border-t border-border bg-surface p-3 shadow-xl lg:hidden">
            <a href="#selected-services" class="flex items-center justify-between rounded-xl bg-primary px-4 py-3 text-white text-xs font-bold">
                <span><strong x-text="lines.length"></strong> <span x-text="lines.length === 1 ? 'service' : 'services'"></span> · Configure booking</span>
                <strong x-text="allAvailable ? formatPesos(totalEstimate) : ''"></strong>
            </a>
        </div>
    </div>

    <script type="application/json" id="unified-workspace-config">@json($workspaceConfig)</script>

    <script>
        document.addEventListener('alpine:init', () => {
            const config = JSON.parse(document.getElementById('unified-workspace-config').textContent);

            Alpine.data('unifiedBookingWorkspace', () => ({
                config: config,
                step: 1,
                today: config.today,
                lines: [],
                evaluation: null,
                checking: false,
                submitting: false,
                errorMessage: '',
                versionToken: null,
                policiesAccepted: false,
                specialRequests: '',

                // Categories
                categories: [
                    { slug: 'selected', label: 'Selected services' },
                    { slug: 'room', label: 'Room' },
                    { slug: 'function_hall', label: 'Function Halls' },
                    { slug: 'resort_package', label: 'Resort Packages' },
                    { slug: 'cottage', label: 'Cottage' },
                ],
                categoryFilter: ['selected', 'room', 'function_hall', 'resort_package', 'cottage'].includes(config.initialCategory) ? config.initialCategory : 'selected',
                availabilityRequestId: 0,

                // Photo Gallery Details Modal
                detailFacility: null,
                detailOpener: null,
                activePhotoIndex: 0,
                touchStartX: 0,
                touchEndX: 0,

                // Payment Checkout Data (Stage 4)
                selectedDestinationId: config.paymentDestinations.length === 1 ? config.paymentDestinations[0].id : null,
                paymentDate: '',
                paymentPercent: 50,
                petsIncluded: false,
                proofFile: null,
                get currentDestination() {
                    return this.config.paymentDestinations.find(d => Number(d.id) === Number(this.selectedDestinationId));
                },

                get filteredFacilities() {
                    if (this.categoryFilter === 'selected') {
                        return this.config.facilities.filter(f => this.lines.some(l => Number(l.facility_id) === f.id));
                    }
                    return this.config.facilities.filter(f => f.type === this.categoryFilter);
                },

                openDetails(facility, opener) {
                    this.detailFacility = facility;
                    this.detailOpener = opener;
                    this.activePhotoIndex = 0;
                    document.body.style.overflow = 'hidden';
                    history.pushState({ serviceDetail: facility.id }, '');
                    this.$nextTick(() => this.$refs.detailClose.focus());
                },

                closeDetails(updateHistory = true) {
                    if (!this.detailFacility) return;
                    this.detailFacility = null;
                    document.body.style.overflow = '';
                    this.$nextTick(() => this.detailOpener?.focus());
                    if (updateHistory && history.state?.serviceDetail) history.back();
                },

                prevPhoto() {
                    if (!this.detailFacility?.photos?.length) return;
                    this.activePhotoIndex = (this.activePhotoIndex - 1 + this.detailFacility.photos.length) % this.detailFacility.photos.length;
                },
                photoPosition(index) {
                    const count = this.detailFacility?.photos?.length || 0;
                    if (index === this.activePhotoIndex) return 'active';
                    if (count <= 1) return 'hidden';
                    if (index === (this.activePhotoIndex + 1) % count) return 'next';
                    if (count > 2 && index === (this.activePhotoIndex - 1 + count) % count) return 'previous';
                    return 'hidden';
                },

                nextPhoto() {
                    if (!this.detailFacility?.photos?.length) return;
                    this.activePhotoIndex = (this.activePhotoIndex + 1) % this.detailFacility.photos.length;
                },

                touchStart(e) {
                    this.touchStartX = e.changedTouches[0].screenX;
                },

                touchEnd(e) {
                    this.touchEndX = e.changedTouches[0].screenX;
                    if (this.touchStartX - this.touchEndX > 50) this.nextPhoto();
                    if (this.touchEndX - this.touchStartX > 50) this.prevPhoto();
                },

                trapDetailFocus(event) {
                    const controls = [...this.$refs.detailSheet.querySelectorAll('button:not([disabled]), a[href], input, select')].filter(control => control.getClientRects().length);
                    if (!controls.length) return;
                    const first = controls[0], last = controls[controls.length - 1];
                    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
                    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
                },

                humanSchedule(fac) {
                    if (!fac) return 'Standard published schedule';
                    if (fac.is_room) return 'Room stay: overnight 2 PM–noon, or same-day selected hours';
                    if (fac.is_hall && fac.schedule?.mode === 'opal') return 'Event venue (Day: 9:00 AM–5:00 PM or Night: 5:00 PM–10:00 PM)';
                    if (fac.is_hall) return 'Event venue (Standard 4-hour block or custom hours)';
                    if (fac.is_package) return 'Packaged day or overnight stay';
                    if (fac.is_cottage) return 'Day or night swimming session';
                    return fac.schedule?.mode || 'Standard resort schedule';
                },

                guest: {
                    guest_name: config.guestProfile.guest_name || '',
                    guest_email: config.guestProfile.guest_email || '',
                    guest_phone: (config.guestProfile.guest_phone || '').replace(/[\s-]/g, ''),
                    guest_place_of_origin: config.guestProfile.guest_place_of_origin || '',
                    guest_nationality: config.guestProfile.guest_nationality || '',
                    guest_company: config.guestProfile.guest_company || '',
                    guest_valid_id: config.guestProfile.guest_valid_id || '',
                    guest_gender: config.guestProfile.guest_gender || '',
                },

                init() {
                    if (this.config.initialLines && this.config.initialLines.length > 0) {
                        this.lines = this.config.initialLines.map(l => ({
                            facility_id: l.facility_id,
                            check_in: l.check_in || '',
                            check_out: l.check_out || '',
                            date: l.date || '',
                            swimming_session_id: Number(l.swimming_session_id || this.config.sessions.find(s => s.slug === l.session)?.id || 0) || '',
                            session: l.session || '',
                            guest_count: l.guest_count ?? 0,
                            pax_tier: l.pax_tier || null,
                            infants_count: l.infants_count || 0,
                            early_check_in_hours: l.early_check_in_hours || 0,
                            late_check_out_hours: l.late_check_out_hours || 0,
                            special_requests: l.special_requests || '',
                        }));
                    }

                    if (this.lines.length) {
                        this.categoryFilter = 'selected';
                        this.checkAvailability().then(() => {
                            if (this.config.resumeAfterAuth && this.config.isAuthenticated && this.allAvailable) this.goToStep(2);
                        });
                    }
                },

                addLine(facilityId) {
                    if (this.lines.some(line => Number(line.facility_id) === Number(facilityId))) return;
                    const defaultFac = this.facility(facilityId);
                    this.lines.push({
                        facility_id: defaultFac ? defaultFac.id : '',
                        check_in: '',
                        check_out: '',
                        date: '',
                        swimming_session_id: '',
                        session: '',
                        guest_count: 0,
                        pax_tier: null,
                        infants_count: 0,
                        early_check_in_hours: 0,
                        late_check_out_hours: 0,
                        special_requests: '',
                    });
                    this.categoryFilter = 'selected';
                    this.onInputChanged();
                    this.$nextTick(() => document.getElementById('selected-services')?.focus());
                },

                removeLine(index) {
                    this.lines.splice(index, 1);
                    this.onInputChanged();
                },

                facility(id) {
                    return this.config.facilities.find(f => f.id === Number(id));
                },

                facilityName(id) {
                    const fac = this.facility(id);
                    return fac ? fac.name : 'Choose a service';
                },

                isCottage(id) {
                    const fac = this.facility(id);
                    return fac ? fac.is_cottage : false;
                },

                isRoom(id) {
                    const fac = this.facility(id);
                    return fac ? fac.is_room : false;
                },

                isHall(id) {
                    return Boolean(this.facility(id)?.is_hall);
                },

                datePart(value) {
                    return value ? String(value).slice(0, 10) : '';
                },

                isSameDayRoom(line) {
                    return Boolean(this.datePart(line.check_in) && this.datePart(line.check_in) === this.datePart(line.check_out));
                },

                setRoomDate(line, field, date) {
                    const wasSameDay = this.isSameDayRoom(line);
                    const originalDate = this.datePart(line[field]);
                    if (date === originalDate) return;

                    const overnightTimes = { check_in: '14:00', check_out: '12:00' };
                    line[field] = date ? date + ' ' + overnightTimes[field] : '';
                    if (this.isSameDayRoom(line)) {
                        line.check_in = date + ' 09:00';
                        line.check_out = date + ' 17:00';
                        line.early_check_in_hours = 0;
                    } else if (wasSameDay) {
                        const otherField = field === 'check_in' ? 'check_out' : 'check_in';
                        line[otherField] = this.datePart(line[otherField]) + ' ' + overnightTimes[otherField];
                    }
                    this.onInputChanged();
                },

                setPackageDate(line, date) {
                    const schedule = this.facility(line.facility_id)?.schedule;
                    if (!date) {
                        line.check_in = line.check_out = '';
                        return;
                    }
                    if (!schedule?.start || !schedule?.end) return;
                    line.check_in = date + ' ' + schedule.start;
                    const end = new Date(date + 'T00:00:00Z');
                    if (schedule.overnight) end.setUTCDate(end.getUTCDate() + 1);
                    line.check_out = end.toISOString().slice(0, 10) + ' ' + schedule.end;
                },

                setHallSlot(line, date, slot) {
                    line.hall_slot = slot;
                    if (!date) {
                        line.check_in = line.check_out = '';
                        return;
                    }
                    const [start, end] = slot.split('|');
                    line.check_in = date + ' ' + start;
                    line.check_out = date + ' ' + end;
                },

                maxCapacity(id) {
                    const fac = this.facility(id);
                    return fac ? fac.max_capacity : 10;
                },

                includedCapacity(id) {
                    const fac = this.facility(id);
                    return fac ? fac.included_capacity : 1;
                },

                capacityHint(id) {
                    const limit = this.maxCapacity(id);
                    const included = this.includedCapacity(id);
                    return included === limit ? 'Up to ' + limit + ' guests' : included + ' included · Up to ' + limit + ' guests';
                },

                lineMissingDetails(line) {
                    if (this.isCottage(line.facility_id)) {
                        if (!line.date || !line.swimming_session_id) return 'Choose a visit date and swimming session.';
                    } else if (!this.datePart(line.check_in) || !this.datePart(line.check_out)) {
                        if (this.isRoom(line.facility_id)) return 'Choose check-in and check-out dates.';
                        return this.isHall(line.facility_id) ? 'Choose an event date.' : 'Choose a package start date.';
                    }
                    if (this.isRoom(line.facility_id) && this.isSameDayRoom(line) && (!line.check_in.slice(11, 16) || !line.check_out.slice(11, 16))) {
                        return 'Choose both check-in and check-out times.';
                    }
                    return Number(line.guest_count) >= 1 ? '' : 'Enter at least one guest.';
                },

                onInputChanged() {
                    this.evaluation = null;
                    this.availabilityRequestId++;
                    this.versionToken = null;
                    clearTimeout(this.debounceTimer);
                    this.debounceTimer = setTimeout(() => {
                        this.checkAvailability();
                    }, 500);
                },

                async checkAvailability() {
                    clearTimeout(this.debounceTimer);
                    const requestId = ++this.availabilityRequestId;
                    if (this.lines.length === 0 || this.lines.some(line => Number(line.guest_count) < 1)) {
                        this.evaluation = null;
                        this.versionToken = null;
                        this.checking = false;
                        return;
                    }
                    this.versionToken = null;
                    this.checking = true;
                    this.errorMessage = '';

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

                        const payload = await res.json();
                        if (requestId !== this.availabilityRequestId) return;
                        if (res.ok && payload.success) {
                            this.evaluation = payload.data;
                            this.versionToken = payload.data.version_token;
                        } else {
                            this.errorMessage = payload.message || 'Could not verify availability.';
                        }
                    } catch (e) {
                        if (requestId === this.availabilityRequestId) this.errorMessage = 'Network error while checking availability.';
                    } finally {
                        if (requestId === this.availabilityRequestId) this.checking = false;
                    }
                },

                get allAvailable() {
                    return this.evaluation && this.evaluation.all_available && Boolean(this.versionToken)
                        && !this.lines.some(line => this.lineMissingDetails(line));
                },

                get totalEstimate() {
                    return this.evaluation ? this.evaluation.total_estimate + (this.petsIncluded ? 500 : 0) : 0;
                },

                get depositEstimate() {
                    return this.evaluation ? Math.ceil((Math.round(this.evaluation.total_estimate * 100) + (this.petsIncluded ? 50000 : 0)) / 2) / 100 : 0;
                },

                get basketGuidance() {
                    const incomplete = this.lines.find(line => this.lineMissingDetails(line));
                    if (incomplete) return this.facilityName(incomplete.facility_id) + ': ' + this.lineMissingDetails(incomplete);
                    if (this.checking) return 'Checking your selected schedules…';
                    if (!this.evaluation) return 'Configure dates and sessions above, then recheck availability.';
                    const failed = this.evaluation.lines?.find(line => !line.is_available);
                    return failed ? (failed.facility_name + ': ' + (failed.reason || 'Choose a valid schedule.')) : 'Refresh availability for the modified schedules.';
                },

                lineStatus(index) {
                    const line = this.lines[index];
                    if (line && this.lineMissingDetails(line)) return 'incomplete';
                    if (this.checking) return 'checking';
                    if (!this.versionToken) return 'stale';
                    const evaluated = this.evaluation?.lines?.[index];
                    if (!evaluated) return 'stale';
                    return evaluated.is_available ? 'available' : 'unavailable';
                },

                lineStatusClass(index) {
                    const status = this.lineStatus(index);
                    if (status === 'unavailable') return 'border-danger/60 bg-danger-light/10';
                    if (status === 'available') return 'border-success/60';
                    return '';
                },

                lineReason(index) {
                    return this.evaluation?.lines?.[index]?.reason || 'Service line is unavailable.';
                },

                lineEstimate(index) {
                    return this.evaluation?.lines?.[index]?.quoted_amount ?? null;
                },

                lineScheduleLabel(line) {
                    const evaluated = this.evaluation?.lines?.[this.lines.indexOf(line)];
                    if (evaluated?.check_in && evaluated?.check_out) return this.formatManila(evaluated.check_in) + ' to ' + this.formatManila(evaluated.check_out);
                    if (this.isCottage(line.facility_id)) return line.date ? line.date + ' · Session chosen' : 'Choose visit date and session';
                    return line.check_in && line.check_out ? this.formatManila(line.check_in) + ' to ' + this.formatManila(line.check_out) : 'Select schedule';
                },

                formatManila(value) {
                    const normalized = String(value).replace(' ', 'T').slice(0, 19);
                    const date = new Date(normalized + '+08:00');
                    if (Number.isNaN(date.getTime())) return value;
                    return new Intl.DateTimeFormat('en-PH', { timeZone: 'Asia/Manila', month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true }).format(date) + ' (PHT)';
                },

                formatPesos(amount) {
                    return window.pesos(amount);
                },

                goToStep(step) {
                    this.step = step;
                    this.errorMessage = '';
                    this.$nextTick(() => document.getElementById('booking-step-' + step).focus());
                },

                validateFields(section) {
                    const invalid = [...section.querySelectorAll('input, select, textarea')]
                        .find(field => field.getClientRects().length && !field.validity.valid);
                    if (!invalid) return true;

                    invalid.focus();
                    invalid.reportValidity();
                    return false;
                },

                continueToGuestDetails() {
                    if (!this.validateFields(this.$refs.selectedServices)) return;
                    if (!this.allAvailable) {
                        this.errorMessage = this.basketGuidance;
                        this.$nextTick(() => document.getElementById('selected-services')?.focus());
                        return;
                    }
                    this.goToStep(2);
                },

                async continueToReview() {
                    if (this.step !== 2 || this.checking || !this.config.isAuthenticated) return;
                    const fields = this.$refs.guestFields.elements;
                    const name = fields.guest_name.value.trim();
                    fields.guest_name.setCustomValidity(!name || /^(?=.*\p{L})[\p{L}\p{M} .'’\-]+$/u.test(name)
                        ? '' : 'Use letters, spaces, apostrophes, periods, or hyphens in the guest name.');
                    for (const field of [fields.guest_name, fields.guest_phone, fields.guest_place_of_origin, fields.guest_nationality]) {
                        if (field.value && !field.value.trim()) field.setCustomValidity('Enter a value for this field.');
                    }
                    if (!this.validateFields(this.$refs.guestFields)) return;
                    await this.checkAvailability();
                    if (this.step !== 2) return;
                    if (!this.allAvailable) {
                        const message = this.errorMessage || this.basketGuidance;
                        this.goToStep(1);
                        this.showBookingError(message);
                        return;
                    }
                    this.goToStep(3);
                },

                async continueToPayment() {
                    if (this.step !== 3 || this.checking) return;
                    const previousPrices = this.lines.map((_, index) => this.lineEstimate(index));
                    await this.checkAvailability();
                    if (this.step !== 3) return;
                    if (!this.allAvailable) {
                        const message = this.errorMessage || this.basketGuidance;
                        this.goToStep(1);
                        this.showBookingError(message);
                        return;
                    }
                    if (previousPrices.some((price, index) => price !== this.lineEstimate(index))) {
                        this.showBookingError('A price changed. Review the updated amounts before continuing.');
                        return;
                    }
                    this.goToStep(4);
                },

                showBookingError(message) {
                    this.errorMessage = message;
                    this.$nextTick(() => this.$refs.errorSummary.focus());
                },

                validateReceipt(input) {
                    this.proofFile = input.files[0];
                    let message = '';
                    if (this.proofFile?.size > 5 * 1024 * 1024) {
                        message = 'Choose a file of 5 MB or less.';
                    } else if (this.proofFile && !/\.(jpe?g|png|pdf)$/i.test(this.proofFile.name)) {
                        message = 'Choose a JPG, PNG, or PDF receipt.';
                    }
                    input.setCustomValidity(message);
                },

                async saveAndSignIn() { await this.saveAndAuthenticate(this.config.loginUrl); },
                async saveAndRegister() { await this.saveAndAuthenticate(this.config.registerUrl); },
                async saveAndAuthenticate(destination) {
                    if (!this.allAvailable) { this.errorMessage = this.basketGuidance; return; }
                    try {
                        const response = await fetch(this.config.intentUrl, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': this.config.csrfToken },
                            body: JSON.stringify({ lines: this.lines }),
                        });
                        if (!response.ok) throw new Error('Could not save your booking. Please try again.');
                        window.location.href = destination;
                    } catch (error) { this.errorMessage = error.message || 'Could not save your booking.'; }
                },

                async submitGroupBooking() {
                    if (this.step !== 4 || this.submitting) return;
                    if (!this.allAvailable) {
                        this.showBookingError('Return to Services and recheck availability. If you already transferred, keep your receipt and contact the resort if the amount or schedule changes.');
                        return;
                    }
                    if (!this.config.paymentDestinations.length) {
                        this.showBookingError('No receiving account is available. Contact the resort for help.');
                        return;
                    }
                    if (!this.validateFields(this.$refs.checkoutFields)) return;

                    this.submitting = true;
                    this.errorMessage = '';

                    const formData = new FormData();
                    this.lines.forEach((l, idx) => {
                        Object.keys(l).forEach(k => {
                            if (l[k] !== null && l[k] !== undefined) {
                                formData.append(`lines[${idx}][${k}]`, l[k]);
                            }
                        });
                    });

                    formData.append('version_token', this.versionToken || '');
                    formData.append('special_requests', this.specialRequests || '');
                    formData.append('policies_accepted', '1');
                    formData.append('policy_version', this.config.policyVersion);
                    formData.append('payment_percent', this.paymentPercent);
                    formData.append('pets_included', this.petsIncluded ? '1' : '0');

                    Object.keys(this.guest).forEach(gk => {
                        formData.append(gk, this.guest[gk].trim());
                    });

                    formData.append('payment_destination_id', this.selectedDestinationId);
                    formData.append('payment_method', this.currentDestination.method);
                    formData.append('payment_date', this.paymentDate);
                    if (this.proofFile) formData.append('proof', this.proofFile);

                    let redirecting = false;
                    try {
                        const res = await fetch(this.$refs.checkoutFields.action, {
                            method: 'POST',
                            headers: {
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': this.config.csrfToken,
                            },
                            body: formData,
                        });

                        if (res.status === 419 || res.status === 401) {
                            this.showBookingError('Your session expired. Keep your receipt if you paid. Sign in again and check My Reservations before retrying.');
                            return;
                        }
                        const data = await res.json();
                        if (res.ok && data.success) {
                            window.location.href = data.redirect_url;
                            redirecting = true;
                            return;
                        }

                        if (data.code === 'availability_expired') {
                            this.goToStep(1);
                            this.showBookingError('Availability expired. Recheck your schedules and review the latest price. If you already paid, keep your receipt and contact the resort if the price or schedule changes.');
                            return;
                        }

                        const errors = data.errors || {};
                        if (Object.keys(errors).some(key => key.startsWith('guest_') || key === 'special_requests')) {
                            this.goToStep(2);
                        } else if (Object.keys(errors).some(key => key.startsWith('lines.') && key.endsWith('.special_requests'))) {
                            this.goToStep(1);
                        }
                        this.showBookingError(Object.values(errors).flat().join(' ') || data.message || 'We could not submit your booking.');
                    } catch (error) {
                        this.showBookingError('We could not confirm whether your booking was submitted. Check My Reservations before trying again. If you paid, keep your receipt.');
                    } finally {
                        if (!redirecting) this.submitting = false;
                    }
                }
            }));
        });
    </script>
</x-customer-layout>
