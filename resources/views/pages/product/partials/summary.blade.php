<section class="space-y-4 bg-white p-4 shadow-[0_0_18px_rgba(0,0,0,0.12)]">
    <div class="flex flex-col gap-3">
        <div class="flex items-end gap-3">
            <div class="text-3xl wght-700 wdth-70">{{ price(data_get($summary, 'price.final')) }}</div>
            @if (data_get($summary, 'price.has_discount'))
                <p class="text-xl text-zinc-500 wght-500 wdth-70 line-through decoration-2 decoration-brand-red">
                    {{ price(data_get($summary, 'price.base')) }}
                </p>
            @endif
        </div>
        <button
            class="text-lg font-bold uppercase bg-brand-green p-3 my-4 hover:bg-brand-green/90 text-white flex items-center gap-2 justify-center"><x-icon
                name="cart" class="w-6 h-6 -translate-y-0.5 mr-2 " />В корзину</button>
        <dl class="grid gap-2 text-zinc-700">
            @foreach (data_get($summary, 'details', []) as $detail)
                <div class="grid gap-1 sm:grid-cols-[150px_1fr] sm:gap-2">
                    <dt class="text-zinc-500">{{ $detail['label'] }}</dt>
                    <dd>{{ $detail['value'] }}</dd>
                </div>
            @endforeach
        </dl>
    </div>

    @if (filled(data_get($summary, 'promo_info')))
        <div class="border-t border-zinc-200 pt-3 text-sm text-zinc-700">
            {{ data_get($summary, 'promo_info') }}
        </div>
    @endif

</section>
