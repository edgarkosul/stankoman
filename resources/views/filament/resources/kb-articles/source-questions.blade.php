{{--
    Что привело в эту форму: вопросы покупателей и, если нажали «Черновик
    от бота», предупреждение о том, чем этот текст является.

    Формулировки нужны перед глазами всё время, пока пишут: статья должна
    отвечать людям, а не теме вообще.
--}}
@php($draft = $draft ?? [])

<div class="space-y-4">
    @if ($draft !== [])
        {{--
            Предупреждение стоит ПЕРВЫМ и жёлтым, а не серым примечанием внизу.
            Опубликованную статью бот повторяет покупателю как факт о магазине,
            то есть цена невнимательности здесь — не опечатка, а неверный ответ,
            сказанный уверенно. Человек между ботом и базой и есть весь смысл
            этой кнопки.
        --}}
        <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="warning">
            <x-slot name="heading">Это черновик, собранный ботом — проверьте факты</x-slot>

            <x-slot name="description">
                Бот писал только по тому, что уже есть на сайте, в статьях и в ответах менеджеров.
                Сроки, цены, условия и адреса, которых там нет, он придумывать не должен — но
                обязанность проверить остаётся за вами.
            </x-slot>

            @if (($draft['missing_facts'] ?? []) !== [])
                <div class="text-sm">
                    <div class="font-medium text-gray-950">
                        Чего боту не хватило — допишите сами:
                    </div>
                    <ul class="mt-2 list-disc space-y-1 ps-5 text-gray-700">
                        @foreach ($draft['missing_facts'] as $fact)
                            <li>{{ $fact }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-500">
                @if (($draft['sources'] ?? []) !== [])
                    <span>
                        Собран по материалам:
                        {{ collect($draft['sources'])->pluck('title')->unique()->take(4)->implode(' · ') }}
                    </span>
                @else
                    <span>В базе ничего близкого не нашлось — текст собран по вопросам и ответам менеджеров.</span>
                @endif

                @if (($draft['cost_rub'] ?? 0) > 0)
                    <span>{{ number_format((float) $draft['cost_rub'], 3, ',', ' ') }} ₽</span>
                @endif
            </div>
        </x-filament::section>
    @endif

    @if ($questions !== [])
        <x-filament::section icon="heroicon-o-chat-bubble-left-right" icon-color="info">
            <x-slot name="heading">
                @if (count($questions) === 1)
                    Вопрос покупателя, ради которого пишется статья
                @else
                    Вопросы покупателей, ради которых пишется статья — {{ count($questions) }}
                @endif
            </x-slot>

            <x-slot name="description">
                Заголовок подставлен из первой формулировки, правьте свободно. В тексте отвечайте
                так, как ответили бы покупателю: бот перескажет своими словами.
            </x-slot>

            <ul class="space-y-2 text-sm">
                @foreach ($questions as $question)
                    <li class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                        <span class="min-w-0 flex-1 text-gray-950">
                            «{{ \Illuminate\Support\Str::limit($question['text'], 400) }}»
                        </span>

                        <a href="{{ \App\Filament\Resources\ChatConversations\ChatConversationResource::getUrl('view', ['record' => $question['conversation']]) }}"
                           class="shrink-0 text-xs font-medium text-primary-600 hover:underline">
                            диалог №{{ $question['conversation'] }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif
</div>
