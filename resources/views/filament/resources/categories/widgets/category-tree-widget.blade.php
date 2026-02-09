<div
    x-data="{
        open: {},
        expandableIds: @js($expandableIds),
        toggle(id) { this.open[id] = !this.open[id] },
        isOpen(id) { return !!this.open[id] },
        collapseAll() { this.open = {} },
        expandAll() { this.expandableIds.forEach(id => this.open[id] = true) },
    }"
    class="space-y-2"
>
    {{-- Тулбар --}}
    <div class="flex items-center gap-2">
        <x-filament::button color="gray" size="xs" @click="collapseAll()">Свернуть все</x-filament::button>
        <x-filament::button color="gray" size="xs" @click="expandAll()">Развернуть все</x-filament::button>
    </div>

    @foreach ($roots as $node)
        @include('filament.resources.categories.widgets.partials.tree-node', ['node' => $node, 'selectedId' => $selectedId])
    @endforeach
</div>
