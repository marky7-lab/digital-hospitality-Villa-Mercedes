<x-customer-layout>
    @php
        $isCustomerDetail = request()->routeIs('customer.facilities.show');
        $homeUrl = $isCustomerDetail
            ? route('customer.reservations.index')
            : route('home');
        $publicListRoute = match($facility->facilityType->slug) {
            \App\Enums\FacilityType::Room->value => 'rooms',
            \App\Enums\FacilityType::FunctionHall->value => 'halls',
            \App\Enums\FacilityType::ResortPackage->value => 'packages',
            \App\Enums\FacilityType::Cottage->value => 'cottages',
            default => 'home',
        };
        $selectionQuery = array_filter(request()->only([
            'check_in', 'check_out', 'guests', 'date', 'session', 'arrival', 'departure',
        ]), fn ($value) => $value !== null && $value !== '');
        $listUrl = $isCustomerDetail
            ? route('customer.grouped-booking.workspace', [
                'type' => $facility->facilityType->slug,
                ...$selectionQuery,
            ])
            : route($publicListRoute, $facility->isRoom() ? array_filter([
                'check_in' => substr((string) ($selectionQuery['arrival'] ?? $selectionQuery['check_in'] ?? ''), 0, 10),
                'check_out' => substr((string) ($selectionQuery['departure'] ?? $selectionQuery['check_out'] ?? ''), 0, 10),
                'guests' => $selectionQuery['guests'] ?? null,
            ]) : []);
    @endphp
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 lg:py-12 pb-24">

        @if($isCustomerDetail)
            {{--
                In the account shell this page announces itself the way every
                account page does. It used to hand-roll its own breadcrumb and its
                own `text-4xl md:text-5xl lg:text-6xl` heading, which is how one
                page drifts out of a language the rest of the area speaks.
                The marketing page keeps its own crumb: different shell, different
                neighbours (see the `@else` below).
            --}}
            <x-customer.page-header
                class="mb-8"
                :breadcrumbs="[
                    ['label' => 'My reservations', 'href' => route('customer.reservations.index')],
                    ['label' => $facility->facilityType->name.'s', 'href' => $listUrl],
                    ['label' => $facility->name],
                ]"
                :eyebrow="$facility->facilityType->name"
                :title="$facility->name"
            />
        @else
            {{-- Marketing shell: its neighbours are the public listings, so it keeps
                 the public way back. --}}
            <nav class="mb-6 text-sm text-muted-foreground" aria-label="Breadcrumb">
                <ol class="flex flex-wrap items-center gap-x-2 gap-y-1 break-words">
                    <li class="inline-flex items-center">
                        <a href="{{ $homeUrl }}" class="inline-flex items-center hover:text-text transition-colors">
                            Home
                        </a>
                    </li>
                    <li>
                        <div class="flex items-center">
                            <svg class="w-4 h-4 text-muted-foreground mx-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                            <a href="{{ $listUrl }}" class="hover:text-text transition-colors">
                                {{ $facility->facilityType->name }}s
                            </a>
                        </div>
                    </li>
                    <li aria-current="page">
                        <div class="flex items-center">
                            <svg class="w-4 h-4 text-muted-foreground mx-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                            <span class="text-text font-medium">{{ $facility->name }}</span>
                        </div>
                    </li>
                </ol>
            </nav>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 xl:gap-12">
            
            {{-- Main Content --}}
            <div class="lg:col-span-2 space-y-10">
                
                {{-- Header & Image. The account shell prints the type and the
                     name in its own header, so this block belongs to the
                     marketing page only. --}}
                <div class="space-y-6">
                    @unless($isCustomerDetail)
                        <div>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-secondary-light text-text mb-3">{{ $facility->facilityType->name }}</span>
                            <h1 class="font-heading text-4xl md:text-5xl lg:text-6xl font-semibold text-text tracking-tight">
                                {{ $facility->name }}
                            </h1>
                        </div>
                    @endunless
                    <a href="#reservation-details" class="inline-flex min-h-11 items-center font-semibold text-primary underline underline-offset-4 lg:hidden">View rate and booking options</a>

                    <div
                        class="space-y-3"
                        @if($facility->galleryImages->count() > 0)
                            x-data="{
                                active: 0,
                                photos: {{ \Illuminate\Support\Js::from($facility->galleryImages->map(fn ($image) => [
                                    'url' => $image->url(),
                                    'caption' => $image->caption,
                                    'alt' => $image->caption ?: $facility->name,
                                ])->values()) }},
                                stepPhoto(direction) {
                                    this.active = (this.active + direction + this.photos.length) % this.photos.length;
                                }
                            }"
                        @endif
                    >
                        <div class="relative w-full h-[300px] md:h-[440px] rounded-2xl overflow-hidden bg-muted flex items-center justify-center text-muted-foreground border border-border shadow-sm">
                            @if($facility->galleryImages->count() > 0)
                                <button type="button" class="group absolute inset-0 focus-visible:outline focus-visible:outline-4 focus-visible:-outline-offset-4 focus-visible:outline-white" aria-label="Open full-size photo" aria-haspopup="dialog" @click="$refs.photoDialog.showModal()">
                                    <img src="{{ $facility->galleryImages->first()->url() }}" alt="{{ $facility->galleryImages->first()->caption ?: $facility->name }}" :src="photos[active].url" :alt="photos[active].alt" fetchpriority="high" class="w-full h-full object-cover">
                                    <span aria-hidden="true" class="absolute bottom-3 left-3 rounded-full bg-black/75 px-3 py-1.5 text-xs font-semibold text-white group-hover:bg-black">Enlarge photo</span>
                                </button>
                                @if($facility->galleryImages->count() > 1)
                                    <button type="button" class="absolute left-3 top-1/2 -translate-y-1/2 bg-black/75 hover:bg-black text-white rounded-full size-11 flex items-center justify-center focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
                                            @click="stepPhoto(-1)" aria-label="Previous photo">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                                    </button>
                                    <button type="button" class="absolute right-3 top-1/2 -translate-y-1/2 bg-black/75 hover:bg-black text-white rounded-full size-11 flex items-center justify-center focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
                                            @click="stepPhoto(1)" aria-label="Next photo">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                                    </button>
                                @endif
                                <span aria-hidden="true" class="pointer-events-none absolute bottom-3 right-3 text-xs font-medium text-white bg-black/75 rounded-full px-2.5 py-1" x-text="(active + 1) + ' / ' + photos.length">1 / {{ $facility->galleryImages->count() }}</span>
                            @else
                                <svg class="w-16 h-16 opacity-20" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                                <span class="absolute text-sm font-medium opacity-50 uppercase tracking-widest mt-24">No Image Available</span>
                            @endif
                        </div>

                        @if($facility->galleryImages->count() > 0)
                            <p role="status" class="sr-only" x-text="'Photo ' + (active + 1) + ' of ' + photos.length + '. ' + photos[active].alt"></p>
                            <p class="text-sm text-muted-foreground" x-show="photos[active].caption" x-text="photos[active].caption">{{ $facility->galleryImages->first()->caption }}</p>
                        @endif

                        @if($facility->galleryImages->count() > 1)
                            <div class="grid grid-cols-5 sm:grid-cols-6 gap-2">
                                @foreach($facility->galleryImages as $index => $image)
                                    <button type="button"
                                            @click="active = {{ $index }}"
                                            aria-label="Show photo {{ $loop->iteration }}{{ $image->caption ? ': '.$image->caption : '' }}"
                                            :aria-current="active === {{ $index }} ? 'true' : null"
                                            class="aspect-square min-h-11 rounded-lg overflow-hidden border-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                                            :class="active === {{ $index }} ? 'border-primary' : 'border-transparent hover:border-border'">
                                        <img src="{{ $image->url() }}" alt="" loading="lazy" class="w-full h-full object-cover">
                                    </button>
                                @endforeach
                            </div>
                        @endif

                        @if($facility->galleryImages->count() > 0)
                            <dialog
                                x-ref="photoDialog"
                                aria-labelledby="facility-photo-title"
                                closedby="any"
                                class="m-auto max-h-[calc(100dvh-1rem)] w-[calc(100%-1rem)] max-w-5xl overflow-auto overscroll-contain rounded-xl bg-neutral-950 p-3 text-white backdrop:overflow-hidden backdrop:overscroll-contain backdrop:bg-black/85 sm:p-5"
                                @keydown.left.prevent="stepPhoto(-1)"
                                @keydown.right.prevent="stepPhoto(1)"
                            >
                                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                                    <h1 id="facility-photo-title" class="font-semibold">{{ $facility->name }} photos</h1>
                                    <form method="dialog">
                                        <button autofocus type="submit" class="min-h-11 rounded-lg border border-white/40 px-4 text-sm font-semibold hover:bg-white/15 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2">Close</button>
                                    </form>
                                </div>
                                <img :src="photos[active].url" :alt="photos[active].alt" class="w-full max-h-[68dvh] object-contain">
                                <p role="status" class="mt-3 text-center text-sm" x-text="'Photo ' + (active + 1) + ' of ' + photos.length + (photos[active].caption ? ' · ' + photos[active].caption : '')"></p>
                                @if($facility->galleryImages->count() > 1)
                                    <div class="mt-3 flex flex-wrap justify-center gap-2">
                                        <button type="button" @click="stepPhoto(-1)" class="min-h-11 rounded-lg border border-white/40 px-4 text-sm hover:bg-white/15 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2">Previous photo</button>
                                        <button type="button" @click="stepPhoto(1)" class="min-h-11 rounded-lg border border-white/40 px-4 text-sm hover:bg-white/15 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2">Next photo</button>
                                    </div>
                                @endif
                            </dialog>
                        @endif
                    </div>
                </div>

                {{-- Overview --}}
                @if(filled($facility->description))
                <section>
                    <h2 class="font-heading text-3xl md:text-4xl font-semibold text-text mb-4">Overview</h2>
                    <p class="max-w-prose break-words text-base text-muted-foreground leading-relaxed whitespace-pre-wrap">{{ $facility->description }}</p>
                </section>
                @endif

                {{-- Amenities / Inclusions --}}
                @if($facility->amenities->count() > 0)
                <section>
                    <h2 class="font-heading text-3xl md:text-4xl font-semibold text-text mb-6">Amenities / Inclusions</h2>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        @foreach($facility->amenities as $amenity)
                            <div class="flex items-start gap-3 bg-surface p-4 rounded-xl border border-border">
                                <svg class="w-5 h-5 text-primary mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                                <span class="text-text font-medium">{{ $amenity->name }}</span>
                            </div>
                        @endforeach
                    </div>
                </section>
                @endif

                @if(filled($facility->exclusions))
                    <section>
                        <h2 class="font-heading text-3xl font-semibold text-text mb-4">Exclusions / Not included</h2>
                        <p class="text-muted-foreground whitespace-pre-line">{{ $facility->exclusions }}</p>
                    </section>
                @endif

                {{-- Included Bookable Resources (Package Components) --}}
                @if($facility->packageComponents->count() > 0)
                <section>
                    <h2 class="font-heading text-3xl md:text-4xl font-semibold text-text mb-6">Included Resources</h2>
                    <p class="text-muted-foreground mb-4">This package reserves the following facilities for your exclusive use:</p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        @foreach($facility->packageComponents as $component)
                            <div class="flex items-center gap-4 bg-surface p-4 rounded-xl border border-border">
                                <div class="w-12 h-12 rounded-lg bg-muted flex items-center justify-center shrink-0">
                                    <svg class="w-6 h-6 text-muted-foreground" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path></svg>
                                </div>
                                <div>
                                    <h4 class="font-bold text-text">{{ $component->componentFacility->name }}</h4>
                                    <p class="text-sm text-muted-foreground capitalize">{{ $component->componentFacility->facilityType->name }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </section>
                @endif

            </div>

            {{-- Sidebar (Reserve & Pricing CTA) --}}
            <div class="lg:col-span-1">
                <div class="sticky top-24">
                    <x-card class="glass p-6 md:p-8 relative overflow-hidden rounded-3xl border border-white/50 shadow-2xl">
                        {{-- Background Accent --}}
                        <div class="absolute top-0 right-0 w-32 h-32 bg-primary/10 rounded-full -mr-16 -mt-16 blur-2xl"></div>
                        
                        <div class="relative z-10">
                            <h2 id="reservation-details" tabindex="-1" class="scroll-mt-24 font-display text-xl md:text-2xl font-bold text-text mb-6">Reservation details</h2>
                            
                            <div class="space-y-4 mb-8">
                                <div class="flex items-center justify-between pb-4 border-b border-border">
                                    <span class="text-muted-foreground flex items-center gap-2">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                                        Standard Capacity
                                    </span>
                                    <span class="font-medium text-text">{{ trans_choice('{1} :count guest|[2,*] :count guests', $facility->capacity) }}</span>
                                </div>
                                <div class="flex items-center justify-between pb-4 border-b border-border">
                                    <span class="text-muted-foreground flex items-center gap-2">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
                                        Maximum Capacity
                                    </span>
                                    <span class="font-medium text-text">
                                        {{ $facility->effectiveMaxCapacity() === null ? 'No fixed guest limit' : trans_choice('{1} :count guest|[2,*] :count guests', $facility->effectiveMaxCapacity()) }}
                                    </span>
                                </div>
                            </div>

                            <div class="mb-8 bg-background p-4 rounded-xl border border-border">
                                <p class="text-sm text-muted-foreground uppercase tracking-wide font-medium mb-1">Starting from</p>
                                @php
                                    $baseRate = $facility->rates->where('is_active', true)->where('type', \App\Enums\RateType::Base)->sortBy('amount')->first();
                                @endphp
                                @if($baseRate)
                                    <div class="flex items-baseline gap-1.5 flex-wrap">
                                        <x-amount :value="$baseRate->amount" class="text-3xl font-extrabold text-primary" />
                                        <span class="text-muted-foreground font-medium">{{ $baseRate->unit }}</span>
                                    </div>
                                    <p class="text-xs text-muted-foreground mt-2 italic">Note: Final price may vary based on selected dates and seasonal rates.</p>
                                @else
                                    <span class="text-lg font-semibold text-text">Rate unavailable</span>
                                @endif
                            </div>

                            <div class="space-y-3">
                                @if($facility->isOnlineBookable())
                                    @php
                                        // Carry dates from discovery into the next step without
                                        // treating the published starting rate as a final quote.
                                        $nextUrl = auth()->check()
                                            ? route('customer.grouped-booking.workspace', ['facility_id' => $facility->id, ...$selectionQuery])
                                            : route('customer.booking.legacy', ['facility' => $facility->id, ...$selectionQuery]);
                                        $buttonLabel = 'Add to booking';
                                        $nextHint = 'Set this service schedule in your booking, then check live availability and the exact estimate.';
                                    @endphp
                                    <x-primary-button tag="a" href="{{ $nextUrl }}" class="w-full justify-center text-center !py-3 !text-base">
                                        {{ $buttonLabel }}
                                    </x-primary-button>
                                    <p class="text-xs text-center text-muted-foreground">
                                        {{ $nextHint }}
                                    </p>
                                @else
                                    <p class="text-sm text-text">This package cannot be reserved online. Call or message the resort to arrange it.</p>
                                    <x-secondary-button tag="a" href="{{ route('contact') }}" class="w-full justify-center text-center">
                                        Contact the resort
                                    </x-secondary-button>
                                    <p class="text-xs text-center text-muted-foreground">
                                        <a href="{{ config('resort.phone_href') }}" class="font-medium text-primary hover:underline">{{ config('resort.phone') }}</a>
                                    </p>
                                @endif
                            </div>
                        </div>
                    </x-card>
                </div>
            </div>

        </div>
    </div>
</x-customer-layout>
