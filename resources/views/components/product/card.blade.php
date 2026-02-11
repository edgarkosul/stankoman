@props(['product'])

@php
    $basePrice = $product->price_int;
    $discount = $product->discount;
    $hasDiscount = $product->has_discount;
    $pct = $product->discount_percent;

    $gallery = $product->gallery ?? [];
    if (is_string($gallery)) {
        $gallery = preg_split('/[|,\\s]+/', $gallery, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    $galleryItems = collect(is_array($gallery) ? $gallery : [])
        ->map(function ($item) {
            if (is_array($item)) {
                return $item['file'] ?? $item['path'] ?? $item['src'] ?? null;
            }

            return $item;
        });

    $images = collect([$product->image, $product->thumb])
        ->merge($galleryItems)
        ->map(fn($value) => is_string($value) ? trim($value) : null)
        ->filter()
        ->unique()
        ->values();

    if ($images->isEmpty()) {
        $images = collect([null]);
    }

    $hasMultipleImages = $images->count() > 1;
    $imageSizes = '(min-width: 1280px) 300px, (min-width: 1024px) 260px, (min-width: 640px) 240px, 50vw';
@endphp

<div wire:key="product-card-{{ $product->id }}"
    class="relative overflow-hidden bg-white flex flex-col justify-between ring-0 ring-transparent transition-shadow duration-200 ease-out hover:ring-6 hover:ring-white">
    <a href="{{ route('product.show', $product->slug) }}" target="_blank" rel="noopener noreferrer"
        class="relative flex h-full flex-col justify-between" aria-label="Открыть товар">
        @if ($pct)
            <div
                class="absolute right-0 inline-flex items-center justify-center text-lg py-2 px-3 font-medium bg-brand-red/30 text-brand-red z-30">
                −{{ $pct }}%
            </div>
        @endif

        <div class="flex flex-col justify-start min-w-0">
            <div class="swiper product-card__swiper h-48 w-full min-w-0" style="height: 12rem;">
                <div class="swiper-wrapper h-full">
                    @foreach ($images as $image)
                        <div class="swiper-slide h-full w-full">
                            <x-product.image :src="$image" :alt="$product->name" class="w-full h-full object-contain"
                                sizes="{{ $imageSizes }}" loading="lazy" />
                        </div>
                    @endforeach
                </div>
                @if ($hasMultipleImages)
                    <div class="swiper-pagination product-card__pagination"></div>
                @endif
            </div>
            <div class="p-4 gap-3">
                <div class="text-2xl font-bold">
                    @if ($hasDiscount)
                        <div class="flex items-center gap-2">
                            <span class="text-brand-900">
                                @price($discount)
                            </span>
                            <div class="flex flex-col">
                                <span class="line-through text-zinc-400 text-base">
                                    @price($basePrice)
                                </span>
                            </div>
                        </div>
                    @elseif ($basePrice === 0)
                        <div class="text-xl font-bold text-brand-700">
                            Цена по запросу
                        </div>
                    @else
                        @price($basePrice)
                    @endif
                </div>
            </div>
            @if ($product->sku)
                <div class="font-medium text-sm px-4 py-2">Артикул: {{ $product->sku }}</div>
            @endif

            <div class="font-semibold text-brand-900 px-4">{{ $product->name }}</div>
        </div>
    </a>
</div>
