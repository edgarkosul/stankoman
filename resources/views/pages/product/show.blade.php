<x-layouts.app :title="$meta['page_title']">
    <div class="mx-auto w-full min-w-0 max-w-7xl px-4 py-6">
        <header class="space-y-8 border-b border-brand-gray/50 pb-4">
            <h1 class="text-3xl font-semibold">{{ $meta['heading'] }}</h1>
            <div class="flex justify-between items-center">
                @include('pages.product.partials.actions')
                @if (filled($product->sku))
                    <p class="text-sm text-zinc-500"><span class="text-zinc-800">Артикул</span>: {{ $product->sku }}</p>
                @endif
            </div>
        </header>

        <div class="mt-8 grid gap-8 lg:grid-cols-[minmax(0,1.65fr)_minmax(20rem,1fr)] lg:items-start">
            <div class="space-y-8">
                @include('pages.product.partials.hero', ['gallery' => $gallery, 'summary' => $summary])
            </div>

            <aside class="space-y-4 lg:sticky lg:top-28">
                <div class="hidden lg:block">
                    @include('pages.product.partials.summary', ['summary' => $summary])
                </div>
                @include('pages.product.partials.features', ['features' => $features])
            </aside>

        </div>
        @include('pages.product.partials.tabs', ['tabs' => $tabs])

        <x-product.similar :product="$product" />

        <livewire:recent-products-slider />

        @php($productId = (int) $product->id)
        @if ($productId > 0)
            <div x-data x-init="$store.recent && $store.recent.add({{ $productId }})" class="hidden" aria-hidden="true"></div>
        @endif
    </div>
</x-layouts.app>
