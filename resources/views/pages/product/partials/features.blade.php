<section class="space-y-3  bg-white p-4">
    <h2 class="text-lg font-semibold">Характеристики</h2>

    <ul class="grid gap-2 text-sm text-zinc-700">
        @forelse ($features as $feature)
            <li>
                <span class="font-medium">{{ $feature['name'] }}:</span>
                {{ $feature['value'] }}
            </li>
        @empty
            <li class="text-zinc-500">Характеристики пока не заполнены.</li>
        @endforelse
    </ul>
</section>
