@props(['facility', 'showImage' => true, 'query' => null, 'available' => false, 'showDescription' => true])

@php
    $baseRates = $facility->rates
        ->filter(fn ($rate) => $rate->type === \App\Enums\RateType::Base && $rate->is_active)
        ->sortBy(fn ($rate) => (float) $rate->amount);
    $baseRate = $baseRates->first() ?? $facility->rates->first();
    $roomUnit = $facility->isRoom() ? $facility->unitLabel() : null;
    $cardTitle = $facility->isFlyerPackage()
        ? ($facility->stay_category?->label() ?? $facility->name)
        : ($roomUnit ? $facility->listingCategory() : $facility->name);
    $photoCount = $facility->gallery_photo_count
        ?? ($facility->relationLoaded('galleryImages') ? $facility->galleryImages->count() : 0);
    $coverUrl = $facility->cover_image_path
        ? \Illuminate\Support\Facades\Storage::disk('public')->url($facility->cover_image_path)
        : ($facility->relationLoaded('galleryImages') ? $facility->galleryImages->first()?->url() : null);
    // Dates from the availability bar or reservation picker follow into the detail page.
    $stayQuery = array_filter(
        is_array($query)
            ? $query
            : array_merge(
                \App\Support\StayQuery::parameters(request()),
                array_filter(request()->only(['date', 'arrival', 'departure']), fn ($value) => filled($value)),
            ),
        fn ($value) => $value !== null && $value !== '',
    );
    $detailRoute = \App\Support\CustomerNav::isCustomer()
        ? 'customer.facilities.show'
        : 'facilities.show';
    $rateUnit = $baseRate
        ? preg_replace('/^(?:per\s+)+/i', 'per ', trim((string) $baseRate->unit))
        : '';
@endphp

<x-card class="group relative flex h-full flex-col overflow-hidden transition-colors duration-200 hover:border-primary/50 focus-within:border-primary">
    <div class="relative w-full aspect-video sm:aspect-[16/10] bg-muted flex items-center justify-center text-text-muted overflow-hidden">
        @if($showImage && $coverUrl)
            <img src="{{ $coverUrl }}" alt="{{ $facility->name }}" loading="lazy" decoding="async" class="h-full w-full object-cover transition duration-500 ease-out group-hover:scale-[1.03]" />
        @else
            <svg class="w-12 h-12 opacity-20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
        @endif

        @if($showImage && $photoCount > 1)
            <span class="absolute bottom-3 right-3 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-black/70 text-white">
                {{ $photoCount }} photos
            </span>
        @endif

        @if($roomUnit || $facility->facilityType)
            <span class="absolute top-3 left-3 inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-surface/95 text-text shadow-sm">
                {{ $roomUnit ?? ($facility->isFlyerPackage() ? 'Package' : $facility->facilityType->name) }}
            </span>
        @endif
    </div>

    <div class="flex flex-col flex-grow p-3.5 sm:p-5">
        <h3 class="font-heading text-xl font-semibold sm:text-2xl text-text leading-snug">
            <a href="{{ route($detailRoute, ['facility' => $facility, ...$stayQuery]) }}" class="focus:outline-none before:absolute before:inset-0 group-hover:text-primary transition-colors">
                <span class="relative">{{ $cardTitle }}</span>
                @if($roomUnit)<span class="sr-only"> {{ $roomUnit }}</span>@endif
            </a>
        </h3>
        @if($facility->isFlyerPackage() && $facility->stayScheduleLabel())
            <p class="mt-1 text-xs font-semibold uppercase tracking-wide text-text-muted">{{ $facility->stayScheduleLabel() }}</p>
        @elseif(! $roomUnit && $facility->unitLabel() && $facility->listingCategory() !== $facility->name)
            <p class="mt-1 text-xs font-semibold uppercase tracking-wide text-text-muted">{{ $facility->listingCategory() }} · {{ $facility->unitLabel() }}</p>
        @endif

        @if($showDescription && filled($facility->description))
            <p class="text-sm text-text-muted mt-2 line-clamp-2">
                {{ $facility->description }}
            </p>
        @endif

        <div class="mt-3 flex flex-wrap gap-x-3 sm:mt-4 sm:gap-x-4 gap-y-2 text-sm text-text-muted">
            @if($facility->bedSetupSummary())
                <span class="flex items-center gap-1.5">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8h18M5 8v11m14-11v11M4 19h16M8 8V6a2 2 0 012-2h4a2 2 0 012 2v2" /></svg>
                    {{ $facility->bedSetupSummary() }}
                </span>
            @endif

            <span class="flex items-center gap-1.5">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
                {{ $facility->includedGuestCount() }} included
            </span>

            @if($facility->hasPaxTiers())
                <span>{{ implode(' or ', $facility->paxTiers()) }} pax tiers</span>
            @else
                <span>Max {{ $facility->maxGuestCount() }}</span>
            @endif

            @php $extraPersonRate = $facility->extraPersonRate(); @endphp
            @if($extraPersonRate)
                <span class="flex items-center gap-1.5">
                    Extra person <x-amount :value="$extraPersonRate->amount" />
                </span>
            @endif

            @if($facility->amenities->count() > 0)
                <span class="flex items-center gap-1.5">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"></path></svg>
                    {{ $facility->amenities->take(2)->pluck('name')->join(', ') }}@if($facility->amenities->count() > 2) +{{ $facility->amenities->count() - 2 }} more @endif
                </span>
            @endif
        </div>

        @if($available)
            <p class="mt-3 inline-flex items-center gap-1.5 text-sm font-semibold text-success">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                </svg>
                Free for your dates
            </p>
        @endif

        <div class="mt-auto pt-3 sm:pt-4 border-t border-border flex items-end justify-between gap-3">
            <div>
                @if($baseRate)
                    <p class="text-xs font-semibold uppercase tracking-wide text-text-muted">From</p>
                    <p class="flex items-baseline gap-1">
                        <x-amount :value="$baseRate->amount" class="font-heading text-2xl font-semibold sm:text-3xl text-primary" />
                        <span class="text-sm text-text-muted">{{ $rateUnit }}</span>
                    </p>
                @else
                    <p class="text-sm font-medium text-text-muted">Ask us for the rate</p>
                @endif
            </div>

            @if($facility->isOnlineBookable())
                <a href="{{ route($detailRoute, ['facility' => $facility, ...$stayQuery]) }}"
                   class="relative z-10 inline-flex min-h-11 items-center justify-center rounded-lg bg-primary px-4 py-2.5 text-xs font-semibold uppercase tracking-[0.1em] text-white hover:bg-primary-hover focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2 transition">
                    View details<span class="sr-only"> for {{ $roomUnit ? $cardTitle.' '.$roomUnit : $facility->name }}</span>
                </a>
            @else
                <span class="relative inline-flex items-center text-xs font-semibold uppercase tracking-[0.1em] text-primary group-hover:underline">
                    View details
                </span>
            @endif
        </div>
    </div>
</x-card>
