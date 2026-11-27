@props(['id', 'label' => 'password'])

<div class="relative mt-2" x-data="{ revealed: false }">
    <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-text-muted">
        <svg aria-hidden="true" focusable="false" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="5" y="11" width="14" height="10" rx="2" /><path stroke-linecap="round" d="M8 11V8a4 4 0 018 0v3" /></svg>
    </span>
    <x-text-input :id="$id" {{ $attributes->class('block min-h-11 w-full rounded-xl ps-11 pe-14') }}
                  type="password" data-custom-password-reveal x-bind:type="revealed ? 'text' : 'password'" spellcheck="false" />
    <button type="button"
            x-cloak
            class="absolute inset-y-0 end-0 inline-flex min-h-11 min-w-11 items-center justify-center rounded-e-xl bg-transparent text-text-muted hover:text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary"
            aria-label="{{ __('Show :field', ['field' => $label]) }}"
            data-show-label="{{ __('Show :field', ['field' => $label]) }}"
            data-hide-label="{{ __('Hide :field', ['field' => $label]) }}"
            aria-controls="{{ $id }}"
            x-bind:aria-label="revealed ? $el.dataset.hideLabel : $el.dataset.showLabel"
            @click="revealed = ! revealed">
        <svg aria-hidden="true" focusable="false" x-show="!revealed" class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg>
        <svg aria-hidden="true" focusable="false" x-show="revealed" x-cloak class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" /></svg>
    </button>
</div>
