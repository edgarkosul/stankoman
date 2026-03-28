<x-mail::message>
# Добро пожаловать, {{ $user->name ?? 'клиент' }}!

Мы создали для вас личный кабинет на сайте {{ config('settings.general.shop_name', config('app.name')) }}.

Чтобы завершить настройку кабинета:

1. Установите пароль.
2. Войдите в личный кабинет.
3. Подтвердите email по отдельному письму, которое мы отправим после установки пароля.

<x-mail::button :url="$resetUrl">
Установить пароль
</x-mail::button>

С уважением,<br>
{{ config('settings.general.shop_name', config('app.name')) }}
</x-mail::message>
