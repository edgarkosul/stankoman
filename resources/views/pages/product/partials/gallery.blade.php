@php
    $main = data_get($gallery, 'main', []);
    $items = collect(data_get($gallery, 'items', []))
        ->filter(fn ($image) => filled(data_get($image, 'src')))
        ->values();
@endphp

<section class="space-y-3">
    @if ($items->isEmpty())
        <div class="overflow-hidden rounded border border-zinc-200 bg-white p-4">
            <x-product.image
                :src="data_get($main, 'src')"
                :alt="data_get($main, 'alt')"
                class="aspect-square w-full object-contain"
            />
        </div>
    @else
        <div
            data-image-gallery
            data-image-gallery-thumbs-direction="vertical"
            class="image-gallery image-gallery--left product-image-gallery"
        >
            <div
                class="swiper image-gallery__main w-full overflow-hidden  bg-white p-4"
                data-image-gallery-main
            >
                <div class="swiper-wrapper">
                    @foreach ($items as $image)
                        <div class="swiper-slide">
                            @if (filled(data_get($image, 'url')))
                                <a href="{{ $image['url'] }}" target="_blank" rel="noopener noreferrer">
                                    <x-product.image
                                        :src="data_get($image, 'src')"
                                        :alt="data_get($image, 'alt')"
                                        class="h-full w-full object-contain"
                                    />
                                </a>
                            @else
                                <x-product.image
                                    :src="data_get($image, 'src')"
                                    :alt="data_get($image, 'alt')"
                                    class="h-full w-full object-contain"
                                />
                            @endif
                        </div>
                    @endforeach
                </div>

                @if ($items->count() > 1)
                    <div class="swiper-button-next max-xs:!hidden" data-image-gallery-next></div>
                    <div class="swiper-button-prev max-xs:!hidden" data-image-gallery-prev></div>
                @endif
            </div>

            @if ($items->count() > 1)
                <div class="swiper image-gallery__thumbs w-full" data-image-gallery-thumbs>
                    <div class="swiper-wrapper">
                        @foreach ($items as $image)
                            <div class="swiper-slide overflow-hidden  bg-white p-1">
                                <x-product.image
                                    :src="data_get($image, 'src')"
                                    :alt="data_get($image, 'alt')"
                                    class="aspect-square w-full object-contain"
                                />
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    @endif
</section>
