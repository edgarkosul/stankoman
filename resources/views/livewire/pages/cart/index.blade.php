<div class="max-w-7xl mx-auto px-4 py-6">
    <div class="flex justify-between">
        <h1 class="text-2xl font-bold mb-4">Корзина</h1>

        <button
            type="button"
            wire:click="clear"
            wire:loading.attr="disabled"
            class="inline-flex items-center gap-2 rounded-lg p-2 border bg-white text-red-600 hover:bg-red-50"
            title="Очистить корзину"
        >
            <x-heroicon-o-trash class="h-5 w-5" />
        </button>
    </div>

    @if ($rows->isEmpty())
        <div class="rounded-lg border p-6 bg-white">
            <p class="text-zinc-600">Корзина пуста.</p>
        </div>
    @else
        <div class="rounded-lg border bg-white divide-y">
            @foreach ($rows as $row)
                <div
                    wire:key="cart-row-{{ $row['cart_item_id'] }}"
                    x-data="{ removing: false, rowId: {{ $row['cart_item_id'] }} }"
                    x-show="!removing"
                    x-transition.opacity.duration.200ms
                    @cart:soft-remove.window="
                        if ($event.detail.id === rowId) {
                            removing = true;
                            setTimeout(() => { $wire.finalizeRemove(rowId) }, 200);
                        }
                    "
                    class="p-4 flex flex-col gap-4"
                >
                    <div class="flex gap-4 items-center">
                        <div class="w-20 h-20 shrink-0 rounded border bg-white grid place-items-center overflow-hidden">
                            @if ($row['image'])
                                <x-product.image :src="$row['image']" :alt="$row['name']" class="object-contain w-full h-full" sizes="80px" />
                            @else
                                <div class="text-xs text-zinc-400">no image</div>
                            @endif
                        </div>

                        <div class="min-w-0 grow">
                            @if ($row['url'])
                                <a href="{{ $row['url'] }}" class="font-medium hover:underline line-clamp-2">
                                    {{ $row['name'] }}
                                </a>
                            @else
                                <div class="font-medium line-clamp-2">{{ $row['name'] }}</div>
                            @endif

                            <div class="text-sm text-zinc-500">ID: {{ $row['id'] }}</div>
                        </div>
                    </div>

                    <div class="flex items-end justify-between gap-4">
                        <div>
                            <div class="text-sm text-zinc-500">Кол-во</div>

                            <div class="mt-1 inline-flex items-center justify-between rounded-2xl bg-zinc-100 px-3 py-1.5 min-w-[120px]">
                                <button
                                    type="button"
                                    @click.prevent="$wire.decOrSoftRemove({{ $row['cart_item_id'] }})"
                                    wire:loading.attr="disabled"
                                    wire:target="decOrSoftRemove({{ $row['cart_item_id'] }})"
                                    :disabled="removing"
                                    class="px-2 py-1 rounded hover:bg-zinc-200"
                                    aria-label="Уменьшить количество"
                                >
                                    <x-heroicon-o-minus class="w-5 h-5" />
                                </button>

                                <span class="font-semibold select-none tabular-nums">{{ $row['qty'] }}</span>

                                <button
                                    type="button"
                                    wire:click="incItem({{ $row['cart_item_id'] }})"
                                    wire:loading.attr="disabled"
                                    wire:target="incItem({{ $row['cart_item_id'] }})"
                                    :disabled="removing"
                                    class="px-2 py-1 rounded hover:bg-zinc-200"
                                    aria-label="Увеличить количество"
                                >
                                    <x-heroicon-o-plus class="w-5 h-5" />
                                </button>
                            </div>
                        </div>

                        <div class="text-right">
                            @auth
                                <div class="flex gap-3 items-baseline">
                                    <span class="text-sm text-zinc-700 font-semibold">Сумма:</span>
                                    @if ($row['has_discount'])
                                        <span class="text-zinc-500 line-through">{{ price($row['subtotal']) }}</span>
                                    @endif
                                    <span class="font-bold text-lg">{{ price($row['line_total']) }}</span>
                                </div>
                                <span class="text-xs">{{ $row['with_dns'] }}</span>
                            @else
                                <div class="flex flex-col items-end">
                                    <div class="flex gap-3 items-center">
                                        <span class="text-sm text-zinc-700 font-semibold">Сумма:</span>
                                        <span class="font-bold text-lg">{{ price($row['subtotal']) }}</span>
                                    </div>
                                    <span class="text-xs">{{ $row['with_dns'] }}</span>
                                </div>
                            @endauth
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        @if ($discountSum > 0)
            <div class="text-right mt-4">
                Общая скидка: <span class="text-lg font-semibold">{{ price($discountSum) }}</span>
            </div>
        @endif

        <div class="mt-4 flex items-center justify-between">
            <div class="text-right ml-auto">
                <div class="text-sm font-semibold text-zinc-700">Итого ({{ $totalQty }} шт.)</div>
                @auth
                    <div class="text-2xl font-bold">{{ price($totalSum) }}</div>
                @else
                    <div class="text-2xl font-bold">{{ price($discTotalSum) }}</div>
                @endauth
            </div>
        </div>
    @endif
</div>
