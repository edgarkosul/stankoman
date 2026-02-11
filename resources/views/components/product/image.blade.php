@props([
    'src' => null,
    'alt' => null,
    'webpSrcset' => null,
])

@php
    $src = is_string($src) ? trim($src) : null;

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

    $url = $toUrl($src);

    $storagePath = null;
    if (is_string($src) && $src !== '') {
        if (\Illuminate\Support\Str::startsWith($src, 'storage/')) {
            $storagePath = \Illuminate\Support\Str::after($src, 'storage/');
        } elseif (\Illuminate\Support\Str::startsWith($src, '/storage/')) {
            $storagePath = \Illuminate\Support\Str::after($src, '/storage/');
        } elseif (! \Illuminate\Support\Str::startsWith($src, ['http://', 'https://', '/'])) {
            $storagePath = $src;
        }
    }

    if (! $webpSrcset && $storagePath) {
        $resolver = app(\App\Support\ImageDerivativesResolver::class);
        $webpSrcset = $resolver->buildWebpSrcset($storagePath);
    }

    $imgAttributes = $attributes->merge(['class' => 'w-full ']);
@endphp
@if ($url)
    @if ($webpSrcset)
        <picture>
            <source type="image/webp" srcset="{{ $webpSrcset }}" sizes="100vw">
            <img
                src="{{ $url }}"
                alt="{{ $alt ?? '' }}"
                {{ $imgAttributes }}
            />
        </picture>
    @else
        <img
            src="{{ $url }}"
            alt="{{ $alt ?? '' }}"
            {{ $imgAttributes }}
        />
    @endif
@else
    <div {{ $attributes->merge(['class' => 'flex items-center justify-center rounded border border-dashed border-zinc-300 bg-zinc-50 p-6 text-sm text-zinc-500']) }}>
        Нет изображения
    </div>
@endif
