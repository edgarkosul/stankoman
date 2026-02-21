@php
    $isCompareVariant = $variant === 'compare';
    $iconSizeClass = $variant === 'card' ? 'size-7' : 'size-6';
    $iconToneClass = $added
        ? '[&_.icon-base]:text-brand-red [&_.icon-accent]:text-brand-red'
        : '[&_.icon-base]:text-zinc-700/70 [&_.icon-accent]:text-zinc-700/70 group-hover:[&_.icon-base]:text-zinc-700 group-hover:[&_.icon-accent]:text-rose-600';
@endphp

<div x-data x-on:click.stop.prevent class="flex items-end gap-2 overflow-hidden z-30">
    <button
        type="button"
        wire:click.stop.prevent="toggle"
        wire:loading.attr="disabled"
        title="{{ $tooltip }}"
        class="group inline-flex items-center {{ $isCompareVariant ? 'gap-2' : '' }}"
    >
        <x-icon name="bokmark" class="{{ $iconSizeClass }} {{ $iconToneClass }}" />

        @if ($isCompareVariant)
            <span class="whitespace-nowrap text-zinc-800 hover:text-brand-red hidden md:block">
                {{ $added ? 'В избранном' : 'В избранное' }}
            </span>
        @endif
    </button>
</div>
