{{--
    Лаунчер чата — единственная строка, которую видит макет витрины.

    Чистый Blade и Alpine, без Livewire: до клика на странице не должно быть
    ни одного запроса к серверу, а виджет висит на каждой странице витрины.

    Панель — компонент пакета siteko/chatbot в рамке со своего же домена
    (`/chat/panel`). Рамку создаёт скрипт при первом открытии, и говорит
    он с ней протоколом `widget.js` пакета (alpine/chat-launcher.js).

    Свёрнутая рамка сервер не опрашивает: поллинг панели в скрытом документе
    не тикает. Оборотная сторона — ответ, пришедший в свёрнутый чат, никто
    бы не заметил. Поэтому лаунчер сторожит ответ сам, маленьким JSON
    вместо рендера Livewire, и только когда есть чего ждать.
--}}
@php
    /*
     * Состояние чата для лаунчера: сколько непрочитанного и надо ли
     * сторожить ответ. Единственный запрос к базе, который страница
     * витрины делает ради чата, и только у посетителя с кукой.
     */
    $chatState = app(\Siteko\Chatbot\Chat\ChatConversationService::class)->launcherState();

    /*
     * Панель разворачивается сама ТОЛЬКО по ссылке из письма «менеджер
     * ответил»: человек пришёл читать ответ, а не искать на витрине, куда
     * нажать. По непрочитанному — нет: чат, лезущий поверх страницы, которую
     * человек читает, — ровно то, за что виджеты поддержки и не любят.
     */
    $chatAutoOpen = request()->boolean('chat');

    // Имя и подсказка — из настроек бота (`chatbot_settings`, один запрос
    // на страницу, запоминается до конца запроса).
    $assistant = app(\Siteko\Chatbot\Ai\AssistantConfig::class);
    $botName = $assistant->botName();
    $botInvite = $assistant->invite();

    // Зелёная точка у аватара в подсказке означает ровно одно: в чате
    // кто-то отвечает. Выключенному боту она была бы обещанием, которого
    // экран не выполняет.
    $botOnline = $assistant->enabled();

    /*
     * Вид кнопки — из «Вида чата» в админке. Тоже конфиг, а не база; картинка
     * проверяется на диске одним stat, без запроса. Робот грузится, только
     * когда он на кнопке: иначе динамический import артворка и движка
     * в chatLauncher не случается вовсе.
     */
    $appearance = app(\App\Shop\Chat\ChatAppearance::class);
    $launcher = $appearance->launcher();
    $picture = $appearance->pictureUrl($launcher);
    $face = $appearance->face($launcher);
    $avatarUrl = $appearance->avatarUrl();
    $robot = $launcher->image === \App\Shop\Chat\ChatLauncher::IMAGE_ROBOT;
    $corner = ($launcher->side === 'left' ? 'left' : 'right').': '.$launcher->x.'px; bottom: '.$launcher->y.'px';
@endphp

<div x-data="chatLauncher(@js([
        'open' => $chatAutoOpen,
        'unread' => $chatState['unread'],
        'pollSeconds' => $chatState['poll'],
        'url' => route('chat.unread'),
        'frameUrl' => route('chat.panel'),
        'stopsAfter' => \Siteko\Chatbot\Chat\ChatPollingCadence::LAUNCHER_STOPS_AFTER_SECONDS,
        'invite' => $botInvite,
        'robot' => $robot,
        'robotSize' => $launcher->floatingRobot() ? null : $launcher->heightPx() - 8,
        'face' => $face,
        'pill' => $launcher->style === \App\Shop\Chat\ChatLauncher::STYLE_PILL,
        'side' => $launcher->side,
        'x' => $launcher->x,
        'y' => $launcher->y,
        'height' => $launcher->heightPx(),
        'drag' => $launcher->drag,
     ]))"
     {{--
         Порядок важен: Esc сначала гасит панель и только потом подсказку.
         Иначе одно нажатие убирало бы оба слоя разом.
     --}}
     @keydown.escape.window="open ? (open = false) : dismissInvite()"
     class="print:hidden">

    {{--
        Кнопка. Вид — из «Вида чата» в админке: классы обёртки собирает
        `ChatLauncher::classes()`, правила — `.chat-launcher` в app.css.
        Умолчание — прежний робот без диска: он парит сам по себе, отделяет
        его от страницы свечение (`.chat-mascot`).

        `fixed` висит на обёртке, а не на кнопке: от неё позиционируются
        бейдж и подсказка. Угол и отступы — инлайном: их двигает и
        перетаскивание (`place()` в chat-launcher.js).

        Пилюля на телефоне — кружок, и решает это скрипт (`cl-narrow`), а не
        медиазапрос: предпросмотр в админке уже 520 px и свернул бы её всегда.
        Чтобы на телефоне пилюля не мелькнула до скрипта, её обёртка ждёт
        Alpine под x-cloak.

        Наведение слушает ОБЁРТКА, а не кнопка: подсказка лежит внутри неё,
        и переход курсора с кнопки на текст не должен считаться уходом.
    --}}
    <div x-show="!open"
         x-ref="corner"
         @if ($launcher->style === \App\Shop\Chat\ChatLauncher::STYLE_PILL) x-cloak @endif
         x-transition.opacity.duration.150ms
         @mouseenter="hoverInvite(true)"
         @mouseleave="hoverInvite(false)"
         class="{{ $launcher->classes($picture !== null || $robot) }}"
         style="{{ $corner }}">

        <button type="button"
            x-ref="button"
            @click="press()"
            x-bind:aria-label="unread > 0 ? 'Вам ответили в чате' : 'Задать вопрос — {{ $botName }}'"
            aria-label="Задать вопрос — {{ $botName }}"
            title="Задать вопрос"
            class="cl-button">
            <x-support.chat-button-face :launcher="$launcher" :picture="$picture" :name="$botName" />
        </button>

        {{-- Бейдж рисует Alpine, а не сервер: ответ может прийти на уже
             открытой странице, и тогда менять его некому, кроме него. --}}
        <span x-show="unread > 0" x-cloak style="display: none"
              x-bind:aria-label="'Непрочитанных сообщений: ' + unread"
              class="pointer-events-none absolute -right-1 -top-1 flex h-5 min-w-5 items-center
                     justify-center rounded-full bg-brand-red px-1.5 text-xs font-semibold
                     text-white ring-2 ring-white"
              x-text="Math.min(unread, 9)"></span>

        {{--
            Подсказка-приглашение над кнопкой, хвостиком вниз. Молчаливая кнопка
            требует догадаться, что она вообще про разговор; строка с именем
            этого не требует. Место — `.cl-bubble` в app.css: у робота прежнее,
            у кружка хвостик в его середину, у левого угла зеркально, у поднятой
            кнопки — вниз. Ширина считается от экрана: на телефоне подсказка
            тянется почти во всю ширину.
        --}}
        <div x-show="invite" x-cloak style="display: none"
             x-transition:enter="transition duration-300 ease-out motion-reduce:transition-none"
             x-transition:enter-start="opacity-0 translate-y-2 scale-95"
             x-transition:enter-end="opacity-100 translate-y-0 scale-100"
             x-transition:leave="transition duration-150 ease-in motion-reduce:transition-none"
             x-transition:leave-start="opacity-100 scale-100"
             x-transition:leave-end="opacity-0 scale-95"
             class="cl-bubble w-[min(19rem,calc(100vw-3.5rem))]">

            @include('components.support.chat-invite-card', ['clickable' => true])

            {{-- Крестик — своя кнопка со своим стопом: «не надо», а не «открой чат». --}}
            <button type="button" @click.stop="dismissInvite()"
                aria-label="Закрыть подсказку"
                class="absolute right-2 top-2 z-20 flex h-6 w-6 cursor-pointer items-center
                       justify-center rounded-full text-zinc-400 transition hover:bg-zinc-100
                       hover:text-zinc-700 focus:outline-none focus-visible:ring-2
                       focus-visible:ring-brand-green">
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2.5" stroke-linecap="round" aria-hidden="true">
                    <path d="M6 6l12 12M18 6L6 18" />
                </svg>
            </button>
        </div>
    </div>

    {{--
        Панель: место под рамку в DOM с первого показа, а рамку скрипт
        создаёт при первом открытии — до клика чат не стоит ни запроса.
        Открыли — непрочитанного больше нет: панель при показе отмечает
        переписку прочитанной, и ждать её ответа, чтобы убрать бейдж, незачем.

        Разворачивается из угла, где стоит кнопка (в том числе перетащенная:
        `side` ведёт скрипт), а на телефоне выезжает снизу листом. Угол — только
        привязкой: до Alpine панель всё равно скрыта. Закрытие быстрее открытия:
        человек уже принял решение и ждёт страницу под панелью.
    --}}
    <div x-show="open"
         x-effect="if (open) unread = 0"
         x-cloak
         style="display: none"
         x-transition:enter="transition duration-200 ease-out motion-reduce:transition-none"
         x-transition:enter-start="opacity-0 translate-y-6 sm:translate-y-2 sm:scale-95"
         x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
         x-transition:leave="transition duration-150 ease-in motion-reduce:transition-none"
         x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
         x-transition:leave-end="opacity-0 translate-y-6 sm:translate-y-2 sm:scale-95"
         x-bind:class="side === 'left' ? 'sm:left-4 sm:origin-bottom-left' : 'sm:right-4 sm:origin-bottom-right'"
         class="fixed inset-x-0 bottom-0 z-50 origin-bottom sm:inset-x-auto sm:bottom-4">
        <div x-ref="frame"
             class="h-[85vh] w-full overflow-hidden rounded-t-2xl bg-white shadow-2xl ring-1 ring-zinc-200
                    sm:h-[min(600px,calc(100vh-2rem))] sm:w-[380px] sm:rounded-2xl"></div>
    </div>
</div>
