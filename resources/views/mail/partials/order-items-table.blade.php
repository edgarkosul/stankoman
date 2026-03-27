<x-mail::table>
| Товар |@if ($showSku) SKU |@endif Кол-во | Цена | Сумма |
|:------|@if ($showSku) :--- |@endif -----:| ----:| -----:|
@foreach ($rows as $row)
| {{ $row['name'] }} |@if ($showSku) {{ $row['sku'] }} |@endif {{ $row['quantity'] }} | {{ $row['price'] }} | {{ $row['total'] }} |
@endforeach
</x-mail::table>
