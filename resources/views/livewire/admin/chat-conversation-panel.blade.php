{{--
    Переписка глазами оператора.

    Опрос идёт постоянно, пока разговор не закрыт: вкладка здесь одна,
    а запоздавший вопрос покупателя дороже лишнего запроса. На витрине
    правило обратное — там каждый тик стоит воркера FPM.

    `detailed` — режим разработчика, а не разворот одного сообщения.
    Это два разных читателя, а не два клика: менеджеру подробности не нужны
    никогда, разработчику нужны всегда, и раскрывать их по одному в каждом
    диалоге пришлось бы обоим. Состояние живёт в localStorage браузера —
    сервер о нём не знает, тик опроса на него не тратится.

    Запись — через `$watch`, а не `x-effect`: тот ждёт выражение, и блок
    `try` в нём падал с «Unexpected token 'try'» на каждой загрузке
    (у донора так и стоит; поймано браузером, не тестом).
--}}
<div class="space-y-4"
    x-data="{
        detailed: (() => {
            try { return localStorage.getItem('intertooler.chat.detailed') === '1' } catch (e) { return false }
        })(),
    }"
    x-init="$watch('detailed', (value) => {
        try { localStorage.setItem('intertooler.chat.detailed', value ? '1' : '0') } catch (e) {}
    })"
    @if (! $conversation->isClosed()) wire:poll.8s @endif>

    {{-- Сводка по разговору --}}
    <div class="grid grid-cols-2 gap-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 sm:grid-cols-4">
        @php
            $facts = [
                'Статус' => $conversation->statusLabel(),
                'Покупатель' => $conversation->user?->name ?? 'аноним',
                'Оператор' => $conversation->operator?->name ?? '—',
                'Начат' => $conversation->created_at?->format('d.m.Y H:i') ?? '—',
                'Сообщений' => (string) $conversation->messages_count,
                'Стоимость' => number_format((float) $conversation->cost_rub, 2, ',', ' ').' ₽',
                'Токенов' => number_format((int) $conversation->input_tokens + (int) $conversation->output_tokens, 0, ',', ' ')
                    .' (из кэша '.number_format((int) $conversation->cached_tokens, 0, ',', ' ').')',
                'Был на связи' => $conversation->last_seen_at?->diffForHumans() ?? '—',
            ];
        @endphp

        @foreach ($facts as $label => $value)
            <div>
                <div class="text-xs font-medium text-gray-500">{{ $label }}</div>
                <div class="text-sm text-gray-950">{{ $value }}</div>
            </div>
        @endforeach

        <label class="col-span-2 flex items-center gap-2 sm:col-span-4">
            <x-filament::input.checkbox x-model="detailed" />
            <span class="text-xs text-gray-500">
                Технические подробности: с каким запросом бот ходил в поиск,
                какие фрагменты нашёл и сколько токенов потратил
            </span>
        </label>

        @if ($conversation->callbackRequest)
            @php($lead = $conversation->callbackRequest)

            <div class="col-span-2 sm:col-span-4">
                <div class="text-xs font-medium text-gray-500">
                    Контакты из заявки №{{ $lead->getKey() }}
                </div>
                <div class="flex flex-wrap gap-x-4 text-sm">
                    @if (filled($lead->email))
                        <a href="mailto:{{ $lead->email }}"
                           class="font-medium text-primary-600 hover:underline">{{ $lead->email }}</a>
                    @endif

                    @if (filled($lead->phone))
                        <a href="tel:{{ $lead->phone }}"
                           class="font-medium text-primary-600 hover:underline">{{ $lead->phone }}</a>
                    @endif
                </div>
            </div>
        @endif
    </div>

    {{-- Лента --}}
    <div class="max-h-[60vh] space-y-3 overflow-y-auto rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5"
        x-data="{ stick() { this.$el.scrollTop = this.$el.scrollHeight } }"
        x-init="
            stick();
            new MutationObserver(() => stick()).observe($el, { childList: true, subtree: true });
        ">

        @forelse ($messages as $message)
            @php($role = $message->role)

            @if ($role === \App\Models\ChatMessage::ROLE_SYSTEM)
                {{-- Служебная пометка: кто взял, когда вернул, почему бот замолчал --}}
                <div class="flex justify-center" wire:key="msg-{{ $message->id }}">
                    <div class="rounded-full bg-gray-100 px-3 py-1 text-center text-xs text-gray-500">
                        {{ $message->body }}
                        <span class="opacity-60">· {{ $message->created_at?->format('d.m H:i') }}</span>
                    </div>
                </div>
            @else
                @php($fromVisitor = $role === \App\Models\ChatMessage::ROLE_VISITOR)
                {{--
                    Ключ обязателен: лента перерисовывается каждым тиком
                    опроса, и без него Livewire переиспользует чужой узел —
                    вместе с состоянием Alpine, которое на нём висит.
                --}}
                <div @class(['flex flex-col gap-1', 'items-end' => $fromVisitor])
                     wire:key="msg-{{ $message->id }}">
                    <div class="text-xs text-gray-500">
                        @switch($role)
                            @case(\App\Models\ChatMessage::ROLE_VISITOR) Покупатель @break
                            @case(\App\Models\ChatMessage::ROLE_OPERATOR) {{ $message->operator?->name ?? 'Оператор' }} @break
                            @default Бот
                        @endswitch
                        · {{ $message->created_at?->format('d.m H:i') }}
                    </div>

                    {{--
                        Разметка — у бота и оператора. Сообщения покупателя
                        кликабельными не делаем вовсе: их читает сотрудник,
                        а фишинг по оператору дороже неудобства.
                    --}}
                    <div @class([
                        'max-w-[80%] break-words rounded-2xl px-3 py-2 text-sm',
                        'whitespace-pre-line' => $fromVisitor,
                        'chat-md' => ! $fromVisitor,
                        'bg-primary-600 text-white' => $fromVisitor,
                        'bg-gray-100 text-gray-950' => $role === \App\Models\ChatMessage::ROLE_ASSISTANT,
                        'bg-success-50 text-gray-950 ring-1 ring-success-200' => $role === \App\Models\ChatMessage::ROLE_OPERATOR,
                    ])>@if ($fromVisitor){{ $message->body }}@else{!! $markdown->toHtml($message->body) !!}@endif</div>

                    {{--
                        «В базу знаний» — у собственного ответа менеджера.
                        Помеченный ответ уезжает в «Пробелы» вместе с вопросом
                        покупателя — там из него делается статья.
                    --}}
                    @if ($role === \App\Models\ChatMessage::ROLE_OPERATOR)
                        <div class="text-xs">
                            @if ($message->meta['to_kb'] ?? false)
                                <span class="inline-flex items-center gap-1 rounded bg-success-50 px-1.5 py-0.5
                                             text-success-700">
                                    ✓ отправлен в «Пробелы»
                                </span>
                            @else
                                <button type="button"
                                    wire:click="markForKb({{ $message->id }})"
                                    wire:target="markForKb({{ $message->id }})"
                                    wire:loading.attr="disabled"
                                    class="text-gray-500 underline decoration-dotted underline-offset-2
                                           hover:text-primary-600 disabled:opacity-50">
                                    В базу знаний
                                </button>
                            @endif
                        </div>
                    @endif

                    {{--
                        Телеметрия ответа бота. Она и есть ответ на вопрос
                        «почему он так сказал»: чем кончился ход, куда
                        сходил и что нашёл.
                    --}}
                    @if ($role === \App\Models\ChatMessage::ROLE_ASSISTANT)
                        @php($telemetry = \App\Services\Chat\ChatMessageTelemetry::for($message))

                        <div class="max-w-[80%] space-y-1 text-xs text-gray-500">
                            {{--
                                Бейджи-исключения. Появляются, только когда
                                есть что сказать: строка «всё прошло штатно»
                                под каждым ответом мешает увидеть тот
                                единственный, где не штатно.

                                Повтор из кэша — не исключение, а экономия,
                                поэтому бейдж нейтральный: покрась его
                                предупреждением, и за неделю привыкнут
                                не замечать предупреждения вообще.
                            --}}
                            @if ($message->stop_reason === 'cached')
                                <span class="mr-2 rounded bg-gray-100 px-1.5 py-0.5 text-gray-500
">повтор, ответ из кэша</span>
                            @elseif ($message->stop_reason && $message->stop_reason !== 'stop')
                                <span class="mr-2 rounded bg-warning-100 px-1.5 py-0.5 text-warning-700
">{{ $message->stop_reason }}</span>
                            @endif

                            @if ($message->kb_miss)
                                <span class="mr-2 rounded bg-danger-100 px-1.5 py-0.5 text-danger-700
">ничего не нашёл</span>
                            @endif

                            @if ($message->rating === \App\Models\ChatMessage::RATING_DOWN)
                                <span class="mr-2 rounded bg-danger-100 px-1.5 py-0.5 text-danger-700
">покупатель: не помогло</span>
                            @elseif ($message->rating === \App\Models\ChatMessage::RATING_UP)
                                <span class="mr-2 rounded bg-success-100 px-1.5 py-0.5 text-success-700
">покупатель: помогло</span>
                            @endif

                            {{--
                                Что бот делал — словами, а не именами
                                инструментов: «24 месяца» приехали из карточки
                                товара, а не из статьи, и без этой строки
                                взять их неоткуда.
                            --}}
                            @if ($telemetry->toolPhrases !== [])
                                <div>{{ implode(' · ', $telemetry->toolPhrases) }}</div>
                            @endif

                            {{-- Источники, свёрнутые до документов: страница
                                 из пяти кусков — одна строка, а не пять. --}}
                            @if ($telemetry->sources !== [])
                                <div>
                                    <span class="opacity-70">Ответ собран из:</span>
                                    @foreach ($telemetry->sources as $source)
                                        @if ($source['url'] !== null)
                                            <a href="{{ $source['url'] }}" target="_blank" rel="noopener"
                                               class="text-primary-600 hover:underline">{{ $source['title'] }}</a>
                                        @else
                                            <span>{{ $source['title'] }}</span>
                                        @endif
                                        @if (! $loop->last)
                                            <span class="opacity-50">·</span>
                                        @endif
                                    @endforeach
                                </div>
                            @endif

                            <div>
                                @if ($message->latency_ms > 0)
                                    <span>{{ number_format($message->latency_ms / 1000, 1, ',', ' ') }} с</span>
                                @endif

                                @if ($message->cost_rub > 0)
                                    <span class="opacity-50">·</span>
                                    <span>{{ number_format((float) $message->cost_rub, 3, ',', ' ') }} ₽</span>
                                @endif
                            </div>

                            {{--
                                Второй уровень — для разбора, а не для работы.
                                Рисуется всегда и прячется стилем: разворот
                                должен быть мгновенным, а не походом на сервер
                                за тем, что уже посчитано.
                            --}}
                            <div x-show="detailed" x-cloak
                                 class="mt-1 space-y-1 rounded-lg bg-gray-50 p-2 font-mono text-[11px]">
                                @foreach ($message->toolCallsDetailed() as $call)
                                    <div class="break-words">
                                        <span class="text-gray-950">{{ $call['name'] }}</span>
                                        @if ($call['arguments'] !== [])
                                            <span>{{ json_encode($call['arguments'], JSON_UNESCAPED_UNICODE) }}</span>
                                        @endif
                                        @if ($call['ms'] > 0)
                                            <span class="opacity-70">{{ $call['ms'] }} мс</span>
                                        @endif
                                    </div>
                                @endforeach

                                @foreach ($message->citations ?? [] as $citation)
                                    <div>
                                        <span class="opacity-70">{{ $citation['chunk_id'] ?? '?' }}</span>
                                        <span>{{ number_format((float) ($citation['score'] ?? 0), 3, ',', ' ') }}</span>
                                    </div>
                                @endforeach

                                <div class="opacity-70">
                                    {{ $message->stop_reason ?? '—' }} ·
                                    {{ $message->model ?? 'модель не записана' }} ·
                                    вход {{ number_format((int) $message->input_tokens, 0, ',', ' ') }}
                                    (из кэша {{ number_format((int) $message->cached_tokens, 0, ',', ' ') }}) ·
                                    выход {{ number_format((int) $message->output_tokens, 0, ',', ' ') }}
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
            @endif
        @empty
            <p class="text-sm text-gray-500">Переписка пуста.</p>
        @endforelse

        {{--
            Бот сейчас готовит ответ. Пометка нужна ровно здесь, в конце
            ленты, где оператор ищет последнее слово.
        --}}
        @if ($botPending)
            <div class="flex justify-center" wire:key="bot-pending">
                <div class="flex items-center gap-2 rounded-full bg-warning-100 px-3 py-1 text-xs text-warning-700">
                    <span class="h-1.5 w-1.5 shrink-0 animate-pulse rounded-full bg-warning-500"></span>
                    Бот готовит ответ — он придёт в ленту через несколько секунд
                </div>
            </div>
        @endif
    </div>

    {{-- Ответ оператора --}}
    @if ($canReply)
        <form wire:submit="send"
              class="space-y-2 rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5">
            {{--
                Поле ответа — редактор markdown с панелью из пяти кнопок.
                Набор кнопок и причины — в `replyForm()` компонента. Своего
                блока с ошибкой рядом нет: поле показывает её само.
            --}}
            {{ $this->replyForm }}

            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-xs text-gray-500">
                    @if ($botPending)
                        {{-- Прямое указание, что делать, а не просто предупреждение:
                             «Взять в работу» отменяет ответ бота, ждать — нет. --}}
                        Бот готовит ответ. Если хотите ответить сами —
                        сначала «Взять в работу»: тогда его ответ будет отброшен.
                    @else
                        {{ $conversation->isOperatorLed()
                            ? 'Разговор ведёте вы — бот в него не отвечает.'
                            : 'Как только вы ответите, разговор перейдёт к вам и бот в него отвечать перестанет.' }}
                    @endif
                </p>

                <div class="flex flex-wrap items-center gap-2">
                    {{--
                        Черновик от бота. Серой кнопкой и слева от «Отправить»:
                        это подсказка оператору, а не второй способ ответить.
                        Текст падает в поле — уйдёт он только тогда, когда
                        оператор нажмёт «Отправить».
                    --}}
                    <x-filament::button
                        color="gray"
                        icon="heroicon-o-sparkles"
                        wire:click="draftWithBot"
                        wire:target="draftWithBot"
                        wire:loading.attr="disabled"
                    >
                        <span wire:loading.remove wire:target="draftWithBot">Черновик от бота</span>
                        <span wire:loading wire:target="draftWithBot">Спрашиваю бота…</span>
                    </x-filament::button>

                    {{--
                        Та же форма контактов, что показывает бот своим
                        инструментом. Скрыта, когда контакты уже есть.
                    --}}
                    @if ($conversation->callback_request_id === null)
                        <x-filament::button
                            color="gray"
                            icon="heroicon-o-envelope"
                            wire:click="askForContacts"
                            wire:target="askForContacts"
                            wire:loading.attr="disabled"
                        >
                            Попросить контакты
                        </x-filament::button>
                    @endif

                    <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="send"
                                        icon="heroicon-o-paper-airplane">
                        Отправить
                    </x-filament::button>
                </div>
            </div>
        </form>
    @else
        <p class="rounded-xl bg-gray-50 p-4 text-sm text-gray-500">
            Диалог закрыт. Новый вопрос посетителя начнёт новый разговор.
        </p>
    @endif
</div>
