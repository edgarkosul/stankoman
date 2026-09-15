{{--
    Панель чата.

    Ответ бота и реплика оператора рендерятся markdown-парсером (ChatMarkdown)
    по белому списку тегов. Сообщение покупателя остаётся ТЕКСТОМ: разметка
    в нём не нужна никому, а фишинг ссылкой — нужен.

    Цвета — сайта: шапка и реплики покупателя в `brand-green`, как кнопки
    витрины. Донорские утилиты `brand-50…950` здесь не существуют и молча
    отрисовались бы ничем.
--}}
<div x-data="{ confirmClear: false }"
    class="chat-panel relative flex h-[85vh] w-full flex-col overflow-hidden rounded-t-2xl bg-white shadow-2xl
            ring-1 ring-zinc-200 sm:h-[600px] sm:w-[380px] sm:rounded-2xl"
    @if ($pollInterval) wire:poll.visible.{{ $pollInterval }}="poll" @endif>

    {{-- Шапка --}}
    <div class="flex items-center gap-3 bg-brand-green px-4 py-3 text-white">
        @php
            // По ту сторону человек или бот. Одно условие на всё: имя,
            // лицо и цвет точки обязаны говорить одно и то же.
            $ledByHuman = (bool) $conversation?->isOperatorLed();

            /*
             * Цвет точки — состояние чата, а не украшение:
             *   серый    — не отвечает никто, виджет показывает форму контактов;
             *   янтарный — разговор у менеджера, но смена кончилась: ответ
             *              будет, только не сейчас;
             *   зелёный  — отвечают прямо сейчас (бот отвечает круглосуточно).
             */
            $statusColor = match (true) {
                ! $enabled => 'bg-zinc-400',
                $ledByHuman && ! $operatorsOnline => 'bg-amber-400',
                default => 'bg-emerald-300',
            };
        @endphp

        {{--
            Лицо собеседника.

            `wire:ignore` обязателен: маскот вставляет сюда SVG скриптом,
            а Livewire на каждом опросе сверяет дерево с серверным и всё
            лишнее вычищает — робот исчезал бы через несколько секунд
            после открытия чата, без единой ошибки в консоли.

            Робота показываем, только когда отвечает бот: лицо робота над
            словом «Менеджер» было бы подлогом.
        --}}
        <span class="relative block h-10 w-10 shrink-0">
            @if ($ledByHuman)
                <span class="flex h-10 w-10 items-center justify-center rounded-full bg-white/15">
                    <svg class="h-5 w-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                        <circle cx="12" cy="7" r="4" />
                    </svg>
                </span>
            @else
                <span wire:ignore x-data="chatAvatar(40)" class="chat-mascot-slot--sm relative block h-10 w-10"></span>
            @endif

            <span aria-hidden="true"
                  class="absolute -bottom-0.5 -right-0.5 block h-3 w-3 rounded-full {{ $statusColor }} ring-2 ring-brand-green"></span>
        </span>

        <div class="min-w-0 flex-1">
            {{-- Имя то же, что на кнопке чата и в подсказке у неё. --}}
            <p class="truncate text-sm font-semibold leading-tight">
                {{ $ledByHuman ? $managerTitle : $botName }}
            </p>
            {{--
                Подзаголовок честно говорит, кто по ту сторону и чего ждать.
                Обещание «сейчас ответим» в нерабочее время дороже молчания:
                посетитель подождёт и уйдёт, вместо того чтобы оставить почту.
            --}}
            <p class="text-xs text-white/75">
                @if ($ledByHuman)
                    С вами общается сотрудник магазина
                @elseif ($operatorsOnline)
                    Отвечаем по заказу, оплате, доставке и гарантии
                @else
                    Отвечаю на вопросы круглосуточно. Менеджеры — {{ $workingHours }}
                @endif
            </p>
        </div>
        <button type="button" @click="$dispatch('chat-close')" aria-label="Свернуть чат"
            class="cursor-pointer rounded-full p-1 text-white/80 transition hover:bg-white/10 hover:text-white">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" aria-hidden="true">
                <path d="M6 6l12 12M18 6L6 18" />
            </svg>
        </button>
    </div>

    {{-- Лента --}}
    <div class="chat-feed flex flex-1 flex-col space-y-3 overflow-y-auto px-4 py-4"
        x-data="{
            stick() { this.$el.scrollTop = this.$el.scrollHeight },
        }"
        x-init="
            stick();
            new MutationObserver(() => stick()).observe($el, { childList: true, subtree: true });
        ">

        @if ($messages->isEmpty())
            {{--
                Приветствие правится в админке, поэтому текст приходит переменной,
                а абзацы получаются из пустых строк: админ пишет в обычное поле,
                а не размечает.
            --}}
            <div class="rounded-xl bg-zinc-50 px-3 py-3 text-sm text-zinc-600 ring-1 ring-zinc-200">
                @foreach (preg_split('/\n\s*\n/u', trim($greeting)) ?: [] as $paragraph)
                    <p @class(['mt-2' => ! $loop->first])>{{ $paragraph }}</p>
                @endforeach
            </div>
        @endif

        @foreach ($messages as $message)
            @php($visitorNote = $message->visitorNote())

            {{--
                Смена отвечающего. Не реплика и не пузырь: это пометка о том,
                что произошло с разговором, — тихо и по центру.
            --}}
            @if ($visitorNote !== null)
                <div class="flex justify-center" wire:key="msg-{{ $message->id }}">
                    <div class="rounded-full bg-zinc-100 px-3 py-1 text-xs text-zinc-500 ring-1 ring-zinc-200">{{ $visitorNote }}</div>
                </div>

                @continue
            @endif

            @php($fromVisitor = $message->role === \App\Models\ChatMessage::ROLE_VISITOR)
            @php($fromOperator = $message->role === \App\Models\ChatMessage::ROLE_OPERATOR)
            <div @class(['flex flex-col', 'items-end' => $fromVisitor]) wire:key="msg-{{ $message->id }}">
                {{--
                    Подпись только над ответом человека: покупатель должен
                    понимать, что отвечает уже не бот, — иначе смена тона
                    читается как сбой.
                --}}
                @if ($fromOperator)
                    <span class="mb-1 text-[11px] font-medium text-brand-green">Менеджер</span>
                @endif
                <div @class([
                    'max-w-[85%] break-words rounded-2xl px-3 py-2 text-sm',
                    'whitespace-pre-line' => $fromVisitor,
                    'chat-md' => ! $fromVisitor,
                    'bg-brand-green text-white' => $fromVisitor,
                    'bg-zinc-100 text-zinc-900 ring-1 ring-zinc-200' => ! $fromVisitor && ! $fromOperator,
                    'bg-brand-green/10 text-zinc-900 ring-1 ring-brand-green/30' => $fromOperator,
                ])>@if ($fromVisitor){{ $message->body }}@else{!! $markdown->toHtml($message->body) !!}@endif</div>

                {{--
                    Оценка ответа бота. Единственный прямой сигнал качества:
                    уверенно неверный ответ не поднимает ни kb_miss, ни
                    эскалацию, и узнать о нём больше неоткуда.

                    Кнопки без подписи «оцените ответ»: просьба в каждом пузыре
                    читалась бы как навязчивость. Нажатая оценка держится на двух
                    признаках сразу — цвет насыщеннее и подложка: одного цвета
                    на фоновом рисунке панели мало.
                --}}
                @if ($message->isRateable())
                    <div class="mt-1 flex items-center gap-1 text-brand-green/60">
                        <button type="button" wire:click="rate({{ $message->id }}, 1)"
                            aria-label="Ответ помог"
                            @class([
                                'cursor-pointer rounded p-1 transition hover:bg-brand-green/10 hover:text-brand-green',
                                'bg-brand-green/10 text-brand-green' => $message->rating === \App\Models\ChatMessage::RATING_UP,
                            ])>
                            <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                 stroke-width="1.6" stroke-linejoin="round" aria-hidden="true">
                                <path d="M7 20V10l4.5-7c1 0 1.7.8 1.6 1.8L12.5 9h5.3c1.3 0 2.2 1.2 1.9 2.4l-1.6 6.4c-.2 1-1.1 1.7-2.1 1.7H7Z" />
                                <path d="M7 10H4.5v10H7" />
                            </svg>
                        </button>
                        <button type="button" wire:click="rate({{ $message->id }}, -1)"
                            aria-label="Ответ не помог"
                            @class([
                                'cursor-pointer rounded p-1 transition hover:bg-brand-green/10 hover:text-brand-green',
                                'bg-brand-green/10 text-brand-green' => $message->rating === \App\Models\ChatMessage::RATING_DOWN,
                            ])>
                            <svg class="h-[18px] w-[18px] rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                 stroke-width="1.6" stroke-linejoin="round" aria-hidden="true">
                                <path d="M7 20V10l4.5-7c1 0 1.7.8 1.6 1.8L12.5 9h5.3c1.3 0 2.2 1.2 1.9 2.4l-1.6 6.4c-.2 1-1.1 1.7-2.1 1.7H7Z" />
                                <path d="M7 10H4.5v10H7" />
                            </svg>
                        </button>
                        @if ($message->rating === \App\Models\ChatMessage::RATING_DOWN)
                            {{--
                                Ответ на 👎 обязателен: без него нажатие выглядит
                                как «ушло в никуда». Обещаем ровно то, что делаем:
                                ответ попадёт в разбор. Никакого «менеджер
                                перезвонит» — за этим в чате есть своя кнопка.
                            --}}
                            <span class="text-[11px] text-zinc-500">Спасибо — посмотрим, что улучшить</span>
                        @endif
                    </div>
                @endif
            </div>
        @endforeach

        {{-- Индикатор ожидания --}}
        @if ($awaiting)
            <div class="flex" wire:key="chat-awaiting"
                x-data="{
                    waited: {{ $waitedSeconds }},
                    hintAfter: {{ \App\Services\Chat\ChatPollingCadence::THINKING_HINT_AFTER_SECONDS }},
                }"
                x-init="
                    const tick = setInterval(() => {
                        // Строка уходит из ленты, как только приходит ответ,
                        // а таймер сам по себе не умирает — снимаем его руками.
                        if (! $el.isConnected) return clearInterval(tick);
                        waited++;
                    }, 1000);
                ">
                <x-support.chat-status>
                    {{--
                        Смена подписи на двадцатой секунде обязательна: разброс
                        латентности сидит на стороне шлюза, и без неё посетитель
                        решает, что чат завис.
                    --}}
                    <span x-show="waited < hintAfter">Печатает…</span>
                    <span x-show="waited >= hintAfter" x-cloak>Секунду, уточняю…</span>
                </x-support.chat-status>
            </div>
        @endif

        {{--
            Что делает менеджер, пока экран молчит. «В работе» — вопрос у человека,
            но он ещё не начал отвечать; «печатает» — ответ уже пишется. Разница
            для покупателя в том, уходить ли со страницы.
        --}}
        @if ($staffActivity !== null)
            @php($staffTyping = $staffActivity === \App\Models\ChatConversation::STAFF_TYPING)

            <div wire:key="chat-staff-activity">
                <x-support.chat-status :typing="$staffTyping">
                    {{ $staffTyping ? 'Менеджер печатает ответ…' : 'В работе у менеджера…' }}
                </x-support.chat-status>
            </div>
        @endif

        {{--
            Нужен живой разговор: бот позвал менеджера, сам предложил оставить
            контакты или покупатель написал контакт в сообщении. Контакты
            вводятся в форму магазина и уходят в заявку — в переписку с моделью
            они не попадают вообще.
        --}}
        @if ($callbackHint !== null)
            <div class="rounded-xl border border-brand-green/30 bg-white px-3 py-3 text-sm text-zinc-800" wire:key="chat-callback-card">
                <p class="mb-2">{{ $callbackHint }}</p>
                @livewire($lead['component'], $lead['parameters'], key('chat-callback'))
            </div>
        @endif
    </div>

    {{--
        Ввод. `bg-white` не дублирует фон панели, а закрывает его: под строкой
        ввода рисунок читался бы как грязь вокруг поля, а не как фон ленты.
    --}}
    <div class="bg-white px-3 py-3">
        @if (! $enabled)
            <p class="text-sm text-zinc-600">
                Консультант сейчас недоступен. Оставьте почту — менеджер ответит письмом,
                а если удобнее голосом, там же можно оставить телефон.
            </p>
            {{--
                Заявка уже оформлена — второй раз контакты не просим. Карточка
                с формой уже стоит в ленте — вторую такую же под ней не показываем.
            --}}
            @if ($conversation?->callback_request_id !== null)
                <p class="mt-2 text-sm text-zinc-600">Заявка принята — менеджер ответит письмом.</p>
            @elseif ($callbackHint === null)
                <div class="mt-2">
                    @livewire($lead['component'], $lead['parameters'], key('chat-callback-disabled'))
                </div>
            @endif
        @else
            <form x-data="{
                    busy: false,
                    send() {
                        if (this.busy) return;
                        this.busy = true;
                        this.$wire.send().finally(() => { this.busy = false });
                    },
                }"
                @submit.prevent="send()" class="flex items-end gap-2">
                {{--
                    Поле растёт под ответ и упирается в потолок в четыре строки —
                    дальше скроллится само. Ручную тянучку (`resize-y`) снимаем:
                    перетащенную высоту всё равно затрёт первый же пересчёт.
                --}}
                <textarea wire:model="draft" rows="1"
                    x-data="autogrowTextarea(4)" @input="fit()" x-effect="$wire.draft, fit()"
                    @keydown.enter="if (! $event.shiftKey) { $event.preventDefault(); send() }"
                    maxlength="{{ (int) config('ai_support.chat.max_message_length') }}"
                    placeholder="Ваш вопрос…"
                    aria-label="Ваш вопрос"
                    class="min-h-[42px] flex-1 resize-none rounded-xl border border-zinc-300 px-3 py-2 text-sm
                           outline-none transition focus:border-brand-green focus:ring-2 focus:ring-brand-green/30"></textarea>
                {{--
                    `cursor-pointer` руками: preflight Tailwind кнопкам палец не ставит.
                    `enabled:` у подрастания — пока бот думает, кнопка выключена,
                    и расти в ответ на курсор ей нельзя: это обещание нажатия,
                    которого не будет. `motion-safe:` — чтобы правило со скейлом
                    не появлялось у тех, кто просил меньше движения.
                --}}
                <button type="submit"
                    @disabled($awaiting)
                    x-bind:disabled="busy || @js($awaiting)"
                    aria-label="Отправить"
                    class="flex h-[42px] w-[42px] shrink-0 cursor-pointer items-center justify-center rounded-xl
                           bg-brand-green text-white transition hover:bg-[#1c7731]
                           motion-safe:enabled:hover:scale-110
                           disabled:cursor-not-allowed disabled:opacity-40">
                    {{--
                        Самолётик сдвинут на два юнита: чернила фигуры сходятся к носу,
                        и центр тяжести лежит правее и выше середины рамки — в квадратной
                        кнопке значок читался как сбитый. `overflow-visible` — чтобы хвост
                        после сдвига не срезало.
                    --}}
                    <svg class="h-5 w-5 overflow-visible" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path transform="translate(-2 2)" d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z" />
                    </svg>
                </button>
            </form>

            @error('draft')
                <p class="mt-1 text-sm text-brand-red">{{ $message }}</p>
            @enderror

            {{--
                Страница пролежала открытой дольше жизни сессии, и первое действие
                не прошло. Сторож в бандле уже всё починил молча, но нажатие пропало —
                сказать об этом надо, иначе посетитель решит, что чат сломался.
                Показываем только когда в поле есть текст.
            --}}
            <p x-data="{ show: false }"
               x-on:session-renewed.window="
                   show = ($el.parentElement?.querySelector('textarea')?.value ?? '').trim() !== '';
                   if (show) setTimeout(() => show = false, 12000);
               "
               x-show="show"
               x-cloak
               class="mt-1 text-sm text-amber-700">
                Страница была открыта слишком долго — соединение восстановлено.
                Нажмите «Отправить» ещё раз.
            </p>

            {{-- Выход к человеку — только когда человек действительно на месте. --}}
            @if ($canCallOperator)
                <button type="button" wire:click="callOperator" wire:loading.attr="disabled"
                    class="mt-2 cursor-pointer text-[11px] text-zinc-500 underline underline-offset-2 transition
                           hover:text-brand-green disabled:cursor-not-allowed disabled:opacity-50">
                    Позвать менеджера
                </button>
            @endif

            <p class="mt-2 text-[11px] leading-snug text-zinc-400">
                {{ $conversation?->isOperatorLed() ? 'Отвечает менеджер.' : 'Отвечает бот.' }}
                Отправляя вопрос, вы соглашаетесь с
                <a href="{{ route('page.show', 'terms') }}" target="_blank" rel="noopener"
                   class="underline underline-offset-2 hover:text-zinc-600">условиями</a>
                и
                <a href="{{ route('page.show', 'privacy') }}" target="_blank" rel="noopener"
                   class="underline underline-offset-2 hover:text-zinc-600">политикой конфиденциальности</a>.
            </p>
        @endif

        <div class="mt-2 flex items-center justify-between gap-2">
            {{--
                «Очистить переписку» — снаружи ветки «бот включён/выключен»:
                право стереть написанное не должно зависеть от того, работает ли
                сейчас консультант. Подтверждение обязательно: действие необратимое.
            --}}
            @if ($conversation)
                <button type="button" @click="confirmClear = true"
                    wire:loading.attr="disabled"
                    class="cursor-pointer text-[11px] text-zinc-400 underline underline-offset-2 transition
                           hover:text-zinc-600 disabled:cursor-not-allowed disabled:opacity-50">
                    Очистить переписку
                </button>
            @else
                <span></span>
            @endif

            {{-- Подпись разработчика: не часть разговора и не мешает ни одному состоянию. --}}
            <a href="https://www.siteko.net/development" target="_blank" rel="noopener"
               class="text-[10px] text-zinc-300 transition hover:text-zinc-500">Сделано в Siteko</a>
        </div>
    </div>

    {{--
        Подтверждение удаления — своё, а не `wire:confirm`: браузерная модалка
        в чате выглядит инородно — чужой заголовок «localhost:8103 says», чужие
        кнопки, чужой язык интерфейса.

        Затемнение накрывает только панель, а не всю страницу: чат живёт
        в углу, и гасить за ним витрину ради одного вопроса незачем.
    --}}
    <div x-show="confirmClear" x-cloak x-transition.opacity
         @keydown.escape.window="confirmClear = false"
         class="absolute inset-0 z-20 flex items-center justify-center bg-zinc-900/40 p-4">
        {{-- Клик мимо карточки закрывает: это отказ, а не подтверждение. --}}
        <div @click.outside="confirmClear = false"
             class="w-full max-w-[300px] rounded-2xl bg-white p-4 shadow-xl ring-1 ring-zinc-200">
            <p class="text-sm font-semibold text-zinc-900">Удалить переписку?</p>
            <p class="mt-1 text-[13px] leading-snug text-zinc-500">
                Она удалится насовсем — ни у вас, ни у магазина её больше не будет.
            </p>
            <div class="mt-3 flex gap-2">
                <button type="button" @click="confirmClear = false"
                    class="flex-1 cursor-pointer rounded-xl border border-zinc-300 px-3 py-2 text-sm text-zinc-700
                           transition hover:bg-zinc-50">
                    Отмена
                </button>
                <button type="button" @click="confirmClear = false; $wire.clearHistory()"
                    class="flex-1 cursor-pointer rounded-xl bg-brand-red px-3 py-2 text-sm font-medium text-white
                           transition hover:opacity-90">
                    Удалить
                </button>
            </div>
        </div>
    </div>
</div>
