<x-customer-layout>
    @php
        $time = fn (string $value) => \Carbon\Carbon::createFromFormat('H:i', $value)->format('g:i A');
        $dayUse = config('resort.stay.day_use_hours');
        $nightUse = config('resort.stay.night_use_hours');
        $overnight = config('resort.stay.overnight_hours');
        $hero = asset('images/hero-aerial.jpg');
        $mapsQuery = rawurlencode(config('resort.maps_query'));
    @endphp

    {{-- Hero --}}
    <section class="relative overflow-hidden min-h-screen flex flex-col">
        <img
            src="{{ $hero }}"
            alt="Aerial view of Villa Mercedes Nature Farm &amp; Resort, with the pool, cottages, and villa among the Ilagan hills"
            fetchpriority="high"
            decoding="async"
            class="hero-photo hero-photo-aerial"
        >
        <div class="absolute inset-0 bg-gradient-to-b from-black/15 via-black/30 to-black/65" aria-hidden="true"></div>

        <div class="relative flex-1 flex flex-col justify-center w-full max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 pt-40 sm:pt-32 lg:pt-28 pb-36 text-white text-center">
            <div class="animate-hero-copy">
                <p class="text-xs sm:text-sm font-semibold uppercase tracking-[0.2em] text-accent-light">
                    {{ config('resort.location') }}
                </p>
                <h1 class="mt-5 font-heading text-3xl sm:text-5xl md:text-6xl lg:text-7xl font-semibold leading-[1.08] tracking-tight drop-shadow text-balance">
                    {{ config('resort.name') }}
                </h1>
                <p class="mt-6 text-base sm:text-lg text-white/95 max-w-3xl mx-auto leading-relaxed">
                    Stay, celebrate, and unwind: cozy rooms and cottages, function halls for events, spring-fed pools, and day or overnight resort packages.
                </p>
                <div class="mt-9 flex flex-col sm:flex-row justify-center items-center gap-3">
                    <x-primary-button tag="a" href="{{ route('rooms') }}" class="!px-8 !py-3 !text-base">
                        Explore services
                    </x-primary-button>
                    <a href="{{ route('resort.show') }}" class="inline-flex items-center justify-center min-h-[44px] px-8 py-3 rounded-lg border border-white text-white font-semibold hover:bg-white/10">
                        Explore the Resort
                    </a>
                </div>
            </div>
        </div>
    </section>

    <div class="bg-background">

        {{-- Availability --}}
        <section id="availability" class="relative z-10 -mt-16 sm:-mt-20 px-4 sm:px-6 lg:px-8 scroll-mt-24" x-reveal>
            <div class="max-w-6xl mx-auto animate-hero-desk">
                <x-availability-bar submit-label="Check Availability" />
            </div>
        </section>


        {{-- Service cards --}}
        <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12 sm:py-20" x-reveal>
            <x-section-heading title="What brings you here?" class="mb-12">
                Choose a space, check dates, and review the reservation requirements.
            </x-section-heading>

            <div class="grid gap-5 lg:grid-cols-[1.15fr_.85fr]">
                <a href="{{ route('rooms') }}" class="group relative min-h-[28rem] overflow-hidden rounded-2xl bg-primary text-white" x-reveal>
                    <img
                        src="{{ asset('images/rooms-and-cottages.jpg') }}"
                        alt="Wooden guest cottages at Villa Mercedes with peaked roofs along a garden path"
                        loading="lazy"
                        decoding="async"
                        class="absolute inset-0 h-full w-full object-cover transition duration-700 ease-out group-hover:scale-[1.03]"
                    >
                    <span class="absolute inset-0 bg-gradient-to-t from-primary/90 via-primary/15 to-transparent" aria-hidden="true"></span>
                    <span class="absolute inset-x-0 bottom-0 p-6 sm:p-8">
                        <span class="block text-xs font-semibold uppercase tracking-[0.18em] text-accent-light">Stay</span>
                        <span class="mt-2 block font-heading text-4xl font-semibold leading-none sm:text-5xl">Rooms &amp; cottages</span>
                        <span class="mt-3 block max-w-md text-sm leading-relaxed text-white/85">Overnight stays and day-use rooms for couples, families, and groups.</span>
                        <span class="mt-6 inline-flex items-center gap-2 text-sm font-semibold text-white group-hover:underline">View accommodations <span aria-hidden="true">&rarr;</span></span>
                    </span>
                </a>

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-1">
                    <a href="{{ route('halls') }}" class="group grid grid-cols-[6.75rem_minmax(0,1fr)] gap-4 border-t border-border py-4 sm:grid-cols-[8rem_minmax(0,1fr)] lg:grid-cols-[7.5rem_minmax(0,1fr)]" x-reveal style="--reveal-delay: 100ms">
                        <img
                            src="{{ asset('images/event-hosting.jpg') }}"
                            alt="Banquet hall at Villa Mercedes with pink table linens, gold chairs, and a red carpet aisle"
                            loading="lazy"
                            decoding="async"
                            class="h-28 w-full rounded-xl object-cover sm:h-32 lg:h-28"
                        >
                        <span class="flex min-w-0 flex-col justify-center">
                            <span class="text-xs font-semibold uppercase tracking-[0.18em] text-accent">Gather</span>
                            <span class="mt-1 font-heading text-2xl font-semibold leading-tight text-text group-hover:text-primary">Event hosting</span>
                            <span class="mt-2 text-sm leading-relaxed text-text-muted">Function halls for events, meetings, and celebrations.</span>
                            <span class="mt-3 text-sm font-semibold text-primary group-hover:underline">Plan your event <span aria-hidden="true">&rarr;</span></span>
                        </span>
                    </a>

                    <a href="{{ route('packages') }}" class="group grid grid-cols-[6.75rem_minmax(0,1fr)] gap-4 border-t border-border py-4 sm:grid-cols-[8rem_minmax(0,1fr)] lg:grid-cols-[7.5rem_minmax(0,1fr)]" x-reveal style="--reveal-delay: 200ms">
                        <img
                            src="{{ asset('images/resort-package.jpg') }}"
                            alt="Pool and grounds at Villa Mercedes with a slide, rock waterfall, and poolside pavilion"
                            loading="lazy"
                            decoding="async"
                            class="h-28 w-full rounded-xl object-cover sm:h-32 lg:h-28"
                        >
                        <span class="flex min-w-0 flex-col justify-center">
                            <span class="text-xs font-semibold uppercase tracking-[0.18em] text-accent">Plan</span>
                            <span class="mt-1 font-heading text-2xl font-semibold leading-tight text-text group-hover:text-primary">Packages</span>
                            <span class="mt-2 text-sm leading-relaxed text-text-muted">Day-use, night-use, and overnight bundles for rooms and grounds.</span>
                            <span class="mt-3 text-sm font-semibold text-primary group-hover:underline">See packages <span aria-hidden="true">&rarr;</span></span>
                        </span>
                    </a>
                </div>

            </div>
        </section>

        {{-- Trust bar — real resort stats, not marketing fiction. --}}
        <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pb-8" x-reveal>
            <dl class="grid grid-cols-2 lg:grid-cols-4 gap-4 border-y border-border py-8 sm:gap-8 sm:py-12">
                @foreach(config('resort.stats') as $stat)
                    <div class="text-center">
                        <dt class="font-heading text-3xl sm:text-4xl font-semibold text-primary">{{ $stat['value'] }}</dt>
                        <dd class="mt-1.5 text-sm font-semibold text-text">{{ $stat['label'] }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>

        {{-- This requests just three compact room cards when the section nears view. --}}
        <section
            class="bg-surface py-12 sm:py-20"
            data-home-featured
            data-home-featured-endpoint="{{ route('home.featured') }}"
            data-home-featured-fallback-url="{{ route('rooms') }}"
            aria-labelledby="featured-stays-heading"
            aria-busy="true"
            x-reveal
        >
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <x-section-heading title="Featured stays" id="featured-stays-heading" class="mb-12">
                    A few guest-ready rooms, with live photos and rates for the stay you have in mind.
                </x-section-heading>

                <div data-home-featured-content>
                    <div class="grid grid-cols-1 gap-6 md:grid-cols-3">
                        <x-loading-card count="3" />
                    </div>
                </div>

                <noscript>
                    <p class="mt-6 text-center text-sm text-text-muted">JavaScript is off. <a href="{{ route('rooms') }}" class="font-semibold text-primary hover:underline">Browse all rooms</a>.</p>
                </noscript>
            </div>
        </section>

        {{-- How to reserve --}}
        <section class="bg-background py-12 sm:py-20" x-reveal>
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <x-section-heading title="How to reserve your stay" class="mb-12">
                    Check availability, review your total, and follow your booking in My Reservations.
                </x-section-heading>

                <ol class="grid grid-cols-1 gap-6 md:grid-cols-5">
                    <li class="border-t border-border pt-5 text-left">
                        <span class="block text-xs font-semibold tracking-[0.18em] text-accent">01</span>
                        <h3 class="mt-3 text-lg font-bold text-text">Check availability</h3>
                        <p class="mt-2 text-sm text-text-muted">Pick dates, guests, and a space type, then browse rooms, halls, cottages, or packages.</p>
                    </li>
                    <li class="border-t border-border pt-5 text-left">
                        <span class="block text-xs font-semibold tracking-[0.18em] text-accent">02</span>
                        <h3 class="mt-3 text-lg font-bold text-text">Sign in</h3>
                        <p class="mt-2 text-sm text-text-muted">Create an account or sign in. Your dates and guest count stay with you.</p>
                    </li>
                    <li class="border-t border-border pt-5 text-left">
                        <span class="block text-xs font-semibold tracking-[0.18em] text-accent">03</span>
                        <h3 class="mt-3 text-lg font-bold text-text">Pay and submit</h3>
                        <p class="mt-2 text-sm text-text-muted">Review your total and choose a 50% down payment or full payment. Transfer the amount shown, then upload your receipt when you submit your booking.</p>
                    </li>
                    <li class="border-t border-border pt-5 text-left">
                        <span class="block text-xs font-semibold tracking-[0.18em] text-accent">04</span>
                        <h3 class="mt-3 text-lg font-bold text-text">Track your booking</h3>
                        <p class="mt-2 text-sm text-text-muted">Check Review pending in My Reservations while staff verify your payment and review your booking. Once approved, open your confirmed reservation for your check-in pass.</p>
                    </li>
                    <li class="border-t border-border pt-5 text-left">
                        <span class="block text-xs font-semibold tracking-[0.18em] text-accent">05</span>
                        <h3 class="mt-3 text-lg font-bold text-text">Check in at the resort</h3>
                        <p class="mt-2 text-sm text-text-muted">Show your check-in pass when you arrive and settle any remaining balance before checking in.</p>
                    </li>
                </ol>
            </div>
        </section>

        {{-- Mission & vision --}}
        <section id="mission" class="bg-surface py-12 sm:py-20 scroll-mt-24" x-reveal>
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 grid lg:grid-cols-2 gap-12 lg:gap-16 items-center">
                <div class="relative pb-8">
                    <img
                        src="{{ asset('images/mission-entrance.jpg') }}"
                        alt="Villa Mercedes Nature Farm &amp; Resort entrance sign in front of the lodge"
                        loading="lazy"
                        decoding="async"
                        class="w-full rounded-2xl object-cover object-[center_78%] aspect-[16/9] lg:aspect-[5/3] shadow-none"
                    >
                    <div class="absolute bottom-0 right-4 bg-primary/90 px-4 py-3 text-white sm:right-8">
                        <p class="font-semibold">Heartful hospitality</p>
                        <p class="text-xs text-white/70">Nature farm &amp; resort</p>
                    </div>
                </div>

                <div>
                    <p class="font-heading text-3xl italic leading-snug text-primary md:text-4xl">
                        A family gathering place in Ilagan that grew into a nature farm and resort — still hosted with the same heartful hospitality.
                    </p>

                    <div class="mt-8 grid sm:grid-cols-2 gap-4">
                        <div class="border-t border-border pt-5">
                            <h2 class="font-bold text-text">Mission</h2>
                            <p class="mt-2 text-sm text-text-muted leading-relaxed">{{ config('resort.mission') }}</p>
                        </div>
                        <div class="border-t border-border pt-5">
                            <h2 class="font-bold text-text">Vision</h2>
                            <p class="mt-2 text-sm text-text-muted leading-relaxed">{{ config('resort.vision') }}</p>
                        </div>
                    </div>

                    <dl class="mt-10 grid grid-cols-2 sm:grid-cols-4 gap-6">
                        @foreach(config('resort.values') as $value)
                            <div class="text-left sm:text-center">
                                <dt class="font-heading text-4xl font-semibold text-primary">{{ $value['letter'] }}</dt>
                                <dd class="mt-1 text-sm font-semibold text-text">{{ $value['title'] }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            </div>
        </section>

        {{-- Gallery --}}
        <section
            id="gallery"
            class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-20 pb-8 scroll-mt-24"
            x-data="{ lightbox: null, items: {{ \Illuminate\Support\Js::from($galleryItems->values()) }} }"
            x-reveal
        >
            <x-section-heading title="Experience our world" class="mb-12">
                Rooms, pools, cottages, and villas — a look at the grounds you can book.
            </x-section-heading>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 md:gap-5">
                @foreach($galleryItems as $index => $item)
                    <button
                        type="button"
                        @class([
                            'group relative overflow-hidden rounded-2xl text-left focus:outline-none focus:ring-2 focus:ring-accent',
                            'col-span-2 aspect-[16/10]' => $index < 2,
                            'aspect-square' => $index >= 2,
                        ])
                        @unless(! empty($item['url']))
                            @style(['background: linear-gradient(160deg, rgba(98,63,31,0.95) '.($index * 8).'%, rgba(67,41,16,0.85) 100%)'])
                        @endunless
                        @click="lightbox = {{ $index }}"
                    >
                        @if(! empty($item['url']))
                            <img src="{{ $item['url'] }}" alt="{{ $item['title'] }}" loading="lazy" class="h-full w-full object-cover transition duration-700 ease-out group-hover:scale-[1.04]">
                        @endif
                        <span class="absolute inset-0 bg-gradient-to-t from-primary/85 via-primary/25 to-transparent transition duration-500 group-hover:from-primary-dark/90" aria-hidden="true"></span>
                        <span class="absolute inset-x-0 bottom-0 p-4">
                            <span class="block font-semibold text-white text-sm">{{ $item['title'] }}</span>
                            @if(! empty($item['caption']))
                                <span class="block text-xs text-accent-light mt-0.5 line-clamp-2">{{ $item['caption'] }}</span>
                            @endif
                        </span>
                    </button>
                @endforeach
            </div>



            <div class="mt-10 text-center">
                <x-primary-button tag="a" href="{{ route('gallery') }}" class="!rounded-lg !px-8 !py-3">
                    Explore complete gallery
                </x-primary-button>
            </div>

            <div
                x-show="lightbox !== null"
                x-cloak
                class="fixed inset-0 z-[60] bg-black/80 flex items-center justify-center p-6"
                @keydown.escape.window="lightbox = null"
                @click.self="lightbox = null"
            >
                <div class="max-w-3xl w-full bg-surface rounded-2xl overflow-hidden">
                    <template x-if="lightbox !== null && items[lightbox] && items[lightbox].url">
                        <img :src="items[lightbox].url" :alt="items[lightbox].title" class="w-full max-h-[70vh] object-contain bg-black">
                    </template>
                    <div class="p-6 text-center">
                        <h3 class="font-heading text-2xl font-semibold text-text" x-text="lightbox !== null ? items[lightbox].title : ''"></h3>
                        <p class="mt-3 text-text-muted" x-text="lightbox !== null ? (items[lightbox].caption || '') : ''"></p>
                        <button type="button" class="mt-6 text-sm font-medium text-primary hover:underline" @click="lightbox = null">Close</button>
                    </div>
                </div>
            </div>
        </section>

        {{-- Customer reviews --}}
        @if($homeReviews->isNotEmpty())
            <section id="reviews" class="bg-surface pt-8 pb-20 scroll-mt-24" x-reveal>
                <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    <div class="text-center">
                        <span class="mx-auto mb-3 block h-px w-10 bg-accent" aria-hidden="true"></span>
                        <h2 class="font-heading text-4xl md:text-5xl font-semibold text-primary">Customer Reviews</h2>
                    </div>

                    <div class="mt-12 grid grid-cols-1 md:grid-cols-3 gap-6 lg:gap-8">
                        @foreach($homeReviews as $review)
                            <x-review-quote :review="$review" />
                        @endforeach
                    </div>

                    <div class="mt-10 text-center">
                        <a href="{{ route('reviews') }}" class="font-semibold text-primary hover:underline">Read more reviews &rarr;</a>
                    </div>
                </div>
            </section>
        @endif

        {{-- FAQ teaser from the live catalog --}}
        @if($homeFaqs->isNotEmpty())
            <section class="bg-background py-12 sm:py-20 scroll-mt-24" id="faqs" x-reveal>
                <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 grid grid-cols-1 lg:grid-cols-2 gap-10 lg:gap-16 items-start">
                    <div class="max-w-md">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.28em] text-accent">GOOD TO KNOW</p>
                        <h2 class="mt-3 font-heading text-4xl md:text-5xl font-semibold text-text tracking-tight">
                            Questions guests ask
                        </h2>
                        <p class="mt-4 text-sm sm:text-base text-text-muted leading-relaxed">
                            Contact the resort for help with your visit.
                        </p>
                        <div class="mt-8 flex flex-wrap items-center gap-4">
                            <button
                                type="button"
                                data-support-chat-open
                                class="inline-flex min-h-[44px] items-center justify-center rounded-lg bg-primary px-6 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-primary-hover focus:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2"
                            >
                                Message the resort
                            </button>
                            <a href="{{ route('faq.index') }}" class="text-sm font-semibold text-primary hover:underline">
                                See all FAQs &rarr;
                            </a>
                        </div>
                    </div>

                    <div
                        class="space-y-3"
                        x-data="{ active: {{ (int) $homeFaqs->first()->id }} }"
                    >
                        @foreach($homeFaqs as $faq)
                            <x-faq-accordion-item :faq="$faq" />
                        @endforeach
                    </div>
                </div>
            </section>
        @endif

        {{-- Hours + map --}}
        <section class="bg-surface py-10 sm:py-16" x-reveal>
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 grid lg:grid-cols-2 gap-10 items-start">
                <div class="space-y-8">
                    <div>
                        <h2 class="font-heading text-4xl font-semibold text-primary">Our hours</h2>
                        <span class="mt-3 block h-px w-12 bg-accent" aria-hidden="true"></span>
                        <p class="mt-4 text-text-muted">{{ config('resort.hours') }}</p>
                        <dl class="mt-6 space-y-3 text-sm">
                            <div class="flex justify-between gap-4 border-b border-border pb-2">
                                <dt class="font-semibold text-text">Day use</dt>
                                <dd class="text-text-muted">{{ $time($dayUse['start']) }} &ndash; {{ $time($dayUse['end']) }}</dd>
                            </div>
                            <div class="flex justify-between gap-4 border-b border-border pb-2">
                                <dt class="font-semibold text-text">Night use</dt>
                                <dd class="text-text-muted">{{ $time($nightUse['start']) }} &ndash; {{ $time($nightUse['end']) }}</dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt class="font-semibold text-text">Overnight</dt>
                                <dd class="text-text-muted">In {{ $time($overnight['check_in']) }} / out {{ $time($overnight['check_out']) }}</dd>
                            </div>
                        </dl>
                    </div>
                    <div>
                        <h3 class="font-bold text-text">Connect with us</h3>
                        <x-social-links class="mt-3" />
                    </div>
                </div>
                <div class="min-h-[280px] overflow-hidden rounded-2xl border border-border bg-muted">
                    <iframe
                        title="Map of {{ config('resort.name') }}"
                        src="https://maps.google.com/maps?q={{ $mapsQuery }}&amp;z=15&amp;output=embed"
                        class="w-full h-[320px] border-0"
                        loading="lazy"
                        referrerpolicy="no-referrer-when-downgrade"
                    ></iframe>
                </div>
            </div>
        </section>

        {{-- Closing CTA --}}
        <section class="relative overflow-hidden">
            <div class="hero-photo-bg" style="background-image: url('{{ $hero }}')" aria-hidden="true"></div>
            <div class="absolute inset-0 bg-primary/80" aria-hidden="true"></div>
            <div class="relative max-w-3xl mx-auto px-4 py-20 text-center text-white">
                <h2 class="font-heading text-4xl md:text-5xl font-semibold">Ready for an unforgettable stay?</h2>
                <p class="mt-4 text-white/90">Check availability and reserve a room, hall, or package at Villa Mercedes.</p>
                <div class="mt-8 flex flex-col sm:flex-row justify-center gap-3">
                    <x-primary-button tag="a" href="{{ route('rooms') }}" class="!px-8 !py-3 !text-base">
                        Reserve now
                    </x-primary-button>
                    <a href="{{ config('resort.phone_href') }}" class="inline-flex items-center justify-center min-h-[44px] px-8 py-3 rounded-lg border border-white text-white font-semibold hover:bg-white/10">
                        {{ config('resort.phone') }}
                    </a>
                </div>
            </div>
        </section>

    </div>
</x-customer-layout>
