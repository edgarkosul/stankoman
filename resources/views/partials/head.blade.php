@php
    $head = $head ?? [
        'title' => $title ?? config('app.name'),
        'description' => null,
        'canonical' => request()->fullUrl(),
        'robots' => 'index,follow',
        'og' => [],
        'twitter' => [],
        'schemas' => [],
    ];
@endphp

<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>{{ $head['title'] ?? $title ?? config('app.name') }}</title>
<meta name="description" content="{{ $head['description'] ?? '' }}" />
<meta name="robots" content="{{ $head['robots'] ?? 'index,follow' }}" />
<link rel="canonical" href="{{ $head['canonical'] ?? request()->fullUrl() }}" />

@foreach (($head['og'] ?? []) as $property => $value)
    @if (filled($value))
        <meta property="og:{{ $property }}" content="{{ $value }}" />
    @endif
@endforeach

@foreach (($head['twitter'] ?? []) as $name => $value)
    @if (filled($value))
        <meta name="twitter:{{ $name }}" content="{{ $value }}" />
    @endif
@endforeach

@foreach (($head['schemas'] ?? []) as $schema)
    <script type="application/ld+json">
{!! json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}
    </script>
@endforeach

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance
