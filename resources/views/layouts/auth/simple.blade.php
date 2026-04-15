<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">

<head>
    @include('partials.head')
</head>

<body class="min-h-screen bg-zinc-300 antialiased dark:bg-linear-to-b dark:from-neutral-950 dark:to-neutral-900">
    @include('partials.yandex-metrika-noscript')

    <div class="bg-background flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
        <div class="flex w-full max-w-lg flex-col gap-2 bg-white px-10 py-10">
            <a href="{{ route('home') }}" class="flex flex-col items-center gap-2 font-medium" wire:navigate>
                {{-- <span class="flex w-36 mb-1 items-start justify-start "> --}}
                    <x-icon name="logo"
                        class="w-48 mb-4 h-auto hidden xs:block [&_.brand-gray]:text-brand-gray [&_.white]:text-white [&_.brand-red]:text-brand-red [&_.brand-dark]:text-black self-start" />
                {{-- </span> --}}
                <span class="sr-only">{{ config('app.name', 'Laravel') }}</span>
            </a>
            <div class="flex flex-col gap-6">
                {{ $slot }}
            </div>
        </div>
    </div>
    @fluxScripts
</body>

</html>
