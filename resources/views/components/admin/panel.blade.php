@props([
    'title' => null,
    'description' => null,
    'badge' => null,
    'padding' => true,
])

@php
    // One container vocabulary for the whole back office: 12px radius, a
    // hairline border, no shadow. Elevation is declared once (border only), so
    // a panel never becomes a "ghost card" with both a border and a soft glow.
    $bodyClasses = $padding ? 'p-3.5 sm:p-5' : '';
@endphp

<section {{ $attributes->class(['overflow-hidden rounded-xl border border-border bg-surface']) }}>
    @if ($title || isset($actions) || $description)
        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-border bg-muted/40 px-3.5 py-3 sm:px-5 sm:py-4">
            <div class="min-w-0">
                @if ($title)
                    {{-- Counts sit beside the heading rather than in the action row,
                         so a narrow screen never wraps them onto their own line. The
                         hook on the count lets a panel that refreshes itself keep it in
                         step with the list it counts. --}}
                    <h2 class="flex items-center gap-2 text-base font-bold tracking-tight text-text">
                        {{ $title }}
                        @if ($badge !== null)
                            <span data-panel-badge class="inline-flex min-w-[1.5rem] items-center justify-center rounded-full bg-primary-light px-2 py-0.5 text-xs font-semibold tabular-nums text-primary">
                                {{ $badge }}
                            </span>
                        @endif
                    </h2>
                @endif

                @if ($description)
                    <p class="mt-1 max-w-[70ch] text-sm leading-5 text-text-muted">{{ $description }}</p>
                @endif
            </div>

            @isset($actions)
                <div class="flex min-w-0 max-w-full flex-wrap items-center gap-2">
                    {{ $actions }}
                </div>
            @endisset
        </div>
    @endif

    <div class="{{ $bodyClasses }}">
        {{ $slot }}
    </div>
</section>
