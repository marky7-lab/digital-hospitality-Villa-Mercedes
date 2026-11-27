@props([
    'title',
    'description' => null,
    'eyebrow' => null,
    'breadcrumbs' => [],
    'back' => null,
])

{{--
    Every account page announces itself the same way.

    Before this component each page hand-rolled its own heading, and the four
    that existed disagreed on both the typeface and the scale: reservations used
    `font-heading text-4xl md:text-5xl`, inquiries and notifications dropped to
    `text-3xl font-bold`, so the same area of the product read as four different
    products. A guest learns the page language once here and it holds everywhere.

    `breadcrumbs` (a list of ['label' => …, 'href' => …]) is for a page reached
    through another page; `back` is the single-step version of the same idea.
    Prefer one of them — a page with neither is a page a guest arrived at by
    guessing.
--}}

{{-- `data-customer-page-header` is the marker the shell guard counts, so "this
     page announces itself the shared way, exactly once" is a thing a test can
     ask the rendered page instead of a convention someone remembers. --}}
<div data-customer-page-header {{ $attributes->merge(['class' => 'space-y-3 sm:space-y-5']) }}>
    @if(! empty($breadcrumbs))
        <nav aria-label="Breadcrumb" class="text-xs text-text-muted">
            <ol class="flex flex-wrap items-center gap-x-2 gap-y-1">
                @foreach($breadcrumbs as $crumb)
                    <li class="flex items-center gap-x-2">
                        @if(! $loop->last && ! empty($crumb['href']))
                            <a href="{{ $crumb['href'] }}" class="transition-colors hover:text-primary focus:outline-none focus:underline">{{ $crumb['label'] }}</a>
                        @else
                            <span @class(['font-medium text-text' => $loop->last]) @if($loop->last) aria-current="page" @endif>{{ $crumb['label'] }}</span>
                        @endif

                        @unless($loop->last)
                            <span aria-hidden="true">/</span>
                        @endunless
                    </li>
                @endforeach
            </ol>
        </nav>
    @elseif($back)
        <a href="{{ $back['href'] }}" class="inline-flex items-center gap-1.5 text-sm font-medium text-primary hover:underline focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2 rounded">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
            </svg>
            {{ $back['label'] }}
        </a>
    @endif

    <div class="flex flex-wrap items-start justify-between gap-x-6 gap-y-3 sm:gap-y-4">
        <div class="min-w-0">
            @if($eyebrow)
                <p class="mb-3 inline-flex items-center gap-3 text-[11px] font-semibold uppercase tracking-[0.18em] text-text-muted">
                    <span class="h-px w-6 bg-border" aria-hidden="true"></span>
                    {{ $eyebrow }}
                </p>
            @endif
            <h1 class="font-heading text-2xl sm:text-4xl lg:text-5xl font-semibold tracking-tight text-text">{{ $title }}</h1>

            @if($description)
                <p class="mt-2 max-w-3xl text-sm text-text-muted sm:text-base">{{ $description }}</p>
            @endif
        </div>

        @isset($actions)
            <div class="flex flex-wrap items-center gap-3">{{ $actions }}</div>
        @endisset
    </div>
</div>
