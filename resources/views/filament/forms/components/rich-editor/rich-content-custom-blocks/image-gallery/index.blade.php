@php
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

    $galleryImages = collect($images ?? [])
        ->map(function ($image) use ($toUrl, $resolver): array {
            $path = is_array($image) ? ($image['file'] ?? null) : null;
            $alt = is_array($image) ? ($image['alt'] ?? '') : '';
            $src = $toUrl(is_string($path) ? $path : null);
            $width = null;
            $height = null;
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
                    $disk = \Illuminate\Support\Facades\Storage::disk('public');

                    if ($disk->exists($storagePath)) {
                        $absolutePath = $disk->path($storagePath);

                        if (is_file($absolutePath)) {
                            $size = getimagesize($absolutePath);

                            if (is_array($size)) {
                                [$width, $height] = $size;
                            }
                        }
                    }

                    $webpSrcset = $resolver->buildWebpSrcset($storagePath);
                }
            }

            return [
                'src' => $src,
                'alt' => $alt,
                'width' => $width,
                'height' => $height,
                'webpSrcset' => $webpSrcset,
            ];
        })
        ->filter(fn (array $image): bool => filled($image['src']))
        ->values();

    $width = is_numeric($width ?? null) && (int) $width > 0 ? (int) $width : null;
    $alignment = in_array($alignment ?? null, ['left', 'center'], true) ? $alignment : 'center';
    $alignmentClass = $alignment === 'center' ? 'image-gallery--center' : 'image-gallery--left';
@endphp

@if ($galleryImages->isNotEmpty())
    <div
        class="image-gallery {{ $alignmentClass }}"
        data-image-gallery
        @if ($width) style="max-width: min(100%, {{ $width }}px);" @endif
    >
        <div class="swiper image-gallery__main" data-image-gallery-main>
            <div class="swiper-wrapper">
                @foreach ($galleryImages as $image)
                    <div class="swiper-slide">
                        <a
                            href="{{ $image['src'] }}"
                            @if ($image['width'] && $image['height'])
                                data-pswp-width="{{ $image['width'] }}"
                                data-pswp-height="{{ $image['height'] }}"
                            @endif
                            target="_blank"
                        >
                            @if ($image['webpSrcset'])
                                <picture>
                                    <source type="image/webp" srcset="{{ $image['webpSrcset'] }}" sizes="100vw">
                                    <img src="{{ $image['src'] }}" alt="{{ $image['alt'] }}" loading="lazy" />
                                </picture>
                            @else
                                <img src="{{ $image['src'] }}" alt="{{ $image['alt'] }}" loading="lazy" />
                            @endif
                        </a>
                    </div>
                @endforeach
            </div>

            <div class="swiper-button-next max-sm:!hidden" data-image-gallery-next></div>
            <div class="swiper-button-prev max-sm:!hidden" data-image-gallery-prev></div>
        </div>

        <div class="swiper image-gallery__thumbs" data-image-gallery-thumbs>
            <div class="swiper-wrapper">
                @foreach ($galleryImages as $image)
                    <div class="swiper-slide">
                        @if ($image['webpSrcset'])
                            <picture>
                                <source type="image/webp" srcset="{{ $image['webpSrcset'] }}" sizes="100vw">
                                <img src="{{ $image['src'] }}" alt="{{ $image['alt'] }}" loading="lazy" />
                            </picture>
                        @else
                            <img src="{{ $image['src'] }}" alt="{{ $image['alt'] }}" loading="lazy" />
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    </div>
@endif
