<x-layouts.app :title="$meta['page_title']">
    <div class="mx-auto max-w-7xl px-4 py-6">
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

                @include('pages.product.partials.content-sections', ['sections' => $contentSections])

                {{-- <section class="space-y-3">
                    <h2 class="text-lg font-semibold">Контент вкладок</h2>
                    @include('pages.product.partials.tab-content')
                </section> --}}
            </div>

            <aside class="space-y-4 lg:sticky lg:top-28">
                <div class="hidden lg:block">
                    @include('pages.product.partials.summary', ['summary' => $summary])
                </div>
                @include('pages.product.partials.features', ['features' => $features])
            </aside>

        </div>
        <div>
            @include('pages.product.partials.specs', ['specs' => $specs])
        </div>
    </div>
</x-layouts.app>
