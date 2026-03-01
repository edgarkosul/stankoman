<div x-data x-init="$wire.load($store.recent ? $store.recent.ids() : [])" x-cloak class="w-full min-w-0">
    @if ($products->isNotEmpty())
        <section class="mt-8 w-full min-w-0 space-y-4">
            <h2 class="text-2xl font-bold text-brand-green uppercase">
                Вы недавно смотрели:
            </h2>

            <x-product.slider :products="$products" />
        </section>
    @endif
</div>
