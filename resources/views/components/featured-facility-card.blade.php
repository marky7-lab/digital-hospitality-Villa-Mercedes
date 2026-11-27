{{-- `label` is optional corner chrome for a hand-picked stay ("Family favourite",
     "Best value"). It is a caller decision on purpose: there is no facility
     column behind it, so a label only ever appears when whoever curates the
     section writes one. --}}
@props(['facility', 'label' => null])

@php
    $coverImageUrl = $facility->cover_image_path
        ? \Illuminate\Support\Facades\Storage::disk('public')->url($facility->cover_image_path)
        : null;
    $rate = $facility->base_rate_amount;
    $capacity = $facility->max_capacity ?? $facility->capacity;
    $rateUnit = preg_replace('/^(?:per\s+)+/i', 'per ', trim((string) ($facility->base_rate_unit ?? '')));
    $detailRoute = \App\Support\CustomerNav::isCustomer()
        ? 'customer.facilities.show'
        : 'facilities.show';
@endphp

<article class="group flex h-full flex-col overflow-hidden rounded-2xl border border-border bg-surface shadow-none transition-colors duration-200 hover:border-primary/50">
    <div class="relative aspect-video sm:aspect-[16/10] overflow-hidden bg-muted">
        @if($coverImageUrl)
            <img
                src="{{ $coverImageUrl }}"
                alt="{{ $facility->name }}"
                loading="lazy"
                decoding="async"
                class="h-full w-full object-cover transition duration-500 ease-out group-hover:scale-[1.03]"
            >
        @else
            <div class="flex h-full items-center justify-center bg-gradient-to-br from-primary/90 to-primary text-white/80">
                <svg class="h-12 w-12" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" /></svg>
            </div>
        @endif
        @if($label)
            <span class="absolute left-4 top-4 inline-flex items-center rounded-full bg-primary/95 px-3 py-1 text-xs font-semibold text-white shadow-sm">
                {{ $label }}
            </span>
        @endif
    </div>

    <div class="flex flex-1 flex-col p-3.5 sm:p-5">
        <h3 class="font-heading text-2xl font-semibold sm:text-3xl leading-tight text-text">
            <a href="{{ route($detailRoute, $facility) }}" class="hover:text-primary focus:outline-none focus:text-primary">
                {{ $facility->name }}
            </a>
        </h3>
        <p class="mt-3 line-clamp-2 text-sm leading-relaxed text-text-muted">{{ $facility->description }}</p>
        <p class="mt-3 flex items-center sm:mt-4 gap-1.5 text-sm text-text-muted">
            <svg class="h-4 w-4 shrink-0 text-primary" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" /></svg>
            Up to {{ $capacity }} guests
        </p>

        <div class="mt-auto flex items-end justify-between gap-3 border-t border-border pt-3 sm:gap-4 sm:pt-5">
            <div>
                @if($rate !== null)
                    <p class="text-xs font-semibold uppercase tracking-wide text-text-muted">From</p>
                    <p class="font-heading text-2xl font-semibold sm:text-3xl text-primary"><x-amount :value="$rate" /></p>
                    @if($rateUnit !== '')
                        <p class="text-xs text-text-muted">{{ $rateUnit }}</p>
                    @endif
                @else
                    <p class="text-sm font-medium text-text-muted">Ask us for the rate</p>
                @endif
            </div>
            <a href="{{ route($detailRoute, $facility) }}" class="inline-flex min-h-[44px] items-center gap-1 rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-primary-hover focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2">
                View details<span class="sr-only"> for {{ $facility->name }}</span>
                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" /></svg>
            </a>
        </div>
    </div>
</article>
