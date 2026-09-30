{{--
    Письмо об эскалации. Строки-реквизиты рисует тот же партиал, что
    и в заявке «перезвоните»: менеджер читает эти письма в одном ящике.

    Тексты реплик выводятся через nl2br(e()), а не markdown-абзацами:
    в них уже снятая разметка и живые переносы строк, и звёздочка из
    сообщения покупателя не должна превращаться в курсив.
--}}
<x-mail::message>
# {{ $title }}

В чате на сайте **{{ $shopName }}** ждут ответа менеджера.

<x-mail::panel>
@include('mail.partials.key-value-rows', ['rows' => $rows])
</x-mail::panel>

@if ($reason)
**Почему передали:** {{ $reason }}
@endif

@if ($transcript !== [])
## Последние сообщения

@foreach ($transcript as $line)
<div style="margin: 0 0 14px;">
<div style="margin: 0 0 4px; font-size: 13px; line-height: 18px; font-weight: 700;">{{ $line['who'] }}</div>
<div style="margin: 0; font-size: 15px; line-height: 22px;">{!! nl2br(e($line['body'])) !!}</div>
</div>
@endforeach
@endif

<x-mail::button :url="$adminUrl">
Открыть диалог в админке
</x-mail::button>

С уважением,<br>
{{ $shopName }}
</x-mail::message>
