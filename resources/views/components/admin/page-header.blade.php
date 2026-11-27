@props([
    'title' => null,
    // Kept, but no admin page passes it: the subtitle line under the page title
    // was removed on every screen. Restoring one is a single attribute again.
    'description' => null,
    'breadcrumbs' => [],
])

@php
    // Depth is the only reason a trail exists: it tells staff which record they
    // are inside and how to get back out of it.
    $crumbs = collect($breadcrumbs)
        ->filter(fn ($crumb) => filled($crumb['label'] ?? null))
        ->values();
@endphp

<div {{ $attributes->class(['mb-4 sm:mb-7']) }}>
    @if ($crumbs->isNotEmpty())
        <nav aria-label="Breadcrumb" class="mb-3">
            <ol class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
                @foreach ($crumbs as $crumb)
                    <li class="flex items-center gap-2">
                        @if (! $loop->first)
                            <span class="text-text-muted" aria-hidden="true">/</span>
                        @endif

                        @if (! empty($crumb['url']) && ! $loop->last)
                            <a
                                href="{{ $crumb['url'] }}"
                                class="rounded font-medium text-text-muted hover:text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2"
                            >
                                {{ $crumb['label'] }}
                            </a>
                        @else
                            <span class="font-medium text-text" @if ($loop->last) aria-current="page" @endif>{{ $crumb['label'] }}</span>
                        @endif
                    </li>
                @endforeach
            </ol>
        </nav>
    @endif

    @if ($title || isset($actions) || $description)
        <div class="flex flex-col gap-3 border-l-4 border-primary pl-3 sm:gap-4 sm:pl-4 xl:flex-row xl:items-start xl:justify-between">
            <div class="min-w-0">
                @if ($title)
                    <h1 class="text-2xl font-bold tracking-tight text-text sm:text-3xl">{{ $title }}</h1>
                @endif

                @if ($description)
                    <p class="mt-1.5 max-w-[70ch] text-sm leading-6 text-text-muted">{{ $description }}</p>
                @endif
            </div>

            @isset($actions)
                <div class="flex shrink-0 flex-wrap items-center gap-2">
                    {{ $actions }}
                </div>
            @endisset
        </div>
    @endif
</div>
