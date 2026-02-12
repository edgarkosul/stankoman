@php
    $tabBlocks = $tabBlocks ?? [];
@endphp

@if (empty($tabBlocks))
    <div class="rounded border border-dashed border-zinc-300 p-4 text-sm text-zinc-600">
        Контент вкладок будет добавлен позже.
    </div>
@else
    <div class="grid gap-4">
        @foreach ($tabBlocks as $block)
            <article class="rounded border border-zinc-200 p-4">
                <h3 class="text-base font-semibold">{{ $block['title'] }}</h3>
                <div class="mt-2 text-sm text-zinc-700">
                    {!! $block['content'] !!}
                </div>
            </article>
        @endforeach
    </div>
@endif
