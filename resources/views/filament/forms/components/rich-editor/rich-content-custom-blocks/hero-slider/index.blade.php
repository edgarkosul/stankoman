@php
    $slider = $slider ?? null;

    $toUrl = function (?string $value): ?string {
        if (! is_string($value) || $value === '') {
            return null;
        }

        if (\Illuminate\Support\Str::startsWith($value, ['http://', 'https://', '/'])) {
            return $value;
        }

        if (\Illuminate\Support\Str::startsWith($value, 'storage/')) {
            return '/' . $value;
        }

        return \Illuminate\Support\Facades\Storage::disk('public')->url($value);
    };

    $resolver = app(\App\Support\ImageDerivativesResolver::class);

    $slides = collect($slider?->slides ?? [])
        ->filter(fn ($slide) => is_array($slide))
        ->map(function (array $slide) use ($toUrl, $resolver): array {
            $path = is_string($slide['image'] ?? null) ? $slide['image'] : null;
            $src = $toUrl($path);
            $webpSrcset = null;

            if (is_string($path) && $path !== '') {
                $storagePath = null;

                if (\Illuminate\Support\Str::startsWith($path, 'storage/')) {
                    $storagePath = \Illuminate\Support\Str::after($path, 'storage/');
                } elseif (\Illuminate\Support\Str::startsWith($path, '/storage/')) {
                    $storagePath = \Illuminate\Support\Str::after($path, '/storage/');
                } elseif (! \Illuminate\Support\Str::startsWith($path, ['http://', 'https://', '/'])) {
                    $storagePath = $path;
                }

                if (is_string($storagePath) && $storagePath !== '') {
                    $webpSrcset = $resolver->buildWebpSrcset($storagePath);
                }
            }

            return [
                'src' => $src,
                'webpSrcset' => $webpSrcset,
                'url' => is_string($slide['url'] ?? null) ? $slide['url'] : null,
                'alt' => is_string($slide['alt'] ?? null) ? $slide['alt'] : '',
            ];
        })
        ->filter(fn (array $slide): bool => filled($slide['src']))
        ->values();
@endphp

@if ($slides->isNotEmpty())
    <div class="swiper hero-slider fi-not-prose" data-hero-slider>
        <div class="swiper-wrapper">
            @foreach ($slides as $slide)
                <div class="swiper-slide">
                    @if ($slide['url'])
                        <a class="hero-slider__slide" href="{{ $slide['url'] }}">
                            @if ($slide['webpSrcset'])
                                <picture>
                                    <source type="image/webp" srcset="{{ $slide['webpSrcset'] }}" sizes="100vw">
                                    <img src="{{ $slide['src'] }}" alt="{{ $slide['alt'] }}" loading="lazy" />
                                </picture>
                            @else
                                <img src="{{ $slide['src'] }}" alt="{{ $slide['alt'] }}" loading="lazy" />
                            @endif
                        </a>
                    @else
                        <div class="hero-slider__slide">
                            @if ($slide['webpSrcset'])
                                <picture>
                                    <source type="image/webp" srcset="{{ $slide['webpSrcset'] }}" sizes="100vw">
                                    <img src="{{ $slide['src'] }}" alt="{{ $slide['alt'] }}" loading="lazy" />
                                </picture>
                            @else
                                <img src="{{ $slide['src'] }}" alt="{{ $slide['alt'] }}" loading="lazy" />
                            @endif
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="swiper-button-next max-sm:!hidden"></div>
        <div class="swiper-button-prev max-sm:!hidden"></div>
    </div>
@endif
