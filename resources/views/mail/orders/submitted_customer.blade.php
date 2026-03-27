@php
    /** @var \App\Models\Order $order */
    /** @var \App\Support\Mail\OrderMailViewData $mailData */
@endphp

<x-mail::message>
# Спасибо за заказ

Ваш заказ **№{{ $order->order_number }}** принят и передан в обработку.

<x-mail::panel>
**Номер заказа:** {{ $order->order_number }}

**Дата:** {{ $mailData->submittedAt() }}

@if ($mailData->paymentMethodLabel())
**Способ оплаты:** {{ $mailData->paymentMethodLabel() }}
@endif

**Доставка:** {{ $mailData->deliveryMethodLabel() }}
</x-mail::panel>

## Состав заказа

@include('mail.partials.order-items-table', ['rows' => $mailData->items(), 'showSku' => false])

@include('mail.partials.order-totals-table', ['rows' => $mailData->totals()])

## Доставка

@include('mail.partials.key-value-rows', ['rows' => $mailData->customerDeliveryRows()])

## Контакты

@include('mail.partials.key-value-rows', ['rows' => $mailData->customerContactRows()])

Спасибо,<br>
{{ $mailData->shopName() }}

<x-mail::subcopy>
Если вы не оформляли этот заказ, просто ответьте на это письмо.
</x-mail::subcopy>
</x-mail::message>
