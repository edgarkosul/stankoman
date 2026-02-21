<button
    type="button"
    wire:click="goToCart"
    class="relative flex flex-col items-center text-sm {{ $count > 0 ? 'cursor-pointer' : 'cursor-default' }}"
>
    <x-icon name="cart" class="size-6 xl:size-5 -translate-y-0.5 [&_.icon-base]:text-zinc-800 [&_.icon-accent]:text-brand-red" />

    <span class="hidden xl:block">Корзина</span>

    @if ($count > 0)
        <span
            class="absolute -top-1 -right-2 min-w-5 h-5 px-1 rounded-full bg-brand-red ring-2 ring-white text-white text-xs flex items-center justify-center"
        >
            {{ $count }}
        </span>
    @endif
</button>
