@php
    $specs = collect($specs ?? [])->values();
    $specCount = $specs->count();
    $columnSize = max(1, (int) ceil($specCount / 2));
    $specColumns = $specCount > 0 ? $specs->chunk($columnSize) : collect();
@endphp

<section class="space-y-5  py-6  lg:py-8">
    <h2 class="text-xl font-semibold leading-tight text-zinc-900 sm:text-2xl">Характеристики</h2>

    @if ($specCount === 0)
        <p class="text-sm text-zinc-500">Характеристики пока не заполнены.</p>
    @else
        <div class="grid grid-cols-1 gap-0 lg:grid-cols-2 lg:gap-x-12">
            @foreach ($specColumns as $column)
                <div class="space-y-0">
                    @foreach ($column as $spec)
                        <div
                            class="grid grid-cols-[minmax(0,1fr)_12rem] items-center gap-3 border-b border-zinc-300 py-3 text-sm leading-snug text-zinc-900 sm:grid-cols-[minmax(0,1fr)_12rem] sm:gap-5  lg:grid-cols-[minmax(0,1fr)_12rem]">
                            <span class="min-w-0 truncate whitespace-nowrap pr-2">{{ $spec['name'] }}</span>
                            <span class="truncate whitespace-nowrap text-left font-medium text-zinc-900">{{ $spec['value'] }}</span>
                        </div>
                    @endforeach
                </div>
            @endforeach
        </div>
    @endif
</section>
