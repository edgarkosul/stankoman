<div
    x-data="{ isVisible: window.scrollY > 320 }"
    x-on:scroll.window="isVisible = window.scrollY > 320"
    x-show="isVisible"
    x-transition.opacity.duration.200ms
    x-cloak
    class="pointer-events-none fixed inset-x-0 bottom-6 z-40 flex justify-end px-4 sm:bottom-8 sm:px-6 lg:px-8"
>
    <x-filament::icon-button
        color="gray"
        icon="heroicon-o-chevron-up"
        label="Наверх"
        x-on:click="window.scrollTo({ top: 0, behavior: 'smooth' })"
        class="pointer-events-auto shadow-lg ring-1 ring-gray-950/10"
    />
</div>
