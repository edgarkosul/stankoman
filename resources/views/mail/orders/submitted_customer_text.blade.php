Спасибо за заказ!

Ваш заказ №{{ $order->order_number }} принят и передан в обработку.

Дата: {{ $mailData->submittedAt() }}
@if ($mailData->paymentMethodLabel())
Способ оплаты: {{ $mailData->paymentMethodLabel() }}
@endif
Доставка: {{ $mailData->deliveryMethodLabel() }}

Состав заказа:
@foreach ($mailData->items() as $item)
- {{ $item['name'] }}
  Количество: {{ $item['quantity'] }}
  Цена: {{ $item['price'] }}
  Сумма: {{ $item['total'] }}
@endforeach

Итого:
@foreach ($mailData->totals() as $row)
{{ $row['label'] }}: {{ $row['value'] }}
@endforeach

Доставка:
@foreach ($mailData->customerDeliveryRows() as $row)
{{ $row['label'] }}: {{ $row['value'] }}
@endforeach

Контакты магазина:
{{ $mailData->shopName() }}
{{ config('company.phone') }}
{{ config('company.public_email') }}
{{ config('company.site_url') }}

Если вы не оформляли этот заказ, напишите нам на {{ config('company.public_email') }}.
