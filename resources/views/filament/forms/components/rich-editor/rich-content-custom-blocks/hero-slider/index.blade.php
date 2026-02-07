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

    $slides = collect($slider?->slides ?? [])
        ->filter(fn ($slide) => is_array($slide))
        ->map(function (array $slide) use ($toUrl): array {
            $src = $toUrl(is_string($slide['image'] ?? null) ? $slide['image'] : null);

            return [
                'src' => $src,
                'url' => is_string($slide['url'] ?? null) ? $slide['url'] : null,
                'alt' => is_string($slide['alt'] ?? null) ? $slide['alt'] : '',
            ];
        })
        ->filter(fn (array $slide): bool => filled($slide['src']))
        ->values();
@endphp

@if ($slides->isNotEmpty())
    <div class="hero-slider" data-hero-slider>
        @foreach ($slides as $slide)
            @if ($slide['url'])
                <a class="hero-slider__slide" href="{{ $slide['url'] }}">
                    <img src="{{ $slide['src'] }}" alt="{{ $slide['alt'] }}" loading="lazy" />
                </a>
            @else
                <div class="hero-slider__slide">
                    <img src="{{ $slide['src'] }}" alt="{{ $slide['alt'] }}" loading="lazy" />
                </div>
            @endif
        @endforeach
    </div>
@endif
