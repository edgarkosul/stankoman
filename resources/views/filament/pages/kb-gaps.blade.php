{{--
    Очередь работ базы знаний.

    Читается сверху вниз как список задач: чем больше группа, тем выше
    она стоит. Одиночные вопросы не прячутся — единственное 👎 может
    стоить десяти «ничего не нашёл», — но и не занимают верх экрана.
--}}
@php
    $report = $this->report;
    $signals = $this->signalMeta();
@endphp

<x-filament-panels::page>
    <div class="space-y-4">

        {{-- Окно наблюдения и сигнал --}}
        <div class="flex flex-wrap items-end gap-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5">
            <div>
                <label class="mb-1 block text-xs font-medium text-gray-500" for="gaps-period">
                    Период
                </label>
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="period" id="gaps-period">
                        @foreach ($this->periodOptions() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </div>

            <div>
                <label class="mb-1 block text-xs font-medium text-gray-500" for="gaps-signal">
                    Сигнал
                </label>
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="signal" id="gaps-signal">
                        @foreach ($this->signalOptions() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </div>

            <div class="ms-auto flex flex-wrap items-center gap-2 text-sm text-gray-500"
                 wire:loading.remove wire:target="period,signal">
                <span>
                    <span class="font-semibold text-gray-950">{{ $report->questionsCount() }}</span>
                    вопросов,
                    <span class="font-semibold text-gray-950">{{ count($report->clusters) }}</span>
                    групп
                </span>

                @foreach ($report->signalCounts() as $signal => $number)
                    <x-filament::badge :color="$signals[$signal]['color'] ?? 'gray'" size="sm">
                        {{ $signals[$signal]['label'] ?? $signal }}: {{ $number }}
                    </x-filament::badge>
                @endforeach
            </div>

            <div class="ms-auto text-sm text-gray-500"
                 wire:loading wire:target="period,signal">
                Считаю…
            </div>
        </div>

        @if ($report->truncated)
            {{--
                Текст идёт слотом `description`, а не содержимым: компонент
                Filament рисует только heading/description, а обычный слот
                молча выбрасывает — плашка получается пустой, без единой
                ошибки в консоли.
            --}}
            <x-filament::callout icon="heroicon-o-exclamation-triangle" color="warning">
                <x-slot name="description">
                    Показаны самые свежие вопросы — их набралось больше, чем помещается на экран.
                    Сузьте период или разберите то, что видно: остальное никуда не денется.
                </x-slot>
            </x-filament::callout>
        @endif

        @if ($report->isEmpty())
            <div class="rounded-xl bg-white p-8 text-center shadow-sm ring-1 ring-gray-950/5">
                <div class="text-sm font-medium text-gray-950">
                    За этот период бот не спотыкался
                </div>
                <p class="mx-auto mt-2 max-w-xl text-sm text-gray-500">
                    Сюда попадают вопросы, на которых бот получил 👎, позвал человека
                    или не нашёл ничего в базе знаний, и ответы менеджеров, отправленные
                    из «Диалогов» кнопкой «В базу знаний». Пусто — либо всё написано,
                    либо вопросов пока не задавали.
                </p>
            </div>
        @endif

        {{-- Группы: одна строка очереди работ = один пробел в базе --}}
        @foreach ($report->clusters as $cluster)
            @php($counts = $cluster->signalCounts())

            <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5"
                 x-data="{ open: false }">
                <div class="flex flex-wrap items-start gap-4 p-4 sm:flex-nowrap">
                    <div class="shrink-0 text-center">
                        <div class="text-2xl font-semibold leading-none text-gray-950">
                            {{ $cluster->count() }}
                        </div>
                        <div class="mt-1 text-xs text-gray-500">
                            {{ $this->timesLabel($cluster->count()) }}
                        </div>
                    </div>

                    {{--
                        На узком экране столбец кнопок занимал половину ширины,
                        и ответ менеджера сжимался в колонку по слову в строке.
                        Поэтому до `sm` содержимое и кнопки идут друг под другом.
                    --}}
                    <div class="w-full min-w-0 sm:flex-1">
                        <div class="text-sm font-medium text-gray-950">
                            {{ $cluster->title() }}
                        </div>

                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            {{--
                                Порог в три повтора: не фильтр, а пометка.
                                Прятать редкие вопросы нельзя — единственный
                                вопрос с 👎 стоит прочитать глазами.
                            --}}
                            @if ($cluster->worthArticle())
                                <x-filament::badge color="primary" size="sm">пора написать статью</x-filament::badge>
                            @endif

                            @foreach ($counts as $signal => $number)
                                <x-filament::badge :color="$signals[$signal]['color'] ?? 'gray'" size="sm">
                                    {{ $signals[$signal]['label'] ?? $signal }}
                                    @if ($number > 1)
                                        · {{ $number }}
                                    @endif
                                </x-filament::badge>
                            @endforeach

                            <span class="text-xs text-gray-500">
                                последний раз {{ $cluster->lastAskedAt()->diffForHumans() }}
                            </span>
                        </div>

                        {{--
                            Ответы менеджеров, помеченные «в базу знаний».
                            Показываем прямо здесь, а не прячем в диалог:
                            это и есть материал будущей статьи, и решение
                            «писать или нет» принимается по нему, а не по
                            одному заголовку группы.
                        --}}
                        @php($answers = array_slice($cluster->answers(), 0, 3))

                        @if ($answers !== [])
                            <div class="mt-3 space-y-1.5 border-l-2 border-success-200 pl-3">
                                <p class="text-xs font-medium text-gray-500">Менеджеры отвечали так:</p>

                                @foreach ($answers as $answer)
                                    <p class="text-xs text-gray-600">{{ \Illuminate\Support\Str::limit($answer, 220) }}</p>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    {{--
                        Кнопка «Ответить в базу знаний» стоит у группы, а не
                        только у отдельного вопроса: группа и есть задача,
                        и статья пишется на неё целиком. Формулировки уезжают
                        в форму вместе с заголовком — писать ответ, видя все
                        вопросы разом, и есть работа того, кто ведёт базу.
                    --}}
                    <div class="flex w-full shrink-0 flex-wrap items-center gap-2 sm:w-auto sm:flex-col sm:items-end">
                        @php($questionIds = $cluster->questionMessageIds())

                        @if ($questionIds !== [])
                            @php($idsParam = implode(',', $questionIds))

                            <x-filament::button
                                tag="a"
                                size="xs"
                                icon="heroicon-m-pencil-square"
                                :href="\App\Filament\Resources\KbArticles\KbArticleResource::getUrl('create', ['from' => $idsParam])"
                            >
                                Ответить в базу знаний
                            </x-filament::button>

                            {{--
                                Черновик от бота. Кнопка вторая и серая:
                                писать статью — работа человека, а бот здесь
                                подручный. Ждать приходится секунды, поэтому
                                на время вызова она сама говорит, что делает.
                            --}}
                            <x-filament::button
                                size="xs"
                                color="gray"
                                icon="heroicon-m-sparkles"
                                wire:click="draftArticle('{{ $idsParam }}')"
                                wire:target="draftArticle('{{ $idsParam }}')"
                                wire:loading.attr="disabled"
                            >
                                <span wire:loading.remove wire:target="draftArticle('{{ $idsParam }}')">
                                    Черновик от бота
                                </span>
                                <span wire:loading wire:target="draftArticle('{{ $idsParam }}')">
                                    Собираю…
                                </span>
                            </x-filament::button>
                        @endif

                        <button type="button"
                                class="text-sm font-medium text-primary-600 hover:underline"
                                x-on:click="open = ! open">
                            <span x-show="! open">Показать вопросы</span>
                            <span x-show="open" x-cloak>Свернуть</span>
                        </button>
                    </div>
                </div>

                {{--
                    Формулировки разворачиваются на клиенте: сервер их уже
                    отдал, и тратить на разворот круг до Livewire незачем.
                --}}
                <div class="border-t border-gray-100" x-show="open" x-cloak>
                    @foreach ($cluster->questions as $question)
                        @include('filament.pages.partials.kb-gap-question', [
                            'question' => $question,
                            'signals' => $signals,
                        ])
                    @endforeach
                </div>
            </div>
        @endforeach

        {{--
            Вопросы без вектора. Вектор вопроса считает джоба ответа, и пустым
            он остаётся, только когда шлюз эмбеддингов в тот момент не ответил.
            Прятать такие нельзя: 👎 от этого не становится менее важным.
        --}}
        @if ($report->ungrouped !== [])
            <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5">
                <div class="border-b border-gray-100 p-4">
                    <div class="text-sm font-medium text-gray-950">
                        Без группировки — {{ count($report->ungrouped) }}
                    </div>
                    <p class="mt-1 text-xs text-gray-500">
                        Смысл этих вопросов посчитать не удалось — сравнивать их с другими
                        не с чем. Читать по одному.
                    </p>
                </div>

                @foreach ($report->ungrouped as $question)
                    @include('filament.pages.partials.kb-gap-question', [
                        'question' => $question,
                        'signals' => $signals,
                    ])
                @endforeach
            </div>
        @endif
    </div>
</x-filament-panels::page>
