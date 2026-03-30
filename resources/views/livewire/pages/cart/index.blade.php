<div class="bg-zinc-200 flex-1 py-10">
    <div class="max-w-5xl mx-auto px-4 py-2 xs:px-8 xs:py-6">
        <div class="flex justify-between">
            <h1 class="text-3xl font-bold mb-6">Корзина</h1>

            <button
                type="button"
                wire:click="clear"
                wire:loading.attr="disabled"
                class="group inline-flex items-center gap-2 p-2"
                title="Очистить корзину"
            >
                <x-icon
                    name="trash"
                    class="h-6 w-6 [&_.icon-base]:text-gray-800 [&_.icon-accent]:text-brand-red group-hover:[&_.icon-accent]:text-brand-red/50 group-hover:[&_.icon-base]:text-gray-100 transition-colors"
                />
            </button>
        </div>

        @if ($rows->isEmpty())
            <div class="p-6 bg-white">
                <p class="text-zinc-600">Корзина пуста.</p>
            </div>
        @else
            <div class=" bg-white divide-y divide-zinc-200">
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
                        <div class="flex items-start gap-4">
                            <div class="w-20 h-20 shrink-0  bg-white grid place-items-center overflow-hidden">
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

                            <button
                                type="button"
                                wire:click="removeItem({{ $row['cart_item_id'] }})"
                                wire:loading.attr="disabled"
                                wire:target="removeItem({{ $row['cart_item_id'] }})"
                                :disabled="removing"
                                class="shrink-0 rounded-full p-1.5 text-zinc-500 transition hover:bg-zinc-100 hover:text-red-500"
                                title="Удалить товар из корзины"
                                aria-label="Удалить товар из корзины"
                            >
                                <x-heroicon-o-x-mark class="size-5" />
                            </button>
                        </div>

                        <div class="flex items-end justify-between gap-4 max-xs:flex-col max-xs:items-stretch">
                            <div>
                                <div class="text-sm text-zinc-500">Кол-во</div>

                                <div class="mt-1 inline-flex items-center justify-between  bg-zinc-100 px-3 py-1.5 min-w-30">
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

                            <div class="text-right max-xs:w-full">
                                @auth
                                    <div class="flex flex-col items-end gap-1">
                                        <div class="flex items-baseline gap-3 max-xs:flex-col max-xs:items-end max-xs:gap-1">
                                            <span class="text-sm font-semibold text-zinc-700">Сумма:</span>

                                            <div class="flex flex-wrap items-baseline justify-end gap-x-3 gap-y-1">
                                                @if ($row['has_discount'])
                                                    <span class="text-zinc-500 line-through">{{ price($row['subtotal']) }}</span>
                                                @endif

                                                <span class="text-lg font-bold">{{ price($row['line_total']) }}</span>
                                            </div>
                                        </div>

                                        <span class="text-xs">{{ $row['with_dns'] }}</span>
                                    </div>
                                @else
                                    <div class="flex flex-col items-end">
                                        <div class="flex items-baseline gap-3 max-xs:flex-col max-xs:items-end max-xs:gap-1">
                                            <span class="text-sm font-semibold text-zinc-700">Сумма:</span>
                                            <span class="text-lg font-bold">{{ price($row['subtotal']) }}</span>
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
                <a href="{{ route('checkout.index') }}"
                    class="inline-flex h-11 items-center gap-2 bg-brand-green px-4 text-sm font-semibold text-white hover:bg-[#1c7731]">
                    <x-heroicon-o-shopping-bag class="h-5 w-5" />
                    <span>Оформить заказ</span>
                </a>

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
</div>
