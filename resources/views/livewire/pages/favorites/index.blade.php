<section class="max-w-7xl mx-auto px-3 py-4 md:py-6" wire:cloak>
    <header class="mb-4 md:mb-6 flex items-center justify-between gap-3">
        <h1 class="text-xl md:text-2xl font-semibold">Избранное</h1>

        @if ($products->total() > 0)
            <div class="flex items-center gap-3 pr-4">
                <button
                    type="button"
                    wire:click="clearAll"
                    wire:loading.attr="disabled"
                    class="inline-flex items-center gap-2 rounded-lg border px-3 py-2 bg-white text-red-600 hover:bg-red-50"
                    title="Удалить все из избранного"
                >
                    <x-heroicon-o-trash class="h-5 w-5" />
                    <span>Удалить всё</span>
                </button>

                <div class="text-sm text-zinc-500">Всего: {{ $products->total() }}</div>
            </div>
        @endif
    </header>

    <div class="mb-4 flex flex-wrap items-center gap-3">
        <input
            type="search"
            wire:model.live.debounce.500ms="q"
            placeholder="Поиск в избранном..."
            class="max-w-full w-64 rounded border border-zinc-300 pl-3 pr-10 h-10 outline-none focus:ring-2 focus:ring-brand-green bg-white"
        />

        <select wire:model.live="sort" class="h-10 border border-zinc-300 bg-white px-3">
            <option value="popular">По популярности</option>
            <option value="price_asc">Цена по возрастанию</option>
            <option value="price_desc">Цена по убыванию</option>
            <option value="new">Новинки</option>
        </select>
    </div>

    @if ($products->count() === 0)
        <div class="rounded-xl border border-dashed border-zinc-300 p-8 text-center text-zinc-500">
            В избранном ничего не найдено.
        </div>
    @else
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-2">
            @foreach ($products as $product)
                <x-product.card
                    :product="$product"
                    :index="$loop->index"
                    :category="$product->primaryCategory()"
                    :favorite="true"
                />
            @endforeach
        </div>

        <div class="mt-6">
            {{ $products->onEachSide(1)->links() }}
        </div>
    @endif
</section>
