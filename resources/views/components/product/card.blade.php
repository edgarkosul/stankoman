@props([
    'category',
    'product',
    'index' => 0,
    'favorite' => false, // новый флаг: если true — показываем крестик удаления
])

<div x-data="{ removing: false }" x-show="!removing" x-transition.opacity.duration.200ms
    wire:key="product-card-{{ $product->id }}-{{ $index }}"
    class="relative group border rounded-lg overflow-hidden hover:shadow-xl bg-white flex flex-col justify-between">
    <a href="{{ route('product.show', $product->slug) }}" target="_blank" rel="noopener noreferrer"
        class="absolute inset-0" aria-label="Открыть товар"></a>
    {{-- Если карточка отображается в избранном — показываем кнопку удаления --}}
    @if ($favorite)
        <button type="button" x-tooltip.top.offset-10.skid-30="'Убрать из избранного'"
            @click="
                removing = true;
                $wire.removeFavorite({{ $product->id }});
            "
            class="absolute top-2 right-2 z-10 rounded-full bg-white/70 hover:bg-white shadow p-1">
            <x-heroicon-o-x-mark class="size-5 text-zinc-600 hover:text-red-500 transition" />
        </button>
    @endif
    @php
        $basePrice = (int) ($product->price ?? ($product->price ?? 0));
        $discount = $product->discount_price;
        $hasDiscount = !is_null($discount) && $discount > 0 && $discount < $basePrice;
        $pct = $hasDiscount ? (int) round(100 - ($product->discount_price / $product->price) * 100) : null;
    @endphp
    @if ($pct && $pct > 0)
        <div
            class="absolute right-0 inline-flex items-center justify-center rounded-bl-lg text-lg py-2 px-3 font-medium bg-red-50 text-red-600 z-30">
            −{{ $pct }}%
        </div>
    @endif

    <div class="flex flex-col justify-start">
        <div class="font-medium text-sm px-4 py-2">Артикул: {{ $product->sku }}</div>
        <x-product.image
            :src="$product->image"
            :alt="$product->name"
            class="w-full h-48 object-contain"
            sizes="(min-width: 1280px) 300px, (min-width: 1024px) 260px, (min-width: 640px) 240px, 50vw"
            loading="lazy"
        />

        <div class="font-semibold text-brand-900 px-4">{{ $product->name }}</div>
    </div>

    <div class="flex flex-col px-4 pb-4 gap-3">
        {{-- мини-спеки: имя — значение (включая юнит) --}}
        <div class="text-sm text-zinc-600">

        </div>

        <div class="text-xl font-bold">
            @if ($hasDiscount)
                <div class="flex flex-col">
                    <span class="line-through text-zinc-400 text-sm">
                        @price($basePrice)

                    </span>
                    <span class="text-brand-900">
                        @price($discount)

                    </span>
                </div>
            @elseif ($product->price_int === 0)
                <div class="text-xl font-bold text-brand-700">
                    Цена по запросу
                </div>
            @else
                @price($basePrice)
            @endif
        </div>

        <div class="flex items-center gap-3 justify-start text-zinc-500">

        </div>
    </div>
</div>
