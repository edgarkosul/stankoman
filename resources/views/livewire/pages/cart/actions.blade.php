@php
    $isDisabled = ! $isInStock || ! $isPrice;

    $containerClass = $extended
        ? 'w-full inline-flex items-center justify-center gap-2 px-4 py-2 text-sm font-semibold uppercase'
        : 'inline-flex items-center justify-center p-2';

    $stateClass = $isDisabled
        ? 'bg-zinc-200 text-zinc-400 cursor-not-allowed'
        : ($inCart
            ? 'bg-brand-green text-white hover:bg-brand-green/90 cursor-pointer'
            : 'bg-brand-green text-white hover:bg-brand-green/90 cursor-pointer');

    $iconToneClass = $isDisabled
        ? '[&_.icon-base]:text-zinc-400 [&_.icon-accent]:text-zinc-400'
        : '[&_.icon-base]:text-white [&_.icon-accent]:text-white';
@endphp

<div class="flex items-center gap-2 z-30 w-full" x-data x-on:click.stop.prevent>
    <button
        type="button"
        @if (! $isDisabled)
            wire:click.stop.prevent="add"
        @endif
        @disabled($isDisabled)
        aria-disabled="{{ $isDisabled ? 'true' : 'false' }}"
        title="{{ $tooltip }}"
        class="transition {{ $containerClass }} {{ $stateClass }}"
    >
        <x-icon name="cart" class="size-6 {{ $iconToneClass }}" />

        @if ($extended)
            <span class="whitespace-nowrap">{{ $inCart ? 'В корзине' : 'В корзину' }}</span>
        @endif
    </button>
</div>
