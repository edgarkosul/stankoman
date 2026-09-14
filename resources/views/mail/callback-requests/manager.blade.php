<x-mail::message>
# {{ $title }}

Покупатель оставил контакты на сайте **{{ $shopName }}** и ждёт, что с ним свяжутся.

<x-mail::panel>
@include('mail.partials.key-value-rows', ['rows' => $rows])
</x-mail::panel>

<x-mail::button :url="$adminUrl">
Открыть заявку в админке
</x-mail::button>

С уважением,<br>
{{ $shopName }}
</x-mail::message>
