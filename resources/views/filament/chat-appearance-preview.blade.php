{{--
    Документ предпросмотра «Вида чата»: угол витрины с кнопкой и подсказкой.

    Стили — сам app.css витрины, разметка — те же компоненты, что рисует
    лаунчер (`x-support.chat-button-face`, `chat-invite-card`). Скриптов
    в iframe нет (`sandbox`), поэтому робот здесь статичная картинка, а
    классы, которые на сайте ставит скрипт, ставятся руками: у образца
    «на телефоне» — `cl-narrow`.

    Обёртка кнопки — `position: fixed`, как на сайте: в iframe это угол его
    окна, и отступы из формы видны как есть.
--}}
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <link rel="stylesheet" href="{{ \Illuminate\Support\Facades\Vite::asset('resources/css/app.css') }}">
    <style>
        /*
         * Витрина держит место под полосу прокрутки с обоих краёв
         * (`scrollbar-gutter: stable both-edges`); в окне предпросмотра это
         * 15 px пустоты слева, и подсказка упиралась бы в край.
         */
        html { scrollbar-gutter: auto !important; overflow: hidden; }
        html, body { height: 100%; margin: 0; }
        body {
            background: #fff;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
        }
        /* Набросок страницы магазина — чтобы кнопку было видно на фоне, а не в пустоте. */
        .page { padding: 16px; }
        .page i { display: block; height: 10px; margin-bottom: 10px; border-radius: 5px; background: #e4e4e7; }
        .page i:nth-child(1) { width: 55%; height: 16px; background: #d4d4d8; }
        .page i:nth-child(3n) { width: 80%; }
        .page i:nth-child(4n) { width: 65%; }
        .cards { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; margin-top: 16px; }
        .cards b { display: block; height: 110px; border-radius: 10px; background: #f4f4f5; }
        .sample { position: absolute; top: 16px; left: 16px; display: flex; align-items: center; gap: 12px;
                  padding: 10px 12px; border-radius: 12px; background: rgb(255 255 255 / 0.92);
                  box-shadow: 0 1px 3px rgb(0 0 0 / 0.12); font-size: 12px; color: #52525b; }
        .sample .chat-launcher { position: relative; inset: auto; }
    </style>
</head>
@php
    $classes = $launcher->classes($picture !== null || $launcher->image === \App\Shop\Chat\ChatLauncher::IMAGE_ROBOT);
@endphp
<body>
    <div class="page" aria-hidden="true">
        <i></i><i></i><i></i><i></i><i></i>
        <div class="cards"><b></b><b></b><b></b><b></b></div>
    </div>

    @if ($launcher->style === \App\Shop\Chat\ChatLauncher::STYLE_PILL)
        <div class="sample">
            <div class="{{ $classes }} cl-narrow">
                <span class="cl-button">
                    <x-support.chat-button-face :launcher="$launcher" :picture="$picture" :name="$botName" :preview="true" />
                </span>
            </div>
            На телефоне — кружком
        </div>
    @endif

    <div class="{{ $classes }}"
         style="{{ $launcher->side === 'left' ? 'left' : 'right' }}: {{ $launcher->x }}px; bottom: {{ $launcher->y }}px">
        <span class="cl-button">
            <x-support.chat-button-face :launcher="$launcher" :picture="$picture" :name="$botName" :preview="true" />
        </span>

        <div class="cl-bubble w-[min(19rem,calc(100vw-3.5rem))]">
            @include('components.support.chat-invite-card', ['clickable' => false])

            {{-- Крестик — как на сайте, только картинкой. --}}
            <span class="absolute right-2 top-2 z-20 flex h-6 w-6 items-center justify-center text-zinc-400">
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2.5" stroke-linecap="round" aria-hidden="true">
                    <path d="M6 6l12 12M18 6L6 18" />
                </svg>
            </span>
        </div>
    </div>
</body>
</html>
