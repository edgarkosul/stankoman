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

    $galleryImages = collect($images ?? [])
        ->map(function ($image) use ($toUrl): array {
            $path = is_array($image) ? ($image['file'] ?? null) : null;
            $alt = is_array($image) ? ($image['alt'] ?? '') : '';
            $src = $toUrl(is_string($path) ? $path : null);
            $width = null;
            $height = null;

            if (is_string($path) && $path !== '') {
                $storagePath = \Illuminate\Support\Str::startsWith($path, 'storage/')
                    ? \Illuminate\Support\Str::after($path, 'storage/')
                    : $path;

                if (! \Illuminate\Support\Str::startsWith($storagePath, ['http://', 'https://', '/'])) {
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
                }
            }

            return [
                'src' => $src,
                'alt' => $alt,
                'width' => $width,
                'height' => $height,
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
                            <img src="{{ $image['src'] }}" alt="{{ $image['alt'] }}" loading="lazy" />
                        </a>
                    </div>
                @endforeach
            </div>

            <div class="swiper-button-next" data-image-gallery-next></div>
            <div class="swiper-button-prev" data-image-gallery-prev></div>
        </div>

        <div class="swiper image-gallery__thumbs" data-image-gallery-thumbs>
            <div class="swiper-wrapper">
                @foreach ($galleryImages as $image)
                    <div class="swiper-slide">
                        <img src="{{ $image['src'] }}" alt="{{ $image['alt'] }}" loading="lazy" />
                    </div>
                @endforeach
            </div>
        </div>
    </div>
@endif
