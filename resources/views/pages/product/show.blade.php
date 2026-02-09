<x-layouts.app :title="$product->meta_title ?? $product->name">
    <div class="mx-auto max-w-5xl px-4 py-10">
        <h1 class="text-3xl font-semibold">{{ $product->name }}</h1>

        @if (!empty($product->meta_description))
            <p class="mt-2 text-sm text-zinc-600">{{ $product->meta_description }}</p>
        @endif

        <div class="mt-8 grid gap-6 md:grid-cols-[280px_1fr]">
            <x-product.image
                :src="$product->image_url"
                :webp-srcset="$product->image_webp_srcset"
                :alt="$product->name"
            />

            <div class="grid gap-4">
                <div>
                    <div class="text-sm text-zinc-600">Цена</div>
                    <div class="text-2xl font-semibold">
                        {{ number_format($product->price_amount, 0, ' ', ' ') }} ₽
                    </div>

                    @if ($product->discount_price)
                        <div class="text-sm text-zinc-500">
                            Скидка: {{ number_format($product->discount_price, 0, ' ', ' ') }} ₽
                        </div>
                    @endif
                </div>

                <div class="text-sm text-zinc-600">
                    Наличие: {{ $product->in_stock ? 'В наличии' : 'Нет в наличии' }}
                </div>

                @if ($product->sku)
                    <div class="text-sm text-zinc-600">Артикул: {{ $product->sku }}</div>
                @endif

                @if ($product->brand)
                    <div class="text-sm text-zinc-600">Бренд: {{ $product->brand }}</div>
                @endif
            </div>
        </div>

        <div class="mt-10 grid gap-6">
            <section>
                <h2 class="text-lg font-semibold">Описание</h2>
                <div class="mt-2 text-sm text-zinc-700">
                    {!! $product->description ?: '<p>Описание пока не заполнено.</p>' !!}
                </div>
            </section>

            @if ($product->extra_description)
                <section>
                    <h2 class="text-lg font-semibold">Дополнительное описание</h2>
                    <div class="mt-2 text-sm text-zinc-700">
                        {!! $product->extra_description !!}
                    </div>
                </section>
            @endif

            <section>
                <h2 class="text-lg font-semibold">Характеристики</h2>

                <ul class="mt-3 grid gap-2 text-sm text-zinc-700">
                    @forelse ($product->attributeValues as $value)
                        <li>
                            <span class="font-medium">{{ $value->attribute?->name ?? 'Атрибут' }}:</span>
                            {{ $value->display_value ?? '—' }}
                        </li>
                    @empty
                        <li class="text-zinc-500">Характеристики пока не заполнены.</li>
                    @endforelse

                    @foreach ($product->attributeOptions->groupBy('pivot.attribute_id') as $options)
                        <li>
                            <span class="font-medium">{{ $options->first()->attribute?->name ?? 'Опции' }}:</span>
                            {{ $options->pluck('value')->join(', ') }}
                        </li>
                    @endforeach
                </ul>
            </section>

            <section>
                <h2 class="text-lg font-semibold">Контент вкладок</h2>
                @include('pages.product.partials.tab-content')
            </section>
        </div>
    </div>
</x-layouts.app>
