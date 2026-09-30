@php
    $specs = collect($specs ?? [])->values();
    $specCount = $specs->count();
    $columnSize = max(1, (int) ceil($specCount / 2));
    $specColumns = $specCount > 0 ? $specs->chunk($columnSize) : collect();
    $withHeading = $withHeading ?? true;
@endphp

@if ($withHeading)
    <section class="space-y-5 py-6 lg:py-8">
        <h2 class="text-xl font-semibold leading-tight text-zinc-900 sm:text-2xl">Характеристики</h2>
@endif

@if ($specCount === 0)
    <p class="text-sm text-zinc-500">Характеристики пока не заполнены.</p>
@else
    <div class="grid grid-cols-1 gap-0 lg:grid-cols-2 lg:gap-x-12">
        @foreach ($specColumns as $column)
            <div class="space-y-0">
                @foreach ($column as $spec)
                    @php($specName = (string) ($spec['name'] ?? ''))
                    {{-- Значение не обрезается ни на какой ширине: ради него таблицу и открывают,
                         а подсказки у него нет. Название на телефоне тоже переносится — тап по
                         обрезанному тексту никто не угадает; с sm места хватает, там обрезка
                         с подсказкой по наведению. --}}
                    <div
                        class="grid grid-cols-2 items-start gap-3 border-b border-zinc-300 py-3 text-sm leading-snug text-zinc-900 sm:grid-cols-[minmax(0,1fr)_12rem] sm:gap-5">
                        <span
                            class="min-w-0 break-words pr-2 sm:truncate sm:whitespace-nowrap"
                            x-data="overflowTooltip(@js($specName))"
                            x-tooltip.theme-ks-light="tooltipContent"
                            x-on:mouseenter="queueSync()"
                            x-on:focus="queueSync()"
                            data-tooltip-max-width="360"
                        >{{ $specName }}</span>
                        <span class="min-w-0 break-words text-left font-medium text-zinc-900">{{ $spec['value'] }}</span>
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>
@endif

@if ($withHeading)
    </section>
@endif
