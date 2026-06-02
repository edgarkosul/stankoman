@php
    /** @var \App\Models\Order $order */
    /** @var \App\Support\Mail\OrderMailViewData $mailData */

    $publicEmail = trim((string) config('company.public_email', config('mail.from.address')));
    $phone = trim((string) config('company.phone'));
    $totals = $mailData->totals();
    $grandTotal = $totals[array_key_last($totals)]['value'] ?? '—';
@endphp
<!doctype html>
<html lang="ru">
<body>
<p>Здравствуйте!</p>

<p>Ваш заказ №{{ $order->order_number }} принят.</p>

<p>Сумма заказа: {{ $grandTotal }}.</p>

<p>Контакты магазина:</p>
<p>
{{ $mailData->shopName() }}<br>
@if (filled($phone))
{{ $phone }}<br>
@endif
{{ $publicEmail }}
</p>

<p>Если вы не оформляли этот заказ, напишите нам на {{ $publicEmail }}.</p>
</body>
</html>
