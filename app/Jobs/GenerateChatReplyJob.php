<?php

namespace App\Jobs;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Services\Ai\AssistantConfig;
use App\Services\Ai\Data\AssistantReply;
use App\Services\Ai\ShopAssistant;
use App\Services\Ai\Support\PiiRedactor;
use App\Services\Ai\SystemPromptBuilder;
use App\Services\Chat\AssistantQueueHealth;
use App\Services\Chat\ChatAbuseGuard;
use App\Services\Chat\ChatAnswerCache;
use App\Services\Chat\ChatConversationService;
use App\Services\Chat\ChatEscalationService;
use App\Services\Chat\OperatorPresence;
use App\Services\Chat\PageContext;
use App\Services\Kb\KbVectorStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ответ ассистента на сообщение посетителя.
 *
 * Асинхронно, потому что синхронно нельзя: замер донора дал p95 в 34 секунды
 * и максимум 58 — держать столько воркер FPM значит положить витрину первым
 * же десятком одновременных вопросов.
 *
 * Посетитель на витрине не уходит и не ждёт письма — он смотрит на
 * индикатор, поэтому ЛЮБОЙ исход обязан кончиться сообщением в ленте.
 * Молча выйти можно только там, где отвечать уже некому: диалог закрыт,
 * разговор у оператора, вопрос перебит следующим.
 */
class GenerateChatReplyJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Не ретраим: повтор — двойная оплата модели и риск дубля ответа. */
    public int $tries = 1;

    /**
     * Инвариант, нарушать который нельзя:
     *     timeout джобы (200) < --timeout воркера (240) < retry_after (300)
     *
     * Так истечение времени приходит обычным исключением внутрь handle(),
     * и посетитель получает фолбэк от НАС, а не остаётся с вечным
     * «печатает» после того, как воркер убил процесс сигналом.
     * Держит его AssistantQueueConnectionTest.
     */
    public int $timeout = 200;

    public function __construct(
        public readonly int $conversationId,
        public readonly int $visitorMessageId,
    ) {
        $this->onConnection('redis-assistant')->onQueue('assistant');
    }

    public function handle(
        ShopAssistant $assistant,
        ChatConversationService $chat,
        ChatEscalationService $escalation,
        OperatorPresence $presence,
    ): void {
        // Воркер взял задачу — значит, он жив. По этой отметке витрина
        // понимает, что очередь разгребается, и не обещает посетителю
        // ответ, которого не будет (AssistantQueueHealth).
        app(AssistantQueueHealth::class)->heartbeat();

        $conversation = ChatConversation::query()->find($this->conversationId);

        if ($conversation === null) {
            return;
        }

        /*
         * Разговор больше не наш: закрыт или его ведёт живой оператор.
         * Отвечать некому и незачем — выходим молча, сняв пометку
         * «готовится», чтобы посетитель не смотрел на «печатает».
         */
        if (! $conversation->isBotLed()) {
            $chat->clearPending($conversation);

            return;
        }

        // Выключен: настройкой в админке или аварийной строкой в .env —
        // джобе разница безразлична. Посетитель уже задал вопрос и ждёт,
        // поэтому молчать нельзя: вопрос уходит менеджеру.
        if (! app(AssistantConfig::class)->enabled()) {
            $this->handOff($conversation, $chat, $escalation, 'disabled');

            return;
        }

        $question = $conversation->messages()->find($this->visitorMessageId);

        if ($question === null || ! $question->isFromVisitor()) {
            $chat->clearPending($conversation);

            return;
        }

        /*
         * Отвечаем только на последнее сообщение. Посетитель, дописавший
         * вторую реплику через секунду, ждёт ответа на обе сразу — своя
         * джоба заберёт их одной историей. Пометку «готовится» при этом
         * НЕ снимаем: её снимет та, более поздняя джоба.
         */
        $last = $conversation->messages()->orderByDesc('id')->first();

        if ($last !== null && $last->getKey() !== $question->getKey()) {
            return;
        }

        $startedAt = microtime(true);

        $history = $chat->history($conversation, exceptMessageId: $question->getKey());

        /*
         * Повтор уже отвеченного вопроса — без вызова модели.
         *
         * Что именно попадает в кэш и почему условий пять — в ChatAnswerCache;
         * здесь важно, что кэш спрашивается ТОЛЬКО на первом ходе разговора.
         */
        $cache = app(ChatAnswerCache::class);
        $fingerprint = $this->fingerprint($conversation, $question, $presence);
        $cached = $history === [] ? $cache->get((string) $question->body, $fingerprint) : null;

        if ($cached !== null) {
            Log::info('Chat reply served from cache', [
                'conversation_id' => $conversation->getKey(),
            ]);

            $chat->addAssistantMessage($conversation, $cached['text'], questionVector: $this->questionVector($question), reply: new AssistantReply(
                text: $cached['text'],
                // Видно в админке рядом с ответом: ход, который ничего
                // не стоил, не должен выглядеть как обычный.
                stopReason: 'cached',
                citations: $cached['citations'],
            ));

            $chat->clearPending($conversation);

            return;
        }

        try {
            $reply = $assistant->ask(
                question: (string) $question->body,
                history: $history,
                sessionId: $conversation->promptSessionId(),
                page: PageContext::toPrompt($question->page_context),
                // Редактируемый слой промпта: «О магазине», «Правила
                // магазина», запретные темы и формулировки — из админки.
                settings: app(AssistantConfig::class)->promptSettings(),
                // Внутри воркера auth() пуст, поэтому «кто спрашивает»
                // берём из самого диалога: листенер на Login проставляет
                // user_id, в том числе задним числом.
                seesDiscounts: $conversation->user_id !== null,
                // Присутствие меняет не решение об эскалации, а обещание,
                // которое бот даёт вместе с ней: «ответят в чате» днём и
                // «ответят в рабочее время, оставьте почту» ночью.
                operatorsOnline: $presence->isOnline(),
                workingHours: $presence->scheduleSummary(),
            );
        } catch (Throwable $e) {
            Log::warning('Chat reply failed', [
                'conversation_id' => $conversation->getKey(),
                'seconds' => round(microtime(true) - $startedAt, 1),
                'error' => $e->getMessage(),
            ]);

            $this->handOff($conversation, $chat, $escalation, 'exception');

            return;
        }

        $this->log($conversation, $reply, $startedAt);

        /*
         * Дневной бюджет считает ПОТРАЧЕННОЕ, а не доставленное: ответ,
         * выброшенный ниже из-за перехвата оператором, шлюзом уже оплачен,
         * и не увидеть его в бюджете значило бы обманывать себя ровно в тех
         * случаях, когда денег уходит больше всего.
         */
        app(ChatAbuseGuard::class)->recordSpend($reply->inputTokens + $reply->outputTokens);

        /*
         * Пока модель думала, разговор мог перейти к человеку.
         *
         * Проверка в начале handle() этого не ловит: между ней и ответом
         * проходит от шести секунд до минуты, и оператору хватает времени
         * взять разговор и начать печатать. Без второй проверки ответ бота
         * падал бы в разговор, который уже ведёт человек: два ответа на один
         * вопрос, разными голосами, вперемешку.
         *
         * Ответ выбрасываем, деньги за него уже потрачены — это пишется
         * в лог отдельной строкой.
         */
        $conversation->refresh();

        if (! $conversation->isBotLed()) {
            Log::info('Chat reply discarded, conversation taken over', [
                'conversation_id' => $conversation->getKey(),
                'cost_rub' => $reply->costRub,
                'seconds' => round(microtime(true) - $startedAt, 1),
            ]);

            $chat->clearPending($conversation);

            return;
        }

        if ($reply->isFailure()) {
            $this->handOff($conversation, $chat, $escalation, $reply->stopReason, $reply);

            return;
        }

        $chat->addAssistantMessage(
            $conversation,
            $reply->text,
            $reply,
            (float) config('ai_support.knowledge_base.min_score'),
            questionVector: $this->questionVector($question),
        );

        if ($cache->isCacheable($reply, firstTurn: $history === [])) {
            $cache->put((string) $question->body, $fingerprint, $reply);
        }

        // Бот сам позвал человека: пометка «ждёт менеджера» и запись в ленте.
        // Статус разговора при этом не меняется — бот продолжает отвечать,
        // пока за него не сядет человек (см. ChatEscalationService).
        if ($reply->escalated) {
            $escalation->escalate(
                $conversation,
                ChatEscalationService::TRIGGER_BOT,
                $reply->escalationReason,
            );
        }

        $chat->clearPending($conversation);
    }

    /**
     * Джоба умерла мимо catch — убита воркером по таймауту или не пережила
     * десериализацию. Без этого хука посетитель навсегда остаётся с
     * индикатором «печатает» и без ответа.
     */
    public function failed(?Throwable $e): void
    {
        Log::warning('Chat reply job failed outside handle()', [
            'conversation_id' => $this->conversationId,
            'error' => $e?->getMessage(),
        ]);

        $conversation = ChatConversation::query()->find($this->conversationId);

        if ($conversation === null) {
            return;
        }

        $chat = app(ChatConversationService::class);

        // Пока джоба умирала, диалог мог уйти оператору — тогда заглушка
        // «не получается ответить» встала бы поперёк живого разговора.
        if (! $conversation->isBotLed()) {
            $chat->clearPending($conversation);

            return;
        }

        $this->handOff($conversation, $chat, app(ChatEscalationService::class), 'crashed');
    }

    /**
     * Мягкая эскалация: посетитель получает человеческую фразу, а не
     * служебный текст, и разговор помечается как ушедший менеджеру.
     *
     * Служебная причина при этом сохраняется в stop_reason — в админке
     * должно быть видно, чем именно кончился ход, иначе все провалы
     * выглядят одинаково.
     */
    private function handOff(
        ChatConversation $conversation,
        ChatConversationService $chat,
        ChatEscalationService $escalation,
        string $reason,
        ?AssistantReply $reply = null,
    ): void {
        if (! $conversation->isBotLed()) {
            $chat->clearPending($conversation);

            return;
        }

        // Ответ уже написан (например, джоба доигралась после падения) —
        // второй заглушкой ленту не засоряем.
        $last = $conversation->messages()->orderByDesc('id')->first();

        if ($last === null || $last->role !== ChatMessage::ROLE_VISITOR) {
            $chat->clearPending($conversation);

            return;
        }

        $chat->addAssistantMessage(
            $conversation,
            $this->fallbackText($reason),
            $reply,
            stopReason: $reason,
            // Провал — тоже сигнал для «Пробелов», и вопрос под рукой:
            // выше проверено, что последняя реплика принадлежит покупателю.
            questionVector: $this->questionVector($last),
        );

        $escalation->escalate(
            $conversation,
            ChatEscalationService::TRIGGER_FAILURE,
            $this->failureReason($reason),
        );

        $chat->clearPending($conversation);
    }

    /**
     * Вектор вопроса — от слов ПОКУПАТЕЛЯ и на каждый ответ.
     *
     * У донора вектор был побочным продуктом поиска по базе знаний, и это
     * давало две дыры: у товарных вопросов его не было вовсе, а у остальных
     * он описывал формулировку самого бота. На проде донора — 2 вектора
     * на 46 сообщений, то есть «Пробелы» видели меньше десятой части потока.
     *
     * Стоит это десятки токенов на фоне десятков копеек за сам ответ и идёт
     * ПОСЛЕ него: на точность не влияет ничего.
     *
     * Сбой шлюза здесь не должен стоить покупателю ответа: считать вектор
     * мы пытаемся и тогда, когда шлюз, возможно, уже лежит (заглушка провала).
     * Не получилось — пишем без вектора.
     *
     * @return list<float>|null
     */
    private function questionVector(?ChatMessage $question): ?array
    {
        $text = trim((string) $question?->body);

        if ($text === '') {
            return null;
        }

        try {
            $vector = app(KbVectorStore::class)->embedQuery(
                app(PiiRedactor::class)->redact($text),
            );
        } catch (Throwable $e) {
            Log::warning('Не смог посчитать вектор вопроса', [
                'message_id' => $question?->getKey(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return $vector === [] ? null : $vector;
    }

    /**
     * Человеческая расшифровка служебной причины для менеджера: «модель
     * зациклилась» и «шлюз не ответил» требуют от него одного и того же
     * действия, но понимать, что случилось, он должен без чтения логов.
     */
    private function failureReason(string $reason): string
    {
        return match ($reason) {
            'disabled' => 'Консультант выключен, ответить было некому.',
            'max_iterations' => 'Консультант не уложился в отведённые шаги.',
            'empty' => 'Консультант вернул пустой ответ.',
            'contaminated' => 'Ответ консультанта не прошёл проверку и скрыт.',
            'placeholder_leak' => 'В ответе оказалась служебная заглушка вместо контакта, ответ скрыт.',
            'pii_blocked' => 'В сообщении оказались персональные данные, ответ заблокирован.',
            'crashed' => 'Задача ответа не доработала до конца.',
            default => 'Не удалось получить ответ консультанта.',
        };
    }

    private function fallbackText(string $reason): string
    {
        if ($reason === 'disabled') {
            return 'Сейчас консультант недоступен. Оставьте почту — менеджер напишет '
                .'и ответит на ваш вопрос.';
        }

        return 'Не получается ответить прямо сейчас. Передал ваш вопрос менеджеру — '
            .'он свяжется с вами. Оставьте почту, чтобы он мог ответить.';
    }

    /**
     * Всё, что делает один и тот же текст вопроса РАЗНЫМ вопросом.
     *
     * Тот же вопрос со страницы товара, от вошедшего покупателя, в смену
     * и вне её — это разные системные промпты. Отдельно сюда входит хэш
     * редактируемых настроек: без него правка правил магазина в админке
     * ещё неделю не доезжала бы до тех, кто спрашивает популярное.
     *
     * @return array<string, mixed>
     */
    private function fingerprint(
        ChatConversation $conversation,
        ChatMessage $question,
        OperatorPresence $presence,
    ): array {
        return [
            'model' => (string) config('ai_support.agent.model'),
            'page' => PageContext::toPrompt($question->page_context),
            'discounts' => $conversation->user_id !== null,
            'online' => $presence->isOnline(),
            'hours' => $presence->scheduleSummary(),
            // Адрес магазина стоит в промпте: сменят почту в админке — ответы
            // со старым адресом не должны жить в кэше ещё неделю.
            'contact' => app(SystemPromptBuilder::class)->contactEmail(),
            'settings' => hash('sha256', json_encode(
                app(AssistantConfig::class)->promptSettings(),
                JSON_UNESCAPED_UNICODE,
            ) ?: ''),
        ];
    }

    private function log(ChatConversation $conversation, AssistantReply $reply, float $startedAt): void
    {
        $context = [
            'conversation_id' => $conversation->getKey(),
            'model' => (string) config('ai_support.agent.model'),
            'stop_reason' => $reply->stopReason,
            'seconds' => round(microtime(true) - $startedAt, 1),
            'tool_calls' => $reply->toolNames(),
            'input_tokens' => $reply->inputTokens,
            'output_tokens' => $reply->outputTokens,
            'cached_tokens' => $reply->cachedTokens,
            'cost_rub' => $reply->costRub,
            'escalated' => $reply->escalated,
        ];

        if ($reply->isFailure()) {
            Log::warning('Chat reply unusable', $context);
        } else {
            Log::info('Chat reply generated', $context);
        }
    }
}
