{{--
    Рамка панели чата: компонент пакета siteko/chatbot на своём документе.
    Лаунчер витрины создаёт её при первом открытии (alpine/chat-launcher.js).

    Скрипты пакета (`widget-scripts`) — до Livewire: они вешаются на
    `alpine:init`. Тема одна, светлая: витрина тёмной не бывает.
--}}
<!doctype html>
<html lang="ru" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Консультант</title>
    @include('chatbot::widget-styles')
    @include('chatbot::widget-palette', ['theme' => $theme])
    @include('chatbot::widget-scripts')
    @vite(['resources/css/chat-frame.css', 'resources/js/chat-frame.js'])
</head>
<body>
    <livewire:chatbot.chat-panel
        :site-id="$siteId"
        :origins="$origins"
        :visitor="$visitor"
        :staff="$staff"
        :user-id="$userId" />
</body>
</html>
