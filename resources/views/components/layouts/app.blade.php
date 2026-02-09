@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scrollbar-w-0.5 scrollbar scrollbar-thumb-brand-green scrollbar-track-zinc-50">

<head>
<link rel="icon" href="/favicon.ico?v=2" sizes="any">
<link rel="icon" href="/favicon.svg?v=2" type="image/svg+xml">
<link rel="icon" href="/favicon-32x32.png?v=2" type="image/png" sizes="32x32">
<link rel="icon" href="/favicon-16x16.png?v=2" type="image/png" sizes="16x16">

<link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png?v=2">

<link rel="manifest" href="/site.webmanifest">
<meta name="theme-color" content="#ffffff">
    @include('partials.head', ['title' => $title])
    @stack('head')
    @stack('styles')
</head>

<body>
    <div class="flex min-h-screen flex-col">
        <x-layouts.partials.info />
        <x-layouts.partials.header />
        <x-navigation.breadcrumbs />
        <main class="flex-1">
            {{ $slot }}
        </main>
        <x-layouts.partials.footer />
    </div>

    @fluxScripts
    @stack('scripts')
</body>

</html>
