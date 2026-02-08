<div class="mx-auto max-w-7xl px-4 py-10">
    <h1 class="text-3xl font-semibold">{{ $category->name }}</h1>

    @if (!empty($category->meta_description))
        <p class="mt-2 text-sm text-zinc-600">
            {{ $category->meta_description }}
        </p>
    @endif

    <div class="mt-6 grid gap-4 md:grid-cols-2">
        <label class="grid gap-2">
            <span class="text-sm font-medium">Поиск</span>
            <input
                type="text"
                class="w-full rounded border border-zinc-300 px-3 py-2"
                wire:model.live="q"
                placeholder="Название товара"
            />
        </label>

        <label class="grid gap-2">
            <span class="text-sm font-medium">Сортировка</span>
            <select
                class="w-full rounded border border-zinc-300 px-3 py-2"
                wire:model.live="sort"
            >
                <option value="popular">Популярные</option>
                <option value="price_asc">Цена по возрастанию</option>
                <option value="price_desc">Цена по убыванию</option>
                <option value="new">Новые</option>
            </select>
        </label>
    </div>

    <div class="mt-8">
        <h2 class="text-lg font-semibold">Фильтры (заглушка)</h2>
        <ul class="mt-2 list-disc pl-5 text-sm text-zinc-600">
            @forelse ($filtersSchema as $filter)
                <li wire:key="filter-{{ $filter['key'] }}">
                    {{ $filter['label'] }} ({{ $filter['type'] }})
                </li>
            @empty
                <li>Фильтры отсутствуют.</li>
            @endforelse
        </ul>
    </div>

    <div class="mt-10">
        <h2 class="text-lg font-semibold">Товары</h2>

        <div class="mt-4 grid gap-4">
            @forelse ($products as $product)
                <div class="rounded border border-zinc-200 p-4" wire:key="product-{{ $product->id }}">
                    <div class="text-base font-medium">
                        <a href="{{ route('product.show', $product) }}" class="hover:underline">
                            {{ $product->name }}
                        </a>
                    </div>

                    <div class="mt-1 text-sm text-zinc-600">
                        Цена: {{ number_format($product->price_amount, 0, ' ', ' ') }} ₽
                    </div>
                </div>
            @empty
                <p class="text-sm text-zinc-600">В этой категории пока нет товаров.</p>
            @endforelse
        </div>

        <div class="mt-6">
            {{ $products->links() }}
        </div>
    </div>
</div>
