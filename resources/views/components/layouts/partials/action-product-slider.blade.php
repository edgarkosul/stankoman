@if ($products->isNotEmpty())
    <section class="space-y-4">
        <h2 class="text-2xl font-bold text-brand-green uppercase">
            Акции:
        </h2>

        <x-product.slider :products="$products" />
    </section>
@endif
