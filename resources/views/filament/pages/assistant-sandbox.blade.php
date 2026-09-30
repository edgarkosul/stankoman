{{--
    Песочница: тот же бот, что и на витрине, но с полным разбором каждого
    ответа. Подробности здесь развёрнуты по умолчанию — в ленте диалога они
    спрятаны за переключателем, потому что там их читают редко, а сюда
    приходят именно за ними.
--}}
<x-filament-panels::page>
    <div class="space-y-4">

        {{--
            Текст идёт слотом `description`: обычное содержимое компонент
            Filament выбрасывает молча, и плашка получается пустой.
        --}}
        <x-filament::callout icon="heroicon-o-beaker" color="info">
            <x-slot name="description">
                Вопрос уходит настоящему боту: тот же промпт, та же база знаний, те же инструменты
                и те же деньги. В «Диалоги» это не попадает, заявок не создаёт и разговоров не помечает.
            </x-slot>
        </x-filament::callout>

        {{--
            Выключенный бот песочницу не блокирует: проверять его перед
            включением иначе было бы нечем, а включают именно в этом порядке.
        --}}
        @unless (app(\App\Services\Ai\AssistantConfig::class)->enabled())
            <x-filament::callout icon="heroicon-o-pause-circle" color="warning">
                <x-slot name="description">
                    Для покупателей бот сейчас выключен — в чате они видят форму контактов.
                    Здесь он отвечает как обычно: так его и проверяют перед тем, как включить.
                </x-slot>
            </x-filament::callout>
        @endunless

        {{-- Разговор --}}
        @if ($turns !== [])
            <div class="space-y-3">
                @foreach ($turns as $turn)
                    @if ($turn['role'] === 'visitor')
                        <div class="flex justify-end">
                            <div class="max-w-[80%] whitespace-pre-wrap rounded-xl bg-primary-600 px-3 py-2 text-sm text-white">
                                {{ $turn['text'] }}
                            </div>
                        </div>
                    @else
                        @php($message = $this->messageFor($turn))
                        @php($telemetry = \App\Services\Chat\ChatMessageTelemetry::for($message))

                        <div class="space-y-2">
                            {{--
                                Ответ показывается ровно так, как его увидит
                                покупатель, — с разметкой. Песочница воспроизводит
                                поведение, а не улучшает его, и вид ответа тут
                                такая же его часть, как текст.
                            --}}
                            <div class="chat-md max-w-[80%] rounded-xl bg-gray-100 px-3 py-2 text-sm
                                        text-gray-950">
                                @if ($turn['failed'])
                                    <span class="text-danger-600">
                                        Ответа нет: {{ $turn['stop_reason'] }}. Покупатель увидел бы заглушку
                                        и предложение оставить контакты.
                                    </span>
                                @else
                                    {!! app(\App\Services\Chat\ChatMarkdown::class)->toHtml($turn['text']) !!}
                                @endif
                            </div>

                            {{--
                                Что покупатель увидел бы под этим ответом.

                                Бейджи ниже — телеметрия, они говорят «инструмент
                                вызван». А бот в тексте пишет «нажмите кнопку
                                ниже» — и на витрине кнопка действительно
                                появляется, а в песочнице её нет, и фраза выглядит
                                враньём. Пунктирная заглушка закрывает ровно этот
                                разрыв.
                            --}}
                            @if ($turn['callback_requested'] || $turn['escalated'])
                                <div class="max-w-[80%] space-y-1 rounded-xl border border-dashed border-gray-300
                                            px-3 py-2 text-xs text-gray-500">
                                    @if ($turn['callback_requested'])
                                        <div>Здесь у покупателя появилась бы кнопка «Оставить контакты менеджеру».</div>
                                    @endif

                                    @if ($turn['escalated'])
                                        <div>Вопрос ушёл бы менеджеру: диалог пометился бы «ждёт менеджера»
                                            и загорелся бы бейджем у «Диалогов».</div>
                                    @endif

                                    <div class="opacity-70">Из песочницы ничего этого не происходит.</div>
                                </div>
                            @endif

                            <div class="max-w-[80%] space-y-1 text-xs text-gray-500">
                                {{-- Бейджи-исключения: только когда есть что сказать --}}
                                <div class="flex flex-wrap items-center gap-2">
                                    @if ($turn['stop_reason'] && $turn['stop_reason'] !== 'stop')
                                        <x-filament::badge color="warning" size="sm">{{ $turn['stop_reason'] }}</x-filament::badge>
                                    @endif

                                    @if ($turn['kb_miss'])
                                        <x-filament::badge color="danger" size="sm">ничего не нашёл в базе</x-filament::badge>
                                    @endif

                                    @if ($turn['escalated'])
                                        <x-filament::badge color="warning" size="sm">передал менеджеру</x-filament::badge>
                                    @endif

                                    @if ($turn['callback_requested'])
                                        <x-filament::badge color="gray" size="sm">попросил контакты</x-filament::badge>
                                    @endif
                                </div>

                                @if ($telemetry->toolPhrases !== [])
                                    <div>{{ implode(' · ', $telemetry->toolPhrases) }}</div>
                                @endif

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
                                            @unless ($loop->last)
                                                <span class="opacity-50">·</span>
                                            @endunless
                                        @endforeach
                                    </div>
                                @endif

                                {{--
                                    Подробности. В ленте диалога они за переключателем,
                                    здесь — всегда: разбор «почему он так ответил»
                                    и есть содержание этой страницы.
                                --}}
                                <div class="space-y-1 rounded-lg bg-gray-50 p-2 font-mono text-[11px]">
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

                                    @foreach ($turn['citations'] as $citation)
                                        <div>
                                            <span class="opacity-70">{{ $citation['chunk_id'] ?? '?' }}</span>
                                            <span>{{ number_format((float) ($citation['score'] ?? 0), 3, ',', ' ') }}</span>
                                            <span class="opacity-70">{{ $citation['title'] ?? '' }}</span>
                                        </div>
                                    @endforeach

                                    @if ($turn['tool_calls'] === [] && $turn['citations'] === [])
                                        <div class="opacity-70">Инструменты не вызывались — ответ собран без поиска.</div>
                                    @endif

                                    <div class="opacity-70">
                                        {{ number_format($turn['latency_ms'] / 1000, 1, ',', ' ') }} с ·
                                        {{ number_format((float) $turn['cost_rub'], 4, ',', ' ') }} ₽ ·
                                        вход {{ number_format($turn['input_tokens'], 0, ',', ' ') }}
                                        (из кэша {{ number_format($turn['cached_tokens'], 0, ',', ' ') }}) ·
                                        выход {{ number_format($turn['output_tokens'], 0, ',', ' ') }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>

            <div class="text-xs text-gray-500">
                Разбор обошёлся в {{ number_format($this->totalCost(), 3, ',', ' ') }} ₽.
            </div>
        @endif

        {{-- Вопрос --}}
        <form wire:submit="ask"
              class="space-y-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5">
            <x-filament::input.wrapper :valid="! $errors->has('question')">
                <textarea
                    wire:model="question"
                    rows="3"
                    class="fi-input block w-full border-none bg-transparent px-3 py-2 text-base outline-none
                           placeholder:text-gray-400 focus:ring-0 sm:text-sm"
                    placeholder="Спросите так, как спросил бы покупатель: «можно оплатить по счёту на ООО»"
                ></textarea>
            </x-filament::input.wrapper>

            @error('question')
                <p class="text-sm text-danger-600">{{ $message }}</p>
            @enderror

            <div class="flex flex-wrap items-center gap-3">
                <x-filament::button type="submit" icon="heroicon-m-paper-airplane" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="ask">Спросить</span>
                    <span wire:loading wire:target="ask">Спрашиваю…</span>
                </x-filament::button>

                {{--
                    Откуда спрашивает покупатель. Чат всегда передаёт боту
                    страницу, и на карточке товара ответ получается другим:
                    товар уже известен, спрашивать артикул незачем.
                --}}
                <div class="flex flex-wrap items-center gap-2">
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="pageType">
                            @foreach ($this->pageOptions() as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>

                    @if ($pageType === 'product')
                        <x-filament::input.wrapper :valid="! $errors->has('productRef')" class="min-w-0 sm:min-w-72">
                            <x-filament::input
                                type="text"
                                wire:model="productRef"
                                placeholder="Адрес карточки с сайта или её слаг"
                            />
                        </x-filament::input.wrapper>
                    @endif
                </div>

                @error('productRef')
                    <p class="w-full text-sm text-danger-600">{{ $message }}</p>
                @enderror

                {{--
                    Скидку видит только вошедший покупатель, гостю бот обязан
                    назвать базовую цену и не назвать льготную. Проверять надо оба.
                --}}
                <label class="flex items-center gap-2 text-sm text-gray-600">
                    <input type="checkbox" wire:model="seesDiscounts"
                           class="fi-checkbox-input rounded border-gray-300 text-primary-600">
                    Как вошедший покупатель (видит цены со скидкой)
                </label>

                <span class="text-xs text-gray-500" wire:loading wire:target="ask">
                    Обычно 10–20 секунд, иногда до минуты.
                </span>
            </div>
        </form>
    </div>
</x-filament-panels::page>
