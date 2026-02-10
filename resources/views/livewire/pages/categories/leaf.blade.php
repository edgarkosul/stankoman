<section class="mx-auto max-w-7xl px-4 my-6 space-y-4">
    <h1 class="text-3xl font-bold">{{ $category->name }}</h1>

    <div class="flex flex-col md:flex-row gap-6">
        <aside class="md:w-72 shrink-0">
            <div class="rounded-lg border bg-white p-4 text-sm text-zinc-600">
                <div class="text-base font-semibold text-zinc-900">Фильтры</div>
                <p class="mt-2">Скоро появятся.</p>
            </div>
        </aside>

        <div class="flex-1 space-y-4">
            <div class="flex flex-wrap items-center gap-3">
                <input
                    type="search"
                    wire:model.live.debounce.500ms="q"
                    placeholder="Поиск в разделе..."
                    class="max-w-full w-64 rounded-md border border-gray-300 pl-3 pr-10 h-10 outline-none focus:ring-2 focus:ring-brand-500 bg-white"
                />
                <select
                    wire:model.live="sort"
                    class="h-10 rounded-md border border-gray-300 bg-white px-3"
                >
                    <option value="popular">По популярности</option>
                    <option value="price_asc">Цена по возрастанию</option>
                    <option value="price_desc">Цена по убыванию</option>
                    <option value="new">Новинки</option>
                </select>
            </div>

            @if ($products->isEmpty())
                <p class="text-zinc-600">Товары не найдены.</p>
            @else
                <div class="grid grid-cols-2 lg:grid-cols-3 gap-2">
                    @foreach ($products as $product)
                        <x-product.card :product="$product" :index="$loop->index" :category="$category" />
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</section>
