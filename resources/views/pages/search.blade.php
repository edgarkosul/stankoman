<x-layouts.app :title="'Поиск: ' . e($q)">
    <section class="mx-auto flex-1 max-w-7xl space-y-4 bg-zinc-100/80 px-4 py-6">
        <h1 class="text-2xl font-semibold md:text-3xl">
            Результаты поиска для «{{ $q }}»
        </h1>

        @if ($items instanceof \Illuminate\Support\Collection)
            <div class="rounded-xl border border-dashed border-zinc-300 bg-white p-6 text-zinc-500">
                Введите минимум 2 символа для поиска.
            </div>
        @elseif ($items->isEmpty())
            <div class="rounded-xl border border-dashed border-zinc-300 bg-white p-6 text-zinc-500">
                По вашему запросу ничего не найдено.
            </div>
        @else
            <p class="text-sm text-zinc-500">
                Найдено: {{ $items->total() }}
            </p>

            <div class="grid grid-cols-2 gap-2 md:grid-cols-3 lg:grid-cols-4">
                @foreach ($items as $product)
                    <x-product.card
                        :product="$product"
                        :index="$loop->index"
                        :category="$product->primaryCategory()"
                    />
                @endforeach
            </div>

            <div class="mt-6">
                {{ $items->onEachSide(1)->links() }}
            </div>
        @endif
    </section>
</x-layouts.app>
