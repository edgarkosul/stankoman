<x-mail::message>
# Подтвердите e-mail

Подтвердите адрес электронной почты для аккаунта на сайте {{ $shopName }}.

<x-mail::panel>
@if (filled($user->name ?? null))
Аккаунт: {{ $user->name }}<br>
@endif
Email: {{ $user->email }}
</x-mail::panel>

<x-mail::button :url="$verificationUrl">
Подтвердить e-mail
</x-mail::button>

Если вы не создавали аккаунт, никаких действий не требуется.

С уважением,<br>
{{ $shopName }}

<x-mail::subcopy>
Ссылка действует ограниченное время. Если кнопка не открывается, используйте эту ссылку:
<a href="{{ $verificationUrl }}" class="break-all">{{ $verificationUrl }}</a>
</x-mail::subcopy>
</x-mail::message>
