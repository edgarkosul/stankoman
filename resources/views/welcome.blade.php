<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="color-scheme" content="light">
        <meta name="supported-color-schemes" content="light">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <link rel="icon" href="{{ asset('favicon.ico') }}" type="image/x-icon">
        <link rel="shortcut icon" href="{{ asset('favicon.ico') }}">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">
        <link rel="manifest" href="/site.webmanifest">
    </head>
    <body style="margin: 0; min-height: 100vh; background: #fdfdfc; color: #1b1b18; font-family: ui-sans-serif, system-ui, sans-serif;">
        <main style="display: grid; min-height: 100vh; place-items: center; padding: 1.5rem;">
            <a href="{{ route('home') }}" style="color: inherit; text-decoration: none;">
                {{ config('app.name', 'Laravel') }}
            </a>
        </main>
    </body>
</html>
