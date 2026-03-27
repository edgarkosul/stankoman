<x-mail::message>
# Добро пожаловать, {{ $user->name ?? 'клиент' }}!

Мы создали для вас личный кабинет на сайте {{ config('settings.general.shop_name', config('app.name')) }}.

Пожалуйста, подтвердите email и задайте пароль.

<x-mail::button :url="$verifyUrl">
Подтвердить email
</x-mail::button>

<x-mail::button :url="$resetUrl">
Установить пароль
</x-mail::button>

С уважением,<br>
{{ config('settings.general.shop_name', config('app.name')) }}
</x-mail::message>
