@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head', ['title' => $title])
        @stack('head')
        @stack('styles')
    </head>
    <body>
        <div>
            <x-layouts.partials.info />
            <x-layouts.partials.header />
            <main>
                {{ $slot }}
            </main>
            <x-layouts.partials.footer />
        </div>

        @fluxScripts
        @stack('scripts')
    </body>
</html>
