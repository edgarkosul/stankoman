{{--
    «Уведомления в MAX»: список чатов менеджеров и кнопка подключения.

    Пока ждём чат после «Подключить», страница опрашивает сервер раз
    в пять секунд: человек жмёт «Запустить» в мессенджере, и строка
    чата появляется здесь без перезагрузки.
--}}
@php
    $topics = [
        \App\Models\MessengerChannel::TOPIC_ORDERS => ['notify_orders', 'Заказы'],
        \App\Models\MessengerChannel::TOPIC_REQUESTS => ['notify_requests', 'Заявки'],
        \App\Models\MessengerChannel::TOPIC_CHAT => ['notify_chat', 'Вопросы из чата'],
    ];
@endphp

<x-filament-panels::page>
    <div @if ($this->isAwaiting()) wire:poll.5s="checkConnected" @endif>
        <x-filament::section>
            <x-slot name="heading">Чаты менеджеров</x-slot>
            <x-slot name="description">
                Сюда приходят новые заказы, заявки на обратный звонок и вопросы из чата на сайте,
                когда покупатель зовёт менеджера.
                Вопросы из чата вне рабочего времени придут в начале смены, заказы и заявки — сразу.
                Письма менеджерам уходят как раньше, MAX их не заменяет.
            </x-slot>

            @if ($this->channels->isEmpty())
                <p class="text-sm text-gray-500">Чатов пока нет.</p>
            @else
                <ul class="divide-y divide-gray-100">
                    @foreach ($this->channels as $channel)
                        <li class="flex flex-wrap items-center gap-x-6 gap-y-2 py-3" wire:key="messenger-{{ $channel->id }}">
                            <div class="min-w-0 flex-1">
                                <div class="font-medium text-gray-950">{{ $channel->label }}</div>

                                {{-- Что с чатом — одной строкой: работает, спотыкается или выключен и почему. --}}
                                @if (! $channel->enabled)
                                    <p class="text-sm text-danger-600">Выключен: {{ $channel->last_error ?? 'без причины' }}. «Проверить» включит снова, если всё починилось.</p>
                                @elseif ($channel->last_error)
                                    <p class="text-sm text-warning-600">Последняя попытка не удалась: {{ $channel->last_error }}</p>
                                @elseif ($channel->last_sent_at)
                                    <p class="text-sm text-gray-500">Последнее уведомление {{ $channel->last_sent_at->gt(now()->subMinute()) ? 'только что' : $channel->last_sent_at->diffForHumans() }}</p>
                                @else
                                    <p class="text-sm text-gray-500">Подключён, уведомлений ещё не было</p>
                                @endif
                            </div>

                            @foreach ($topics as $topic => [$flag, $label])
                                <label class="flex items-center gap-2 text-sm text-gray-700">
                                    <x-filament::input.checkbox :checked="$channel->{$flag}" wire:click="toggle({{ $channel->id }}, '{{ $topic }}')" />
                                    {{ $label }}
                                </label>
                            @endforeach

                            <div class="flex items-center gap-4">
                                {{ ($this->testAction)(['channel' => $channel->id]) }}
                                {{ ($this->removeAction)(['channel' => $channel->id]) }}
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif

            <div class="mt-4 flex flex-wrap items-center gap-3">
                {{ $this->connectAction }}
            </div>

            @if ($this->isAwaiting())
                <p class="mt-3 text-sm text-gray-500">
                    Ждём, когда вы нажмёте «Запустить» в чате, — строка появится здесь сама.
                </p>
            @endif

            @if (! $this->botReady())
                <p class="mt-3 text-xs text-gray-500">
                    Бот MAX не настроен: в окружении нужны MAX_BOT_TOKEN и MAX_BOT_LINK. Пока их нет, кнопка не работает.
                </p>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
