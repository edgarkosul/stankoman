@if ($products->isNotEmpty())
    <section class="mt-8 w-full min-w-0 space-y-4">
        <h2 class="text-2xl font-bold text-brand-green uppercase">
            Аналогичные товары:
        </h2>

        <x-product.slider :products="$products" />
    </section>
@endif
