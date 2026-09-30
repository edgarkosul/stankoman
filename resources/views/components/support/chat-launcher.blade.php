{{--
    Лаунчер чата — единственная строка, которую видит макет витрины.

    Чистый Blade и Alpine, без Livewire: до клика на странице не должно быть
    ни одного запроса к серверу, а виджет висит на каждой странице витрины.

    Панель лежит рядом, в контейнере с инлайновым display:none. Это не
    косметика: `lazy` вешает на плейсхолдер IntersectionObserver, скрытый
    элемент ни с чем не пересекается, и компонент не грузится, пока
    посетитель не откроет чат. Инлайновый стиль, а не только x-cloak, —
    потому что наблюдатель может сработать раньше, чем поднимется Alpine.

    Тем же скрытием выключается и поллинг панели: `wire:poll.visible`
    не тикает, пока её не видно. Оборотная сторона — ответ, пришедший
    в свёрнутый чат, никто бы не заметил. Поэтому лаунчер сторожит ответ
    сам, маленьким JSON вместо рендера Livewire, и только когда есть чего ждать.
--}}
@php
    $pageLocator = app(\App\Services\Chat\Contracts\PageContextSource::class)->locate(request());

    /*
     * Состояние чата для лаунчера: сколько непрочитанного и надо ли
     * сторожить ответ. Единственный запрос к базе, который страница
     * витрины делает ради чата, и только у посетителя с кукой.
     */
    $chatState = app(\App\Services\Chat\ChatConversationService::class)->launcherState(request());

    /*
     * Панель разворачивается сама ТОЛЬКО по ссылке из письма «менеджер
     * ответил»: человек пришёл читать ответ, а не искать на витрине, куда
     * нажать. По непрочитанному — нет: чат, лезущий поверх страницы, которую
     * человек читает, — ровно то, за что виджеты поддержки и не любят.
     */
    $chatAutoOpen = request()->boolean('chat');

    // Имя и подсказка — чтение конфига, а не базы: настройки разложены
    // в config на старте приложения.
    $assistant = app(\App\Services\Ai\AssistantConfig::class);
    $botName = $assistant->botName();
    $botInvite = $assistant->invite();

    // Зелёная точка у аватара в подсказке означает ровно одно: в чате
    // кто-то отвечает. Выключенному боту она была бы обещанием, которого
    // экран не выполняет.
    $botOnline = $assistant->enabled();
@endphp

<div x-data="chatLauncher(@js([
        'open' => $chatAutoOpen,
        'unread' => $chatState['unread'],
        'pollSeconds' => $chatState['poll'],
        'url' => route('chat.unread'),
        'stopsAfter' => \App\Services\Chat\ChatPollingCadence::LAUNCHER_STOPS_AFTER_SECONDS,
        'invite' => $botInvite,
     ]))"
     @chat-close.window="open = false"
     {{--
         Порядок важен: Esc сначала гасит панель и только потом подсказку.
         Иначе одно нажатие убирало бы оба слоя разом.
     --}}
     @keydown.escape.window="open ? (open = false) : dismissInvite()"
     class="print:hidden">

    {{--
        Кнопка: робот парит сам по себе, без диска и обводки — отделяет его
        от страницы свечение (см. `.chat-mascot` в app.css). `fixed` висит
        на обёртке, а не на кнопке: от неё позиционируются бейдж и подсказка.

        Наведение слушает ОБЁРТКА, а не кнопка: подсказка лежит внутри неё,
        и переход курсора с робота на текст не должен считаться уходом.
    --}}
    <div x-show="!open"
         x-transition.opacity.duration.150ms
         @mouseenter="hoverInvite(true)"
         @mouseleave="hoverInvite(false)"
         class="fixed bottom-4 right-4 z-50">

        <button type="button"
            @click="show()"
            x-bind:aria-label="unread > 0 ? 'Вам ответили в чате' : 'Задать вопрос — {{ $botName }}'"
            aria-label="Задать вопрос — {{ $botName }}"
            title="Задать вопрос"
            class="block h-18 w-18 cursor-pointer rounded-full focus:outline-none
                   focus-visible:ring-2 focus-visible:ring-brand-green focus-visible:ring-offset-2">
            {{--
                Слот фиксированного размера: что бы ни приехало, коробка та же,
                и подмена облачка на робота не двигает макет. Без `overflow-hidden`:
                робот парит и должен выходить за её края.
            --}}
            <span x-ref="mascot" class="relative block h-full w-full">
                {{--
                    Плейсхолдер до приезда робота. Он один остаётся без подложки,
                    поэтому в цвете сайта и с белой обводкой — иначе на тёмном
                    подвале его не видно.
                --}}
                <svg data-mascot-placeholder
                     class="absolute inset-0 m-auto h-10 w-10 rounded-full bg-brand-green p-2
                            text-white shadow-lg ring-2 ring-white/90 transition-opacity duration-200"
                     viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z" />
                </svg>
            </span>
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
            Подсказка-приглашение над роботом, хвостиком вниз. Молчаливая кнопка
            требует догадаться, что она вообще про разговор; строка с именем
            этого не требует. Ширина считается от экрана: на телефоне подсказка
            тянется почти во всю ширину.
        --}}
        <div x-show="invite" x-cloak style="display: none"
             x-transition:enter="transition duration-300 ease-out motion-reduce:transition-none"
             x-transition:enter-start="opacity-0 translate-y-2 scale-95"
             x-transition:enter-end="opacity-100 translate-y-0 scale-100"
             x-transition:leave="transition duration-150 ease-in motion-reduce:transition-none"
             x-transition:leave-start="opacity-100 scale-100"
             x-transition:leave-end="opacity-0 scale-95"
             class="absolute bottom-[4.75rem] right-6 w-[min(19rem,calc(100vw-3.5rem))] origin-bottom-right">

            <button type="button" @click="show()"
                class="relative flex w-full cursor-pointer items-start gap-3 rounded-2xl bg-white
                       py-3 pl-3 pr-9 text-left shadow-xl ring-1 ring-zinc-200 transition
                       hover:ring-zinc-300 focus:outline-none focus-visible:ring-2
                       focus-visible:ring-brand-green">
                {{-- Тот же робот, но мелкий и спокойный: рядом с текстом второй
                     пляшущий объект только мешает. --}}
                <span class="relative block h-10 w-10 shrink-0">
                    <span x-ref="bubbleMascot" class="chat-mascot-slot--sm relative block h-full w-full"></span>
                    @if ($botOnline)
                        <span aria-hidden="true"
                              class="absolute -bottom-0.5 -right-0.5 block h-3 w-3 rounded-full
                                     bg-emerald-500 ring-2 ring-white"></span>
                    @endif
                </span>

                <span class="min-w-0 flex-1">
                    <span class="block text-sm font-semibold leading-tight text-zinc-900">{{ $botName }}</span>
                    <span class="mt-1 block text-sm leading-snug text-zinc-600"
                          x-text="inviteText">{{ $botInvite }}</span>
                </span>
            </button>

            {{--
                Хвостик рисуется ПОВЕРХ пузыря, и у него закрашены только две
                внешние грани: иначе по его основанию проходит линия рамки пузыря,
                и ромбик читается отдельной фигурой, а не отростком.
            --}}
            <span aria-hidden="true"
                  class="absolute -bottom-1.5 right-5 z-20 h-3 w-3 rotate-45 border-b border-r
                         border-zinc-200 bg-white"></span>

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
        Панель: в DOM с первого показа, но скрыта, поэтому не грузится.
        Открыли — непрочитанного больше нет: панель при показе отмечает
        переписку прочитанной, и ждать её ответа, чтобы убрать бейдж, незачем.

        Разворачивается из угла, где стоит кнопка, а на телефоне выезжает
        снизу листом. Закрытие быстрее открытия: человек уже принял решение
        и ждёт страницу под панелью.
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
         class="fixed inset-x-0 bottom-0 z-50 origin-bottom sm:inset-x-auto sm:bottom-4 sm:right-4
                sm:origin-bottom-right">
        <livewire:support.chat-panel :page="$pageLocator" lazy />
    </div>
</div>
