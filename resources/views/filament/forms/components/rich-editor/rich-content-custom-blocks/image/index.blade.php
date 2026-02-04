@php
    $path = $path ?? null;
    $alt = $alt ?? '';
    $src = null;

    if (is_string($path) && $path !== '') {
        if (\Illuminate\Support\Str::startsWith($path, ['http://', 'https://', '/'])) {
            $src = $path;
        } elseif (\Illuminate\Support\Str::startsWith($path, 'storage/')) {
            $src = '/' . $path;
        } else {
            $src = \Illuminate\Support\Facades\Storage::disk('public')->url($path);
        }
    }
@endphp

@if ($src)
    <img src="{{ $src }}" alt="{{ $alt }}" loading="lazy" />
@endif
