<?php

namespace App\Filament\Resources\KbArticles\Pages;

use App\Filament\Pages\KbGaps;
use App\Filament\Resources\KbArticles\KbArticleResource;
use App\Models\ChatMessage;
use App\Services\Kb\KbArticleTitle;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Cache;

/**
 * Создание статьи — обычная форма Filament плюс мост из диалогов.
 *
 * Мост — это ответ на вопрос «а что писать»: сюда приходят не с пустой
 * головой, а от конкретного вопроса покупателя, на который бот не ответил.
 * Без моста круг рвётся ровно здесь, на шаге «иди в раздел базы знаний
 * и создай там что-нибудь»: формулировку надо держать в памяти, а диалог —
 * искать заново.
 *
 * Ссылка на форму несёт `?from=` со списком id реплик покупателя. Именно
 * реплик, а не ответов бота: id вопроса — то единственное, что нужно обеим
 * сторонам моста, и «Пробелам» с их группами, и карточке диалога с её
 * выбором одного вопроса из переписки.
 */
class CreateKbArticle extends CreateRecord
{
    protected static string $resource = KbArticleResource::class;

    /**
     * Сколько формулировок из группы переносим в форму.
     *
     * Больше десятка вопросов об одном и том же ничего не добавляют:
     * их читают, чтобы понять, о чём люди спрашивают, а не пересчитывают.
     */
    private const MAX_SOURCES = 12;

    /**
     * Вопросы, по которым пишется статья.
     *
     * Свойство, а не чтение строки запроса при каждом рендере: со второго
     * запроса Livewire строка запроса недоступна, и подсказка исчезла бы
     * при первой же перерисовке формы.
     *
     * @var list<array{text: string, conversation: int}>
     */
    public array $sourceQuestions = [];

    /**
     * Черновик, собранный ботом на экране «Пробелы», — или пустой массив,
     * когда статью пишут сами.
     *
     * @var array{title?: string, paragraphs?: list<string>, missing_facts?: list<string>, sources?: list<array{title: string, url: ?string, score: float}>, cost_rub?: float, model?: string}
     */
    public array $draft = [];

    /**
     * Подсказка «по каким вопросам пишем» — над формой, а не внутри неё:
     * форма общая с редактированием, а вопросы бывают только у новой статьи.
     */
    public function content(Schema $schema): Schema
    {
        if ($this->sourceQuestions === [] && $this->draft === []) {
            return parent::content($schema);
        }

        return $schema->components([
            View::make('filament.resources.kb-articles.source-questions')
                ->viewData([
                    'questions' => $this->sourceQuestions,
                    'draft' => $this->draft,
                ]),
            $this->getFormContentComponent(),
        ]);
    }

    protected function fillForm(): void
    {
        parent::fillForm();

        $this->sourceQuestions = $this->loadSourceQuestions();
        $this->draft = $this->loadDraft();

        $state = [];

        $title = $this->draft['title'] ?? '';

        if (! is_string($title) || trim($title) === '') {
            $title = $this->sourceQuestions === []
                ? ''
                : KbArticleTitle::fromQuestion($this->sourceQuestions[0]['text']);
        }

        if ($title !== '') {
            $state['title'] = $title;
        }

        if (($this->draft['paragraphs'] ?? []) !== []) {
            $state['content'] = $this->draftContent();
        }

        if ($state !== []) {
            /*
             * Заполняем поверх уже собранных значений по умолчанию, а не
             * через `fillPartially`: тот раскладывает состояние в точечные
             * ключи (`content.content.0.type`), и вложенный документ
             * редактора до формы не доезжает — поле молча остаётся пустым,
             * хотя заголовок при этом встаёт на место.
             */
            $this->form->fill([...(array) $this->form->getRawState(), ...$state]);
        }
    }

    /**
     * Черновик из кэша. Ключ живёт в адресе, а не в сессии: обновление
     * страницы не должно ни терять статью, ни собирать её заново за деньги.
     *
     * @return array<string, mixed>
     */
    private function loadDraft(): array
    {
        $key = (string) request()->query('draft', '');

        if ($key === '' || preg_match('/^[A-Za-z0-9]{8,64}$/', $key) !== 1) {
            return [];
        }

        $draft = Cache::get(KbGaps::draftCacheKey($key));

        return is_array($draft) ? $draft : [];
    }

    /**
     * Абзацы черновика в формате редактора.
     *
     * @return array{type: string, content: list<array<string, mixed>>}
     */
    private function draftContent(): array
    {
        $paragraphs = [];

        foreach ((array) ($this->draft['paragraphs'] ?? []) as $text) {
            if (! is_string($text) || trim($text) === '') {
                continue;
            }

            $paragraphs[] = [
                'type' => 'paragraph',
                'content' => [['type' => 'text', 'text' => trim($text)]],
            ];
        }

        return ['type' => 'doc', 'content' => $paragraphs];
    }

    /**
     * @return list<array{text: string, conversation: int}>
     */
    private function loadSourceQuestions(): array
    {
        $ids = collect(explode(',', (string) request()->query('from', '')))
            ->map(static fn (string $id): int => (int) trim($id))
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->take(self::MAX_SOURCES)
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        /*
         * Роль проверяем запросом, а не доверяем ссылке: подставить в форму
         * ответ бота вместо вопроса покупателя — это статья, написанная
         * по словам самого бота, то есть закрепление его же ошибки.
         */
        $messages = ChatMessage::query()
            ->whereIn('id', $ids->all())
            ->where('role', ChatMessage::ROLE_VISITOR)
            ->get(['id', 'chat_conversation_id', 'body'])
            ->keyBy('id');

        return $ids
            ->map(static fn (int $id): ?ChatMessage => $messages->get($id))
            ->filter()
            ->map(static fn (ChatMessage $message): array => [
                'text' => trim((string) $message->body),
                'conversation' => (int) $message->chat_conversation_id,
            ])
            ->filter(static fn (array $question): bool => $question['text'] !== '')
            ->values()
            ->all();
    }
}
