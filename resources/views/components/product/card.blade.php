@props(['product', 'category' => null])

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

    $product->loadMissing([
        'attributeValues.attribute.unit',
        'attributeOptions.attribute.unit',
    ]);

    $displayCategory = $category;

    if (! $displayCategory) {
        $displayCategory = $product->relationLoaded('categories')
            ? $product->categories->firstWhere('pivot.is_primary', true)
            : $product->primaryCategory();
    }

    if ($displayCategory) {
        $displayCategory->loadMissing('attributeDefs.unit');
    }

    $cardAttributes = $displayCategory?->attributeDefs
        ?->filter(fn ($attribute) => (bool) ($attribute->pivot?->visible_in_specs ?? true))
        ->map(function ($attribute) use ($product, $displayCategory) {
            $value = $product->attrLabel($attribute, ' / ', $displayCategory);

            if (! filled($value)) {
                return null;
            }

            return [
                'name' => $attribute->name,
                'value' => $value,
            ];
        })
        ->filter()
        ->take(5)
        ->values() ?? collect();
@endphp

<div wire:key="product-card-{{ $product->id }}"
    class="relative overflow-hidden bg-white flex flex-col justify-between shadow-sm ring-0 ring-transparent transition-shadow duration-200 ease-out hover:shadow-xl hover:ring-6 hover:ring-white">
    <a href="{{ route('product.show', $product->slug) }}" rel="noopener noreferrer"
        class="relative flex h-full flex-col" aria-label="Открыть товар">
        @if ($pct)
            <div
                class="absolute left-0 inline-flex items-center justify-center text-lg py-2 px-3 font-medium bg-brand-red/70 text-white z-30">
                −{{ $pct }}%
            </div>
        @endif
        <div class="group absolute top-4 right-4 z-30 size-7" data-product-card-swiper-ignore>
            <x-icon name="bokmark"
                class="size-full text-zinc-700/70 group-hover:[&_.icon-base]:text-zinc-700 group-hover:[&_.icon-accent]:text-rose-600" />
        </div>
        <div class="group absolute top-14 right-4 z-30 size-7" data-product-card-swiper-ignore>
            <x-icon name="compare"
                class="size-full text-zinc-700/70 group-hover:[&_.icon-base]:text-zinc-700 group-hover:[&_.icon-accent]:text-rose-600" />
        </div>

        <div class="flex h-full flex-1 flex-col justify-start min-w-0">
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
                                {{ price($discount) }}
                            </span>
                            <div class="flex flex-col">
                                <span class="line-through text-zinc-400 text-base">
                                    {{ price($basePrice) }}
                                </span>
                            </div>
                        </div>
                    @elseif ($basePrice === 0)
                        <div class="text-xl font-bold text-brand-700">
                            Цена по запросу
                        </div>
                    @else
                        {{ price($basePrice) }}
                    @endif
                </div>
            </div>
            <div class="text-lg px-4">{{ $product->name }}</div>
            @if ($product->sku)
                <div class="text-sm px-4 py-2 text-brand-gray">Артикул: {{ $product->sku }}</div>
            @endif

            @if ($cardAttributes->isNotEmpty())
                <dl class="grid w-full grid-cols-[max-content_max-content] justify-between px-4 py-1 text-sm text-zinc-700 mb-3">
                    @foreach ($cardAttributes as $attribute)
                        <div class="col-span-2 grid grid-cols-subgrid justify-between border-b border-zinc-200/80">
                            <dt class="py-1 pr-2 text-zinc-500">{{ $attribute['name'] }}:</dt>
                            <dd class="min-w-0 py-1 pr-8 text-left">{{ $attribute['value'] }}</dd>
                        </div>
                    @endforeach
                </dl>
            @endif

            <button class="mt-auto mx-4 mb-4 flex items-center justify-center gap-2 bg-brand-green p-3 text-lg font-bold uppercase text-white hover:bg-brand-green/90"><x-icon name="cart" class="mr-2 -translate-y-0.5 h-6 w-6 " />В корзину</button>


        </div>
    </a>
</div>
