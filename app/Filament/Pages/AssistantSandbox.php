<?php

namespace App\Filament\Pages;

use App\Models\ChatMessage;
use App\Services\Ai\AssistantConfig;
use App\Services\Ai\Data\AssistantReply;
use App\Services\Ai\ShopAssistant;
use App\Services\Ai\Support\PiiRedactor;
use App\Services\Chat\Contracts\PageContextSource;
use App\Services\Chat\OperatorPresence;
use App\Services\Chat\PageContext;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Str;
use Throwable;
use UnitEnum;

/**
 * Песочница «Спросить бота» — ответ на вопрос «почему он так ответил».
 *
 * Без неё разбор ошибки выглядит так: написать боту с телефона, дождаться
 * ответа, пойти в «Диалоги», найти свой разговор, развернуть телеметрию.
 * Здесь то же самое за один заход и с подробностями по умолчанию: сюда
 * пришли именно разбираться, а не работать.
 *
 * Три свойства, отличающие песочницу от чата на витрине:
 *
 *   1) в `chat_conversations` не пишет ничего — разбор не должен оседать
 *      в переписке и попадать потом в «Пробелы» как настоящий вопрос;
 *   2) эскалация и форма контактов никого не трогают: инструмент сработает
 *      и попадёт в телеметрию, но разговор не пометится и заявки не будет;
 *   3) видно ВСЁ сразу — найденные фрагменты с близостью, вызовы
 *      с аргументами, токены и деньги.
 */
class AssistantSandbox extends Page
{
    protected static string|UnitEnum|null $navigationGroup = 'ИИ бот';

    /*
     * Пятый пункт — после статей и разделов, перед настройками: сюда
     * приходят проверять написанное, а в настройки — менять поведение.
     */
    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Спросить бота';

    protected static ?string $title = 'Песочница: спросить бота';

    protected string $view = 'filament.pages.assistant-sandbox';

    /** Сколько ходов держим в разговоре — столько же, сколько на витрине. */
    private const MAX_TURNS = 10;

    public string $question = '';

    /**
     * Разговор целиком: и реплики, и разбор каждого ответа.
     *
     * @var list<array<string, mixed>>
     */
    public array $turns = [];

    /**
     * Ключ сессии для кэша префикса на шлюзе. Без него кэш не включается
     * вовсе, и разбор из пяти вопросов стоит втрое дороже.
     */
    public string $sessionId = '';

    /** Спрашивать как вошедший покупатель: он видит цены со скидкой. */
    public bool $seesDiscounts = false;

    /**
     * Откуда спрашивает покупатель: '' — неважно, 'home', 'product'.
     *
     * Не украшение. Чат ВСЕГДА передаёт боту страницу, на которой стоит
     * посетитель, и на карточке товара это меняет ответ целиком: «сколько
     * он стоит» перестаёт быть загадкой, а `get_product` вообще не нужен.
     * Пока песочница спрашивала бы «из ниоткуда», она проверяла бы бота,
     * которого у покупателя не бывает.
     */
    public string $pageType = '';

    /** Адрес или слаг карточки — то, что проще всего скопировать с витрины. */
    public string $productRef = '';

    public function mount(): void
    {
        $this->sessionId = 'sandbox-'.Str::random(16);
    }

    /** @return array<string, string> */
    public function pageOptions(): array
    {
        return [
            '' => 'Спрашивает вне товара',
            'home' => 'С главной страницы',
            'product' => 'С карточки товара',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reset')
                ->label('Начать заново')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn (): bool => $this->turns !== [])
                ->action('startOver'),
        ];
    }

    /**
     * Начать разговор заново.
     *
     * Не `reset()`: этим именем Livewire сбрасывает свойства компонента,
     * и своя реализация ломает его сигнатурой — фатальной ошибкой прямо
     * на рендере страницы.
     */
    public function startOver(): void
    {
        $this->turns = [];
        $this->question = '';
        $this->sessionId = 'sandbox-'.Str::random(16);
    }

    /**
     * Спросить бота. Синхронно — по тем же соображениям, что и черновик
     * статьи: правило «LLM не в FPM» защищает витрину и покупателя, который
     * ждёт, а здесь один человек сознательно ждёт ответа и смотрит на кружок.
     */
    public function ask(): void
    {
        $question = trim($this->question);

        if (mb_strlen($question) < 2) {
            $this->addError('question', 'Напишите вопрос.');

            return;
        }

        @set_time_limit(120);

        $presence = app(OperatorPresence::class);
        $page = $this->pageContext();

        // Товар не нашёлся — молчать нельзя: проверяли бы «с карточки»,
        // а спрашивали бы на самом деле из ниоткуда.
        if ($this->pageType === 'product' && $page === null) {
            $this->addError('productRef', 'Товар не найден или снят с продажи. Скопируйте адрес карточки с сайта или впишите её слаг.');

            return;
        }

        try {
            $reply = app(ShopAssistant::class)->ask(
                question: $question,
                history: $this->historyForModel(),
                sessionId: $this->sessionId,
                page: $page,
                settings: app(AssistantConfig::class)->promptSettings(),
                seesDiscounts: $this->seesDiscounts,
                operatorsOnline: $presence->isOnline(),
                workingHours: $presence->scheduleSummary(),
            );
        } catch (Throwable $e) {
            Notification::make()
                ->title('Модель не ответила')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->turns[] = ['role' => 'visitor', 'text' => $question];
        $this->turns[] = $this->answerTurn($reply);
        $this->question = '';
    }

    /**
     * Контекст страницы для промпта — тот же, что собирает чат на витрине.
     *
     * Через шов `PageContextSource`, а не своей выборкой товара: цена
     * в контексте страницы — часть ценовой политики, и песочница, считающая
     * её по-своему, проверяла бы не того бота.
     *
     * @return array<string, string>|null
     */
    private function pageContext(): ?array
    {
        $locator = match ($this->pageType) {
            'home' => ['type' => 'home'],
            'product' => ['type' => 'product', 'slug' => $this->productSlug()],
            default => null,
        };

        if ($locator === null) {
            return null;
        }

        return PageContext::toPrompt(app(PageContextSource::class)->describe($locator, $this->seesDiscounts));
    }

    /** С витрины удобнее скопировать адрес целиком — вытаскиваем слаг сами. */
    private function productSlug(): string
    {
        $slug = trim($this->productRef);

        if (str_contains($slug, '/')) {
            $slug = basename((string) (parse_url($slug, PHP_URL_PATH) ?: $slug));
        }

        return $slug;
    }

    /**
     * История для следующего хода — плоские пары «вопрос — ответ».
     *
     * Ровно то, что получает бот на витрине (`ChatConversationService::history()`):
     * ни сообщений инструментов, ни их результатов. Соблазн отдать сюда
     * `$reply->messages` велик — там готовая история от самого агента, —
     * но тогда песочница разговаривает с ботом, который помнит больше
     * покупательского, и проверка перестаёт значить что-либо. Песочница
     * обязана воспроизводить поведение, а не улучшать его.
     *
     * @return list<array<string, mixed>>
     */
    private function historyForModel(): array
    {
        $messages = [];

        foreach ($this->turns as $turn) {
            $text = trim((string) ($turn['text'] ?? ''));

            // Провал в историю не идёт: на витрине вместо него была бы
            // заглушка, а не пустой ответ бота.
            if ($text === '' || ($turn['failed'] ?? false)) {
                continue;
            }

            $fromVisitor = ($turn['role'] ?? '') === 'visitor';

            $messages[] = [
                'role' => $fromVisitor ? 'user' : 'assistant',
                'content' => $text,
                // Операторов в песочнице нет: не посетитель — значит бот.
                PiiRedactor::ORIGIN => $fromVisitor ? PiiRedactor::ORIGIN_VISITOR : PiiRedactor::ORIGIN_BOT,
            ];
        }

        return array_slice($messages, -(self::MAX_TURNS * 2));
    }

    /**
     * Ответ вместе с телеметрией — простыми типами.
     *
     * В свойстве Livewire не должно быть Eloquent-моделей: он сериализует
     * их ссылкой (класс + ключ) и на следующем запросе достаёт из базы,
     * а этой строки в базе нет и не будет. Поэтому здесь массив, а модель
     * для показа собирается на рендере, в `messageFor()`.
     *
     * @return array<string, mixed>
     */
    private function answerTurn(AssistantReply $reply): array
    {
        return [
            'role' => 'assistant',
            'text' => $reply->text,
            'stop_reason' => $reply->stopReason,
            'tool_calls' => $reply->toolCalls,
            'citations' => $reply->citations,
            'input_tokens' => $reply->inputTokens,
            'output_tokens' => $reply->outputTokens,
            'cached_tokens' => $reply->cachedTokens,
            'cost_rub' => $reply->costRub,
            'latency_ms' => $reply->latencyMs,
            'kb_miss' => $reply->isKbMiss((float) config('ai_support.knowledge_base.min_score')),
            /*
             * Провал показываем как есть, а не заглушкой для покупателя:
             * «модель вернула пустоту» — это и есть ответ на вопрос
             * «почему бот промолчал», ради которого сюда и пришли.
             */
            'failed' => $reply->isFailure(),
            'escalated' => $reply->escalated,
            'callback_requested' => $reply->callbackRequested,
        ];
    }

    /**
     * Ход в виде НЕсохранённой модели сообщения.
     *
     * Нужно затем, чтобы разбор рисовался тем же кодом, что и лента
     * диалога (`ChatMessageTelemetry`), и не расходился с ней при первой
     * же правке. В базу эта модель не попадает никогда.
     *
     * @param  array<string, mixed>  $turn
     */
    public function messageFor(array $turn): ChatMessage
    {
        return (new ChatMessage)->forceFill([
            'role' => ChatMessage::ROLE_ASSISTANT,
            'body' => (string) ($turn['text'] ?? ''),
            'stop_reason' => $turn['stop_reason'] ?? null,
            'tool_calls' => $turn['tool_calls'] ?? [],
            'citations' => $turn['citations'] ?? [],
            'input_tokens' => (int) ($turn['input_tokens'] ?? 0),
            'output_tokens' => (int) ($turn['output_tokens'] ?? 0),
            'cached_tokens' => (int) ($turn['cached_tokens'] ?? 0),
            'cost_rub' => (float) ($turn['cost_rub'] ?? 0),
            'latency_ms' => (int) ($turn['latency_ms'] ?? 0),
            'kb_miss' => (bool) ($turn['kb_miss'] ?? false),
        ]);
    }

    /** Сколько стоил разбор — считаем вслух, чтобы не удивляться счёту. */
    public function totalCost(): float
    {
        $total = 0.0;

        foreach ($this->turns as $turn) {
            $total += (float) ($turn['cost_rub'] ?? 0);
        }

        return $total;
    }
}
