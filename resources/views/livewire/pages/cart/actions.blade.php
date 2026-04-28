@php
    $isDisabled = !$isInStock || !$isPrice;
    $isProductLayout = $variant === 'product';
    $isCardLayout = $variant === 'card';

    $containerClass = $isProductLayout
        ? 'inline-flex h-12 w-full items-center justify-center gap-3 px-6 text-base font-semibold'
        : ($extended
            ? 'w-full inline-flex items-center justify-center gap-2 px-4 py-2 text-sm font-semibold uppercase'
            : 'inline-flex items-center justify-center p-2');

    $stateClass = $isDisabled
        ? 'bg-zinc-200 text-zinc-400 cursor-not-allowed'
        : 'button-brand-green-muted text-white cursor-pointer';

    $iconToneClass = $isDisabled
        ? '[&_.icon-base]:text-zinc-400 [&_.icon-accent]:text-zinc-400'
        : ($inCart
            ? 'cart-action-icon-muted'
            : '[&_.icon-base]:text-white [&_.icon-accent]:text-white');

    $labelToneClass = $isDisabled ? 'text-zinc-400' : ($inCart ? 'cart-action-foreground-muted' : 'text-white');

    $quantityShellClass = $isDisabled
        ? 'border-zinc-200 bg-zinc-100 text-zinc-400'
        : 'border-zinc-300 bg-white text-zinc-900';

    $quantityButtonClass = $isDisabled
        ? 'cursor-not-allowed text-zinc-300'
        : 'text-zinc-400 hover:bg-zinc-100 hover:text-zinc-700';

    $placeholderClass = $isDisabled ? 'border-zinc-200 bg-zinc-100 text-zinc-400' : 'border-zinc-200 bg-zinc-50';

    $rootClass = $isProductLayout
        ? 'grid gap-3 sm:grid-cols-[148px_minmax(0,1fr)_minmax(0,1fr)] lg:grid-cols-[148px_minmax(0,1fr)]'
        : ($isCardLayout
            ? 'flex min-w-0 items-stretch gap-2 flex-nowrap'
            : 'flex items-center gap-2');
@endphp

<div class="z-30 w-full {{ $rootClass }}" x-data x-on:click.stop.prevent>
    @if ($isProductLayout)
        <div
            class="inline-flex h-12 items-center justify-between border px-3 shadow-sm transition {{ $quantityShellClass }}">
            <button type="button" wire:click="decrementQty" wire:loading.attr="disabled"
                wire:target="decrementQty,incrementQty,add" @disabled($isDisabled) aria-label="Уменьшить количество"
                class="inline-flex size-9 items-center justify-center rounded-full transition {{ $quantityButtonClass }}">
                <x-heroicon-o-minus class="size-5" />
            </button>

            <input type="number" min="1" step="1" inputmode="numeric" wire:model.number="qty"
                wire:loading.attr="disabled" wire:target="decrementQty,incrementQty,add" @disabled($isDisabled)
                aria-label="Количество товара"
                class="no-spin w-14 border-0 bg-transparent p-0 text-center text-2xl font-semibold tabular-nums text-inherit outline-hidden focus:ring-0 disabled:cursor-not-allowed" />

            <button type="button" wire:click="incrementQty" wire:loading.attr="disabled"
                wire:target="decrementQty,incrementQty,add" @disabled($isDisabled)
                aria-label="Увеличить количество"
                class="inline-flex size-9 items-center justify-center rounded-full transition {{ $quantityButtonClass }}">
                <x-heroicon-o-plus class="size-5" />
            </button>
        </div>

        <button type="button" @if (!$isDisabled) wire:click.stop.prevent="add" @endif
            wire:loading.attr="disabled" wire:target="add,decrementQty,incrementQty" @disabled($isDisabled)
            aria-disabled="{{ $isDisabled ? 'true' : 'false' }}" title="{{ $tooltip }}"
            class="transition {{ $containerClass }} {{ $stateClass }}">
            <x-icon name="cart" class="size-6 shrink-0 {{ $iconToneClass }}" />

            @if ($extended)
                <span
                    class="min-w-0 whitespace-nowrap {{ $labelToneClass }} {{ $isCardLayout ? 'max-xs:hidden' : '' }}">{{ $inCart ? 'В корзине' : 'В корзину' }}</span>
            @endif
        </button>

        <button type="button" @if (!$isDisabled) wire:click.stop.prevent="openOneClickOrder" @endif
            wire:loading.attr="disabled" wire:target="openOneClickOrder,decrementQty,incrementQty"
            @disabled($isDisabled) aria-disabled="{{ $isDisabled ? 'true' : 'false' }}"
            title="{{ $isDisabled ? $tooltip : 'Оформить заказ в 1 клик' }}"
            class="inline-flex h-12 w-full items-center justify-center border px-6 text-base font-medium text-zinc-500 shadow-sm transition disabled:cursor-not-allowed lg:col-span-2 {{ $placeholderClass }}">
            Купить в 1 клик
        </button>
    @else
        <button type="button" @if (!$isDisabled) wire:click.stop.prevent="add" @endif
            wire:loading.attr="disabled" wire:target="add" @disabled($isDisabled)
            aria-disabled="{{ $isDisabled ? 'true' : 'false' }}" title="{{ $tooltip }}"
            class="transition {{ $containerClass }} {{ $stateClass }} {{ $isCardLayout ? 'cart-action-button min-w-10 flex-1 overflow-hidden px-6' : '' }}">
            <x-icon name="cart" class="size-6 shrink-0 {{ $iconToneClass }}" />

            @if ($extended)
                <span
                    class="min-w-0 whitespace-nowrap {{ $labelToneClass }} {{ $isCardLayout ? 'cart-action-label' : '' }}">{{ $inCart ? 'В корзине' : 'В корзину' }}</span>
            @endif
        </button>

        @if ($isCardLayout)
            <button type="button" @if (!$isDisabled) wire:click.stop.prevent="openOneClickOrder" @endif
                wire:loading.attr="disabled" wire:target="openOneClickOrder" @disabled($isDisabled)
                aria-disabled="{{ $isDisabled ? 'true' : 'false' }}"
                title="{{ $isDisabled ? $tooltip : 'Оформить заказ в 1 клик' }}"
                class="inline-flex h-full min-h-11 shrink-0 items-center justify-center border border-zinc-200 bg-zinc-50 px-3 py-2 text-center text-sm font-medium text-nowrap text-zinc-500 shadow-sm transition disabled:cursor-not-allowed {{ $placeholderClass }}">
                <span class="xs:hidden">Купить</span>
                <span class="hidden xs:inline">Купить в 1 клик</span>
            </button>
        @endif
    @endif
</div>
