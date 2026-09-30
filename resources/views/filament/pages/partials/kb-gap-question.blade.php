{{--
    Одна формулировка вопроса. Показывается и внутри группы, и в списке
    несгруппированных — вид один и тот же, потому что и работа с ней одна:
    прочитать и открыть диалог, если непонятно.
--}}
<div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 border-b border-gray-50 px-4 py-3 last:border-0">
    <div class="min-w-0 flex-1 text-sm text-gray-950">
        {{ $question->preview(400) }}

        @if ($question->searchQuery !== null && $question->searchQuery !== $question->text)
            <div class="mt-1 text-xs text-gray-500">
                искал: {{ $question->searchQuery }}
            </div>
        @endif
    </div>

    <div class="flex shrink-0 flex-wrap items-center gap-2">
        @foreach ($question->signals as $signal)
            <x-filament::badge :color="$signals[$signal]['color'] ?? 'gray'" size="sm">
                {{ $signals[$signal]['label'] ?? $signal }}
            </x-filament::badge>
        @endforeach

        <span class="text-xs text-gray-500">
            {{ $question->askedAt->format('d.m.Y H:i') }}
        </span>

        <a href="{{ \App\Filament\Resources\ChatConversations\ChatConversationResource::getUrl('view', ['record' => $question->conversationId]) }}"
           class="text-xs font-medium text-primary-600 hover:underline">
            диалог №{{ $question->conversationId }}
        </a>

        {{--
            Ответить можно и на одну формулировку, а не только на группу:
            в списке «Без группировки» группы нет вовсе, а внутри группы
            бывает вопрос, стоящий отдельной статьи.
        --}}
        @if ($question->questionMessageId !== null)
            <a href="{{ \App\Filament\Resources\KbArticles\KbArticleResource::getUrl('create', ['from' => $question->questionMessageId]) }}"
               class="text-xs font-medium text-primary-600 hover:underline">
                в базу знаний
            </a>
        @endif
    </div>
</div>
