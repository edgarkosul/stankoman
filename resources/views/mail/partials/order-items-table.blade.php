<x-mail::table>
| Товар |
|:------|
@foreach ($rows as $row)
| <span class="order-item-name">{{ $row['name'] }}</span>@if ($showSku)<span class="order-item-sku">SKU: {{ $row['sku'] }}</span>@endif<span class="order-item-facts"><span class="order-item-fact"><span class="order-item-fact-label">Кол-во</span> <span class="order-item-fact-value">{{ $row['quantity'] }}</span></span><span class="order-item-fact"><span class="order-item-fact-label">Цена</span> <span class="order-item-fact-value">{{ $row['price'] }}</span></span><span class="order-item-fact"><span class="order-item-fact-label">Сумма</span> <span class="order-item-fact-value">{{ $row['total'] }}</span></span></span> |
@endforeach
</x-mail::table>
