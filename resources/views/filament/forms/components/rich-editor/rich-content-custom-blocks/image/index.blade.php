@php
    $path = $path ?? null;
    $alt = $alt ?? '';
    $src = null;
    $storagePath = null;
    $webpSrcset = null;

    if (is_string($path) && $path !== '') {
        if (\Illuminate\Support\Str::startsWith($path, ['http://', 'https://', '/'])) {
            $src = $path;
            if (\Illuminate\Support\Str::startsWith($path, '/storage/')) {
                $storagePath = \Illuminate\Support\Str::after($path, '/storage/');
            }
        } elseif (\Illuminate\Support\Str::startsWith($path, 'storage/')) {
            $src = '/' . $path;
            $storagePath = \Illuminate\Support\Str::after($path, 'storage/');
        } else {
            $src = \Illuminate\Support\Facades\Storage::disk('public')->url($path);
            $storagePath = $path;
        }
    }

    if (is_string($storagePath) && $storagePath !== '') {
        $resolver = app(\App\Support\ImageDerivativesResolver::class);
        $webpSrcset = $resolver->buildWebpSrcset($storagePath);
    }
@endphp

@if ($src)
    @if ($webpSrcset)
        <picture>
            <source type="image/webp" srcset="{{ $webpSrcset }}" sizes="100vw">
            <img src="{{ $src }}" alt="{{ $alt }}" loading="lazy" />
        </picture>
    @else
        <img src="{{ $src }}" alt="{{ $alt }}" loading="lazy" />
    @endif
@endif
