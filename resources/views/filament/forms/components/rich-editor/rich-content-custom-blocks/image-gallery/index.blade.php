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
@endphp

@if (!empty($images))
    <div class="image-gallery">
        @foreach ($images as $image)
            @php
                $path = $image['file'] ?? null;
                $alt  = $image['alt'] ?? '';
                $src = $toUrl(is_string($path) ? $path : null);
            @endphp

            @if ($src)
                <img src="{{ $src }}" alt="{{ $alt }}" loading="lazy" />
            @endif
        @endforeach
    </div>
@endif
