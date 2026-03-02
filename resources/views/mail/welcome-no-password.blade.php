<x-mail::message>
# Добро пожаловать, {{ $user->name ?? 'клиент' }}!

Личный кабинет для вашего email уже существует на сайте {{ config('app.name') }}.

Если вы не помните пароль, задайте новый по ссылке ниже.

<x-mail::button :url="$resetRequestUrl">
Установить пароль
</x-mail::button>

С уважением,<br>
{{ config('app.name') }}
</x-mail::message>
