@props(['title', 'eyebrow' => null, 'align' => 'left', 'tone' => 'dark'])

@php
    $wrap = $align === 'left' ? 'text-left' : 'text-center mx-auto';
    $titleColor = $tone === 'light' ? 'text-white' : 'text-primary';
    $bodyColor = $tone === 'light' ? 'text-white/85' : 'text-text-muted';
    $ruleColor = $tone === 'light' ? 'bg-white' : 'bg-primary';
@endphp

<div {{ $attributes->merge(['class' => 'max-w-[62ch] '.$wrap]) }}>
    @if($eyebrow)
        <p class="mb-2 text-[11px] sm:mb-3 font-semibold uppercase tracking-[0.18em] {{ $tone === 'light' ? 'text-white/70' : 'text-accent' }}">{{ $eyebrow }}</p>
    @endif
    <h2 class="font-heading text-2xl font-semibold leading-[1.05] tracking-[-0.02em] sm:text-4xl md:text-[2.75rem] {{ $titleColor }}">{{ $title }}</h2>
    <span class="mt-3 block h-px sm:mt-4 w-12 {{ $ruleColor }} {{ $align === 'left' ? '' : 'mx-auto' }}" aria-hidden="true"></span>
    @if(isset($slot) && trim($slot) !== '')
        <p class="mt-3 max-w-[58ch] text-sm sm:mt-4 sm:text-base leading-relaxed {{ $bodyColor }}">{{ $slot }}</p>
    @endif
</div>
