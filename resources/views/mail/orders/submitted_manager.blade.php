@php
    /** @var \App\Models\Order $order */
    $shippingMethod = $order->shipping_method?->value ?? $order->shipping_method;
    $shippingMethodLabel = $shippingMethod ? __('order.shipping_method.'.$shippingMethod) : null;
    if ($shippingMethodLabel === 'order.shipping_method.'.$shippingMethod) {
        $shippingMethodLabel = $shippingMethod;
    }

    $paymentMethod = $order->payment_method;
    $paymentMethodLabel = $paymentMethod ? __('order.payment_method.'.$paymentMethod) : null;
    if ($paymentMethodLabel === 'order.payment_method.'.$paymentMethod) {
        $paymentMethodLabel = $paymentMethod;
    }
@endphp

<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Новый заказ №{{ $order->order_number }}</title>
</head>
<body style="font-family:Arial,sans-serif;background:#f4f4f5;margin:0;padding:20px;color:#111827;">
    <div style="max-width:760px;margin:0 auto;background:#fff;border:1px solid #e4e4e7;padding:20px;">
        <h1 style="margin:0 0 12px;">Новый заказ с сайта {{ config('app.name') }}</h1>
        <p style="margin:0 0 12px;">
            Номер заказа: <strong>{{ $order->order_number }}</strong><br>
            Дата: {{ optional($order->submitted_at ?? $order->created_at)->format('d.m.Y H:i') }}
        </p>

        <h2 style="font-size:16px;margin:18px 0 8px;">Контакты клиента</h2>
        <p style="margin:0 0 10px;">
            {{ $order->customer_name }}<br>
            @if ($order->customer_phone)
                Телефон: {{ $order->customer_phone }}<br>
            @endif
            @if ($order->customer_email)
                Email: {{ $order->customer_email }}<br>
            @endif
            @if ($order->is_company && $order->company_name)
                Компания: {{ $order->company_name }}<br>
            @endif
            @if ($order->inn)
                ИНН: {{ $order->inn }}<br>
            @endif
            @if ($order->kpp)
                КПП: {{ $order->kpp }}
            @endif
        </p>

        @if ($shippingMethodLabel || $order->shipping_city || $order->shipping_street || $order->shipping_house || $order->shipping_comment)
            <h2 style="font-size:16px;margin:18px 0 8px;">Доставка</h2>
            <p style="margin:0 0 10px;">
                @if ($shippingMethodLabel)
                    Способ: {{ $shippingMethodLabel }}<br>
                @endif
                @if ($order->shipping_country || $order->shipping_region || $order->shipping_city || $order->shipping_street || $order->shipping_house || $order->shipping_postcode)
                    Адрес:
                    {{ collect([$order->shipping_postcode, $order->shipping_country, $order->shipping_region, $order->shipping_city, $order->shipping_street, $order->shipping_house])->filter()->implode(', ') }}<br>
                @endif
                @if ($order->pickup_point_id)
                    ПВЗ: {{ $order->pickup_point_id }}<br>
                @endif
                @if ($order->shipping_comment)
                    Комментарий: {{ $order->shipping_comment }}
                @endif
            </p>
        @endif

        @if ($paymentMethodLabel)
            <p style="margin:0 0 12px;">Способ оплаты: <strong>{{ $paymentMethodLabel }}</strong></p>
        @endif

        <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
            <thead>
                <tr>
                    <th align="left" style="padding:8px 0;border-bottom:1px solid #e4e4e7;">Товар</th>
                    <th align="left" style="padding:8px 0;border-bottom:1px solid #e4e4e7;">SKU</th>
                    <th align="center" style="padding:8px 0;border-bottom:1px solid #e4e4e7;">Кол-во</th>
                    <th align="right" style="padding:8px 0;border-bottom:1px solid #e4e4e7;">Цена</th>
                    <th align="right" style="padding:8px 0;border-bottom:1px solid #e4e4e7;">Сумма</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($order->items as $item)
                    <tr>
                        <td style="padding:8px 0;">{{ $item->name }}</td>
                        <td style="padding:8px 0;">{{ $item->sku }}</td>
                        <td align="center" style="padding:8px 0;">{{ (int) $item->quantity }}</td>
                        <td align="right" style="padding:8px 0;">{{ price($item->price_amount ?? 0) }}</td>
                        <td align="right" style="padding:8px 0;">{{ price($item->total_amount ?? 0) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <table width="100%" cellpadding="0" cellspacing="0" style="margin-top:16px;">
            <tr>
                <td align="right" style="padding:4px 0;">Товары:</td>
                <td align="right" style="padding:4px 0;width:180px;">{{ price($order->items_subtotal ?? 0) }}</td>
            </tr>
            @if ((float) ($order->discount_total ?? 0) > 0)
                <tr>
                    <td align="right" style="padding:4px 0;">Скидка:</td>
                    <td align="right" style="padding:4px 0;width:180px;">-{{ price($order->discount_total ?? 0) }}</td>
                </tr>
            @endif
            @if ((float) ($order->shipping_total ?? 0) > 0)
                <tr>
                    <td align="right" style="padding:4px 0;">Доставка:</td>
                    <td align="right" style="padding:4px 0;width:180px;">{{ price($order->shipping_total ?? 0) }}</td>
                </tr>
            @endif
            <tr>
                <td align="right" style="padding:8px 0;border-top:1px solid #e4e4e7;"><strong>Итого:</strong></td>
                <td align="right" style="padding:8px 0;border-top:1px solid #e4e4e7;width:180px;">
                    <strong>{{ price($order->grand_total ?? 0) }}</strong>
                </td>
            </tr>
        </table>

        <p style="margin:14px 0 0;color:#71717a;font-size:12px;">
            ID заказа: {{ $order->id }} | Hash: {{ $order->public_hash }}
        </p>
    </div>
</body>
</html>
