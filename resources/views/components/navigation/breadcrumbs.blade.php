@props(['class' => ''])

@aware(['title' => null, 'breadcrumbTitle' => null])

@php
    $items = $items ?? [];
    $fallbackItems = [];

    if (empty($items)) {
        $fallbackTitle = $breadcrumbTitle ?: $title;
        if (!empty($fallbackTitle)) {
            $fallbackItems = [['title' => (string) $fallbackTitle, 'url' => null]];
        }
    }

    $breadcrumbs = $items ?: $fallbackItems;
    $isHome = request()->routeIs('home') || request()->is('/');
@endphp

@if (!empty($schemaBreadcrumbsJsonLd))
    <script type="application/ld+json">
{!! $schemaBreadcrumbsJsonLd !!}
    </script>
@endif

@if (! $isHome)
    <nav class="w-full text-sm text-zinc-700 {{ $class }}">
        <div class="mx-auto flex max-w-7xl flex-col gap-2 px-4 py-3 xs:flex-row xs:items-center xs:justify-between">
            <ol class="flex flex-wrap items-center gap-1 xs:gap-2 lg:gap-3">
                @if ($home)
                    <li class="flex">
                        <a href="{{ url('/') }}" class="inline-flex items-center gap-2 hover:underline">
                            <x-icon name="home" class="h-4 w-4" />
                            <span>Главная</span>
                        </a>
                    </li>
                @endif

                @foreach ($breadcrumbs as $bc)
                    <li class="inline-flex items-center gap-1">
                        <x-icon name="arrow_right_reg" class="h-3 w-3 text-zinc-400" />
                        @if (empty($bc['url']))
                            <span class="font-semibold">{{ $bc['title'] }}</span>
                        @else
                            <a href="{{ $bc['url'] }}" class="hover:underline">
                                {{ $bc['title'] }}
                            </a>
                        @endif
                    </li>
                @endforeach
            </ol>

            @if (!empty($updated))
                <p class="whitespace-nowrap text-sm font-semibold text-zinc-600">
                    Обновление от {{ $updated }} г.
                </p>
            @endif
        </div>
    </nav>
@endif
