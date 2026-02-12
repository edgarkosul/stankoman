<section class="space-y-4 rounded border border-zinc-200 bg-white p-4">
    <div class="space-y-1">
        <p class="text-sm text-zinc-600">Цена</p>
        <div class="text-2xl font-semibold">{{ data_get($summary, 'price.final_formatted') }}</div>

        @if (data_get($summary, 'price.has_discount'))
            <p class="text-sm text-zinc-500">
                Старая цена: {{ data_get($summary, 'price.base_formatted') }}
            </p>
        @endif
    </div>

    <dl class="grid gap-2 text-sm text-zinc-700">
        @foreach (data_get($summary, 'details', []) as $detail)
            <div class="grid gap-1 sm:grid-cols-[150px_1fr] sm:gap-2">
                <dt class="text-zinc-500">{{ $detail['label'] }}</dt>
                <dd>{{ $detail['value'] }}</dd>
            </div>
        @endforeach
    </dl>

    @if (filled(data_get($summary, 'promo_info')))
        <div class="border-t border-zinc-200 pt-3 text-sm text-zinc-700">
            {{ data_get($summary, 'promo_info') }}
        </div>
    @endif
</section>
