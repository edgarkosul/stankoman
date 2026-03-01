@props([
    /** @var \Illuminate\Support\Collection|\Illuminate\Database\Eloquent\Collection|array $products */
    'products' => collect(),
])

<div class="product-slider action-product-slider swiper group relative w-full max-w-full min-w-0 overflow-hidden select-none">
    <button
        type="button"
        data-nav="prev"
        class="pointer-events-auto absolute left-2 top-1/2 z-10 hidden h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full bg-white/90 shadow ring-1 ring-black/10 transition hover:bg-white md:flex group-hover:flex"
    >
        <x-heroicon-s-arrow-left class="size-5" />
        <span class="sr-only">Назад</span>
    </button>

    <button
        type="button"
        data-nav="next"
        class="pointer-events-auto absolute right-2 top-1/2 z-10 hidden h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full bg-white/90 shadow ring-1 ring-black/10 transition hover:bg-white md:flex group-hover:flex"
    >
        <x-heroicon-s-arrow-right class="size-5" />
        <span class="sr-only">Вперёд</span>
    </button>

    <div class="swiper-wrapper">
        @foreach ($products as $product)
            <div class="swiper-slide">
                <x-product.card :product="$product" :index="$loop->index" />
            </div>
        @endforeach
    </div>
</div>
