<x-mail::message>
# Сброс пароля

Мы получили запрос на сброс пароля для аккаунта на сайте {{ $shopName }}.

<x-mail::panel>
@if (filled($user->name ?? null))
Аккаунт: {{ $user->name }}<br>
@endif
Email: {{ $user->email }}
</x-mail::panel>

<x-mail::button :url="$resetUrl">
Сбросить пароль
</x-mail::button>

Ссылка действует {{ $expiresInMinutes }} мин. Если вы не запрашивали сброс пароля, просто проигнорируйте это письмо.

С уважением,<br>
{{ $shopName }}

<x-mail::subcopy>
Если кнопка не открывается, используйте эту ссылку: {{ $resetUrl }}
</x-mail::subcopy>
</x-mail::message>
