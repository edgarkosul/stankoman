<div class="compare-page mx-auto max-w-7xl py-6 px-4">
    @if (empty($vm['products']))
        <p class="text-zinc-600">Пока пусто. Добавляйте товары кнопкой «В сравнение».</p>
    @else
        @php($attrs = $vm['attributes'] ?? [])
        @php($cols = $vm['products'] ?? [])

        <div class="mb-4 flex items-center gap-4">
            <h1 class="text-2xl font-bold">Сравнение</h1>

            <div class="ml-auto">
                <button
                    type="button"
                    wire:click="clear"
                    wire:loading.attr="disabled"
                    class="inline-flex items-center gap-2 rounded-lg border px-3 py-2 bg-white text-red-600 hover:bg-red-50"
                    title="Удалить все товары из сравнения"
                >
                    <x-heroicon-o-trash class="h-5 w-5" />
                    <span>Удалить всё</span>
                </button>
            </div>
        </div>

        @if (empty($cols))
            <p class="text-zinc-600">Пока пусто. Добавляйте товары кнопкой «В сравнение».</p>
        @else
            <div
                x-data="compareEqualizer"
                x-init="init()"
                x-on:resize.window.debounce.150="measure()"
                x-on:compare:equalize.window="reobserve()"
                x-cloak
                class="flex relative overflow-hidden"
            >
                <aside class="hidden md:block sticky left-0 top-0 z-20 w-72 shrink-0 bg-white border border-zinc-200">
                    <div class="p-3 border-b js-attr-head">
                        <div class="font-semibold">Параметры</div>

                        <div class="mt-2 flex flex-wrap items-center gap-3 text-sm">
                            <button
                                type="button"
                                wire:click="showAll"
                                @class([
                                    'px-2 py-1 rounded-lg transition',
                                    '!bg-brand-50 !text-brand-700 font-semibold ring-1 ring-inset ring-brand-200' => ! $diff,
                                    'text-zinc-600 hover:text-zinc-900 underline cursor-pointer' => $diff,
                                ])
                            >
                                Все
                            </button>

                            <button
                                type="button"
                                wire:click="showDiff"
                                @class([
                                    'px-2 py-1 rounded-lg transition',
                                    '!bg-brand-50 !text-brand-700 font-semibold ring-1 ring-inset ring-brand-200' => $diff,
                                    'text-zinc-600 hover:text-zinc-900 underline cursor-pointer' => ! $diff,
                                ])
                            >
                                Различающиеся
                            </button>

                            <label class="inline-flex items-center gap-2 text-xs">
                                <input type="checkbox" wire:model.change="nonempty" class="rounded">
                                Скрыть пустые
                            </label>
                        </div>
                    </div>

                    <div class="divide-y divide-zinc-200">
                        @foreach ($attrs as $i => $a)
                            <div class="p-3 js-attr-row {{ ! $a['all_equal'] ? 'bg-blue-50/50' : '' }}" data-i="{{ $i }}">
                                <div class="flex items-baseline gap-2">
                                    <span class="font-medium">{{ $a['name'] }}</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </aside>

                <section class="relative border compare-swiper min-w-0 flex-1" data-cols-count="{{ count($cols) }}">
                    <div class="swiper js-compare-swiper">
                        <div class="flex swiper-wrapper gap-px bg-zinc-200">
                            @foreach ($cols as $col)
                                <div class="swiper-slide bg-white" wire:key="cmp-slide-{{ $col['id'] }}">
                                    <div class="flex flex-col p-3 z-10 bg-white border-b js-val-head space-y-2">
                                        <div class="flex justify-between">
                                            <div class="text-sm text-zinc-500">{{ $col['sku'] }}</div>

                                            <button
                                                type="button"
                                                wire:click="removeItem({{ $col['id'] }})"
                                                class="cursor-pointer hover:text-red-500"
                                                title="Убрать товар из сравнения"
                                            >
                                                <x-heroicon-o-x-mark class="size-6" />
                                            </button>
                                        </div>

                                        <div class="flex items-start gap-3">
                                            @if ($col['image'])
                                                <x-product.image
                                                    :src="$col['image']"
                                                    alt=""
                                                    class="w-16 h-16 object-contain rounded-md"
                                                    sizes="64px"
                                                />
                                            @endif

                                            <div class="min-w-0">
                                                <a
                                                    href="{{ $col['url'] }}"
                                                    class="font-medium hover:underline line-clamp-2"
                                                    title="{{ $col['name'] }}"
                                                >
                                                    {{ $col['name'] }}
                                                </a>

                                                @if (! empty($col['category']))
                                                    <div class="text-xs text-zinc-500">{{ $col['category'] }}</div>
                                                @endif
                                            </div>
                                        </div>

                                        <div class="flex flex-col gap-2">
                                            <span class="text-lg font-bold">
                                                {{ price($col['price']) }}
                                            </span>

                                            <div x-data x-on:click.stop.prevent>
                                                <livewire:pages.cart.actions
                                                    :product-id="$col['id']"
                                                    :qty="1"
                                                    :options="[]"
                                                    :variant="'compare'"
                                                    :key="'compare-cart-' . $col['id']"
                                                />
                                            </div>
                                        </div>
                                    </div>

                                    <div class="divide-y divide-zinc-200">
                                        @foreach ($attrs as $i => $a)
                                            @php($cell = $col['values'][$i] ?? null)
                                            <div class="p-3 js-val-row {{ ! $a['all_equal'] ? 'bg-blue-50/50' : '' }}" data-i="{{ $i }}">
                                                <div class="md:hidden mb-1 text-xs text-zinc-500">
                                                    <span class="font-medium text-zinc-700">{{ $a['name'] }}</span>
                                                    @if (! empty($a['unit']))
                                                        <span>{{ ' ' . $a['unit'] }}</span>
                                                    @endif
                                                </div>

                                                @if ($cell && $cell['label'] !== null)
                                                    <span class="font-semibold">{{ $cell['label'] }}</span>
                                                @else
                                                    <span class="text-zinc-400">—</span>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <button
                        type="button"
                        class="flex absolute -right-3 top-15 z-50 rounded-full border p-2 text-2xl shadow js-compare-next bg-white/50"
                    >
                        <x-heroicon-s-arrow-right class="size-7 text-brand-900/50" />
                    </button>

                    <button
                        type="button"
                        class="flex absolute md:-left-6 top-15 z-50 rounded-full border p-2 text-2xl shadow js-compare-prev bg-white/50"
                    >
                        <x-heroicon-s-arrow-left class="size-7 text-brand-900/50" />
                    </button>
                </section>
            </div>
        @endif
    @endif
</div>
