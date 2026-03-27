@php
    /** @var \App\Models\Order $order */
    /** @var \App\Support\Mail\OrderMailViewData $mailData */
@endphp

<x-mail::message>
# Новый заказ №{{ $order->order_number }}

Получен новый заказ на сайте **{{ $mailData->shopName() }}**.

<x-mail::panel>
@include('mail.partials.key-value-rows', ['rows' => $mailData->managerMetaRows()])
</x-mail::panel>

## Клиент

@include('mail.partials.key-value-rows', ['rows' => $mailData->customerContactRows()])

@if ($mailData->shouldShowManagerDeliverySection())
## Доставка

@include('mail.partials.key-value-rows', ['rows' => $mailData->managerDeliveryRows()])
@endif

## Состав заказа

@include('mail.partials.order-items-table', ['rows' => $mailData->items(true), 'showSku' => true])

@include('mail.partials.order-totals-table', ['rows' => $mailData->totals('Итого')])

С уважением,<br>
{{ $mailData->shopName() }}
</x-mail::message>
