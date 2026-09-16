<?php

namespace App\Filament\Pages;

use App\Models\SurveyResponse;
use App\Support\Surveys\BotKnowledgeQuestionnaire;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Vite;
use Livewire\Attributes\Renderless;
use Throwable;
use UnitEnum;

/**
 * Вопросы владельцу магазина перед запуском ИИ-помощника в чате.
 *
 * Сама анкета — в `BotKnowledgeQuestionnaire`, здесь только хранение.
 * Ответы сохраняются сами, пока человек пишет (черновик — одна строка),
 * и отдельно — по кнопке «Отправить» (строка на каждую отправку). Забирать
 * их — `php artisan survey:bot-knowledge`.
 *
 * Страница живёт в `main`, а не в ветке бота: отвечать владелец будет на бою,
 * задолго до того, как бот туда выйдет.
 *
 * ⚠️ Группа «ИИ бот» в ветке бота получает значок, а Filament запрещает
 * иконки у пунктов группы со значком — и падает вся админка. Поэтому
 * `$navigationIcon` здесь нет и появляться не должен.
 */
class BotKnowledgeSurvey extends Page
{
    protected static string|UnitEnum|null $navigationGroup = 'ИИ бот';

    protected static ?int $navigationSort = 90;

    protected static ?string $navigationLabel = 'Вопросы для бота';

    protected static ?string $title = 'Вопросы для бота';

    protected static ?string $slug = 'bot-questions';

    protected string $view = 'filament.pages.bot-knowledge-survey';

    protected Width|string|null $maxContentWidth = Width::SevenExtraLarge;

    public static function canAccess(): bool
    {
        return Auth::user()?->isFilamentAdmin() === true;
    }

    public static function getNavigationBadge(): ?string
    {
        try {
            return SurveyResponse::latestFor(SurveyResponse::SURVEY_BOT_KNOWLEDGE) === null ? 'новое' : null;
        } catch (Throwable) {
            // Таблица ещё не смигрирована — значок не должен ронять всю панель.
            return null;
        }
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'warning';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $draft = SurveyResponse::latestFor(SurveyResponse::SURVEY_BOT_KNOWLEDGE_DRAFT);
        $submitted = SurveyResponse::latestFor(SurveyResponse::SURVEY_BOT_KNOWLEDGE);
        $answers = (array) ($draft?->answers ?? $submitted?->answers ?? []);

        return [
            'config' => [
                'sections' => BotKnowledgeQuestionnaire::sections(),
                'questions' => BotKnowledgeQuestionnaire::questions(),
                'intro' => BotKnowledgeQuestionnaire::intro(),
                'answers' => (object) $answers,
                'draftSavedAt' => $draft?->updated_at?->getTimestampMs(),
                'savedLabel' => $this->stamp($draft),
                'submitted' => $submitted === null ? null : $this->submission($submitted),
            ],
            'fonts' => $this->fonts(),
        ];
    }

    /**
     * Автосохранение. Без перерисовки: страница целиком живёт в Alpine,
     * и морфинг Livewire посреди набора текста сбивал бы курсор.
     *
     * @param  array<mixed>  $answers
     * @return array{savedLabel: string|null, answered: int}
     */
    #[Renderless]
    public function saveDraft(array $answers): array
    {
        $clean = BotKnowledgeQuestionnaire::sanitize($answers);
        $draft = $this->storeDraft($clean);

        return [
            'savedLabel' => $this->stamp($draft),
            'answered' => BotKnowledgeQuestionnaire::answeredCount($clean),
        ];
    }

    /**
     * Отправка — не обязательно полная: отвеченное можно брать в работу
     * сразу, остальное владелец допишет и отправит ещё раз.
     *
     * @param  array<mixed>  $answers
     * @return array{ok: bool, submitted?: array<string, mixed>}
     */
    #[Renderless]
    public function submit(array $answers): array
    {
        $clean = BotKnowledgeQuestionnaire::sanitize($answers);
        $answered = BotKnowledgeQuestionnaire::answeredCount($clean);

        if ($answered === 0) {
            Notification::make()
                ->title('Пока нечего отправлять')
                ->body('Ответьте хотя бы на один вопрос.')
                ->warning()
                ->send();

            return ['ok' => false];
        }

        $this->storeDraft($clean);

        $response = SurveyResponse::query()->create([
            'survey' => SurveyResponse::SURVEY_BOT_KNOWLEDGE,
            'user_id' => Auth::id(),
            'answers' => $clean,
        ]);

        Notification::make()
            ->title('Ответы отправлены')
            ->body('Спасибо! Перепишем их в ответы бота и пришлём вам на вычитку.')
            ->success()
            ->send();

        return ['ok' => true, 'submitted' => $this->submission($response)];
    }

    /**
     * @param  array<string, mixed>  $answers
     */
    private function storeDraft(array $answers): SurveyResponse
    {
        $draft = SurveyResponse::latestFor(SurveyResponse::SURVEY_BOT_KNOWLEDGE_DRAFT)
            ?? new SurveyResponse(['survey' => SurveyResponse::SURVEY_BOT_KNOWLEDGE_DRAFT]);

        $draft->fill(['user_id' => Auth::id(), 'answers' => $answers]);

        // Черновик пересохраняется и без правок: отметка времени — это
        // «сервер видел эти ответы», по ней страница сверяет свою копию.
        $draft->updated_at = $draft->freshTimestamp();
        $draft->save();

        return $draft;
    }

    /**
     * @return array{at: string, by: string|null, answered: int}
     */
    private function submission(SurveyResponse $response): array
    {
        return [
            'at' => $response->created_at?->timezone('Europe/Moscow')->format('d.m.Y в H:i') ?? '',
            'by' => $response->user?->name ?? $response->user?->email,
            'answered' => BotKnowledgeQuestionnaire::answeredCount((array) $response->answers),
        ];
    }

    private function stamp(?SurveyResponse $draft): ?string
    {
        return $draft?->updated_at?->timezone('Europe/Moscow')->format('H:i');
    }

    /**
     * Шрифт витрины, чтобы анкета говорила голосом магазина. Файлы уже
     * лежат в сборке — берём их оттуда; нет сборки (тесты, чистый клон) —
     * заголовки просто наберутся шрифтом панели.
     *
     * @return array{cyrillic: string, latin: string}|null
     */
    private function fonts(): ?array
    {
        try {
            return [
                'cyrillic' => Vite::asset('resources/fonts/roboto-flex/roboto-flex-cyrillic.woff2'),
                'latin' => Vite::asset('resources/fonts/roboto-flex/roboto-flex-latin.woff2'),
            ];
        } catch (Throwable) {
            return null;
        }
    }
}
