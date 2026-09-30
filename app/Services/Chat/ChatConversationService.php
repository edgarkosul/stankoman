<?php

namespace App\Services\Chat;

use App\Models\AiUsageEntry;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Services\Ai\Data\AssistantReply;
use App\Services\Ai\Support\PiiRedactor;
use App\Services\Kb\KbVectorStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * Жизненный цикл разговора: опознание анонима, запись сообщений, история
 * для модели и пометка «ответ готовится».
 *
 * Всё, что связано с куками и счётчиками, собрано здесь, а не размазано
 * по Livewire-компоненту и джобе: обе стороны пишут в одни и те же поля,
 * и разъехаться им нельзя.
 */
final class ChatConversationService
{
    public function __construct(
        private readonly string $cookieName,
        private readonly int $historyMessages,
        private readonly int $pendingTtl,
        private readonly int $typingTtl,
        private readonly PiiRedactor $redactor = new PiiRedactor,
    ) {}

    /**
     * Разговор посетителя по токену из куки. Единственный способ добраться
     * до переписки: ни id, ни что-либо ещё от клиента мы не принимаем,
     * поэтому чужой диалог открыть нечем.
     */
    public function current(?Request $request = null): ?ChatConversation
    {
        $token = (string) ($request ?? request())->cookie($this->cookieName);

        if ($token === '' || mb_strlen($token) > 40) {
            return null;
        }

        return ChatConversation::query()->byToken($token)->first();
    }

    /**
     * Завести разговор и выдать куку.
     *
     * Куку ставит СЕРВЕР, и она httpOnly: токен даёт доступ к переписке,
     * в которой может оказаться телефон, а из localStorage его унесёт
     * любой XSS. Вечная — потому что вернувшийся через месяц посетитель
     * должен увидеть свою историю; Safari режет до семи дней только те
     * куки, что выставлены из JS.
     */
    public function start(?Request $request = null): ChatConversation
    {
        $request ??= request();

        $conversation = ChatConversation::query()->create([
            'token' => ChatConversation::freshToken(),
            'user_id' => Auth::id(),
            'status' => ChatConversation::STATUS_BOT,
            'assistant_enabled' => true,
            // Адрес не храним — только хэш: сопоставить два обращения он
            // позволяет, а посетителя не описывает.
            'ip_hash' => hash('sha256', (string) $request->ip()),
            'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
            'referer_url' => Str::limit((string) $request->headers->get('referer'), 500, ''),
        ]);

        $this->rememberCookie($conversation);

        return $conversation;
    }

    public function rememberCookie(ChatConversation $conversation): void
    {
        Cookie::queue(Cookie::forever(
            name: $this->cookieName,
            value: $conversation->token,
            httpOnly: true,
            sameSite: 'lax',
        ));
    }

    /** Выход из аккаунта: чужая переписка не должна достаться следующему. */
    public function forgetCookie(): void
    {
        Cookie::queue(Cookie::forget($this->cookieName));
    }

    /**
     * @param  array<string, mixed>|null  $pageContext
     */
    public function addVisitorMessage(
        ChatConversation $conversation,
        string $body,
        ?array $pageContext = null,
    ): ChatMessage {
        $message = $conversation->messages()->create([
            'role' => ChatMessage::ROLE_VISITOR,
            'body' => $body,
            'page_context' => $pageContext,
            'read_at' => now(),
            /*
             * Покупатель написал контакт прямо в сообщении. В заявку оттуда
             * он не попадёт: заявку заводит форма. Поэтому факт фиксируем
             * сразу, и форма появляется, НЕ дожидаясь решения модели, —
             * у донора модель прочла спрятанную почту как вопрос «какая
             * у вас почта» и назвала адрес магазина (диалог 17).
             *
             * Контакт, а не любые ПДн: длинный артикул в вопросе не повод
             * просить почту. Сам адрес и так лежит в body целиком — чистится
             * только путь в шлюз.
             */
            'meta' => $this->redactor->containsContact($body) ? ['contact_in_chat' => true] : null,
        ]);

        $conversation->forceFill([
            'messages_count' => $conversation->messages_count + 1,
            'last_message_at' => now(),
            'last_seen_at' => now(),
            'unread_for_staff' => $conversation->unread_for_staff + 1,
            // Согласие берётся один раз, вместе с оговоркой под полем ввода.
            'consent_at' => $conversation->consent_at ?? now(),
        ])->save();

        return $message;
    }

    /**
     * Ответ бота вместе с телеметрией: без stop_reason и списка вызовов
     * в админке видна только конечная фраза, и «модель зациклилась»
     * не отличить от «шлюз залип».
     *
     * @param  list<float>|null  $questionVector  вектор РЕПЛИКИ ПОКУПАТЕЛЯ,
     *                                            посчитанный снаружи
     */
    public function addAssistantMessage(
        ChatConversation $conversation,
        string $body,
        ?AssistantReply $reply = null,
        float $minScore = 0.0,
        ?string $stopReason = null,
        ?array $questionVector = null,
    ): ChatMessage {
        $message = $conversation->messages()->create([
            'role' => ChatMessage::ROLE_ASSISTANT,
            'body' => $body,
            // У заглушки, написанной мимо агента, своей причины нет —
            // её передают снаружи, иначе в админке все провалы выглядят
            // одинаково пустыми.
            'stop_reason' => $reply?->stopReason ?? $stopReason,
            'tool_calls' => $reply?->toolCalls ?: null,
            'citations' => $reply?->citations ?: null,
            'input_tokens' => $reply?->inputTokens ?? 0,
            'output_tokens' => $reply?->outputTokens ?? 0,
            'cached_tokens' => $reply?->cachedTokens ?? 0,
            'cost_rub' => $reply?->costRub ?? 0.0,
            'latency_ms' => $reply?->latencyMs ?? 0,
            // Модель из ответа шлюза, а не из конфига: конфиг знает только
            // сегодняшнюю, а строка живёт годами. У ответа из кэша и у
            // заглушек её нет вовсе — это честный null.
            'model' => ($reply?->model ?: null),
            'kb_miss' => (bool) $reply?->isKbMiss($minScore),
            /*
             * Вектор вопроса. Лежит рядом с ответом, а не с репликой
             * покупателя, по той же причине, что и kb_miss: все сигналы
             * одного хода должны читаться одной строкой.
             *
             * Считается снаружи, от слов ПОКУПАТЕЛЯ. У донора сюда сначала
             * клался вектор, посчитанный поиском по базе знаний, — «ни одного
             * лишнего вызова шлюза», — и это оказалось дырой в два слоя:
             * у товарных вопросов вектора не было вовсе (бот идёт в каталог),
             * а у остальных он описывал поисковый запрос бота, а не вопрос.
             * Замер на проде донора: вектор у 2 сообщений из 46.
             *
             * Вектор из ответа остался запасным: у путей, где вопрос под рукой
             * не оказался, лучше неточный вектор, чем никакого.
             */
            'embedding' => match (true) {
                $questionVector !== null && $questionVector !== [] => KbVectorStore::packVector($questionVector),
                (bool) $reply?->questionEmbedding => KbVectorStore::packVector($reply->questionEmbedding),
                default => null,
            },
            'meta' => array_filter([
                'callback_requested' => (bool) $reply?->callbackRequested,
                'escalated' => (bool) $reply?->escalated,
                // Тема подставится в комментарий заявки, причина эскалации
                // уйдёт менеджеру в уведомление.
                'callback_topic' => $reply?->callbackTopic,
                'escalation_reason' => $reply?->escalationReason,
            ]) ?: null,
        ]);

        /*
         * Расходная книга. Пишется здесь, потому что это единственная
         * воронка, через которую проходят ВСЕ ответы бота: и обычные,
         * и из кэша, и заглушки аварийной передачи менеджеру.
         *
         * Отдельно от сообщения — потому что сообщение покупатель может
         * стереть кнопкой «Очистить переписку», а потраченные деньги
         * стереть нельзя.
         */
        AiUsageEntry::query()->create(AiUsageEntry::attributesFor(
            $reply,
            (int) $conversation->getKey(),
            escalated: $conversation->escalated_at !== null,
            stopReason: $stopReason,
        ) + ['created_at' => $message->created_at]);

        $conversation->forceFill([
            'messages_count' => $conversation->messages_count + 1,
            'last_message_at' => now(),
            'unread_for_visitor' => $conversation->unread_for_visitor + 1,
            'input_tokens' => $conversation->input_tokens + ($reply?->inputTokens ?? 0),
            'output_tokens' => $conversation->output_tokens + ($reply?->outputTokens ?? 0),
            'cached_tokens' => $conversation->cached_tokens + ($reply?->cachedTokens ?? 0),
            'cost_rub' => round($conversation->cost_rub + ($reply?->costRub ?? 0.0), 4),
        ])->save();

        return $message;
    }

    /**
     * Сколько ответов посетитель ещё не видел — для бейджа на лаунчере.
     */
    public function unreadForVisitor(?Request $request = null): int
    {
        return $this->launcherState($request)['unread'];
    }

    /**
     * Всё, что лаунчеру нужно знать о чате, — одним запросом к базе.
     *
     * Две вещи: сколько непрочитанного показать бейджем и стоит ли
     * сторожить ответ, пока панель свёрнута. Второе появилось у донора
     * из дефекта: ответ менеджера, пришедший на открытой странице,
     * посетитель не видел вовсе — закрытая панель сервер не опрашивает,
     * а бейдж считался только при отрисовке страницы.
     *
     * `poll` — секунды между опросами или null, «не опрашивать».
     * Null здесь норма: у подавляющего большинства посетителей куки чата
     * нет вовсе, и запроса к базе тоже не будет.
     *
     * @return array{unread: int, poll: int|null}
     */
    public function launcherState(?Request $request = null): array
    {
        $idle = ['unread' => 0, 'poll' => null];

        $token = (string) ($request ?? request())->cookie($this->cookieName);

        if ($token === '' || mb_strlen($token) > 40) {
            return $idle;
        }

        $conversation = ChatConversation::query()
            ->byToken($token)
            ->where('status', '!=', ChatConversation::STATUS_CLOSED)
            ->first(['status', 'escalated_at', 'unread_for_visitor', 'last_message_at']);

        if ($conversation === null) {
            return $idle;
        }

        $unread = (int) $conversation->unread_for_visitor;

        // Непрочитанное уже есть — бейдж стоит, и узнавать больше нечего.
        if ($unread > 0) {
            return ['unread' => $unread, 'poll' => null];
        }

        $watches = ChatPollingCadence::launcherWatches(
            escalated: $conversation->isEscalated(),
            operatorLed: $conversation->isOperatorLed(),
            secondsSinceLastMessage: (int) ($conversation->last_message_at?->diffInSeconds(absolute: true) ?? PHP_INT_MAX),
        );

        return [
            'unread' => 0,
            'poll' => $watches ? ChatPollingCadence::LAUNCHER_INTERVAL_SECONDS : null,
        ];
    }

    public function touchSeen(ChatConversation $conversation): void
    {
        $conversation->forceFill([
            'last_seen_at' => now(),
            'unread_for_visitor' => 0,
        ])->save();
    }

    /**
     * История для модели — простые ходы «покупатель / консультант».
     *
     * Результаты инструментов сюда НЕ попадают, и это осознанно. Во-первых,
     * они протухли: цена и наличие, найденные пять минут назад, назывались
     * актуальными именно тогда. Во-вторых, они втрое раздувают ввод, а он
     * и есть основная часть счёта. Понадобятся снова — модель сходит
     * и посмотрит заново.
     *
     * @return list<array<string, string>>
     */
    public function history(ChatConversation $conversation, ?int $exceptMessageId = null): array
    {
        return $conversation->messages()
            ->whereIn('role', [ChatMessage::ROLE_VISITOR, ChatMessage::ROLE_ASSISTANT, ChatMessage::ROLE_OPERATOR])
            ->when($exceptMessageId !== null, fn ($q) => $q->where('id', '!=', $exceptMessageId))
            ->where('body', '!=', '')
            ->orderByDesc('id')
            ->limit($this->historyMessages)
            ->get(['role', 'body'])
            ->reverse()
            ->map(static fn (ChatMessage $message): array => [
                'role' => $message->isFromVisitor() ? 'user' : 'assistant',
                'content' => (string) $message->body,
                /*
                 * Кто написал НА САМОМ ДЕЛЕ. Роль для модели общая: реплика
                 * живого менеджера едет под assistant, потому что для
                 * покупателя это один собеседник. Редактору ПДн разница
                 * решающая — текст бота чистить нельзя, текст человека
                 * обязательно.
                 */
                PiiRedactor::ORIGIN => match ($message->role) {
                    ChatMessage::ROLE_VISITOR => PiiRedactor::ORIGIN_VISITOR,
                    ChatMessage::ROLE_OPERATOR => PiiRedactor::ORIGIN_OPERATOR,
                    default => PiiRedactor::ORIGIN_BOT,
                },
            ])
            ->values()
            ->all();
    }

    /**
     * Пометка «ответ готовится».
     *
     * Живёт в кэше, а не в базе, ради одного: тик поллинга должен стоить
     * один GET в Redis, а не запрос к MySQL и рендер компонента.
     */
    public function markPending(ChatConversation $conversation): void
    {
        Cache::put($this->pendingKey($conversation), true, $this->pendingTtl);
    }

    public function clearPending(ChatConversation $conversation): void
    {
        Cache::forget($this->pendingKey($conversation));
    }

    public function isPending(ChatConversation $conversation): bool
    {
        return Cache::has($this->pendingKey($conversation));
    }

    private function pendingKey(ChatConversation $conversation): string
    {
        return 'chat:pending:'.$conversation->getKey();
    }

    /**
     * «Оператор набирает ответ».
     *
     * В кэше по той же причине, что и пометка «ответ готовится»: состояние
     * живёт секунды, читается на каждом тике опроса и не должно стоить
     * ни строки в таблице. Пометку ставит панель оператора по ходу набора,
     * снимает — отправка.
     */
    public function markStaffTyping(ChatConversation $conversation): void
    {
        Cache::put($this->typingKey($conversation), true, $this->typingTtl);
    }

    public function clearStaffTyping(ChatConversation $conversation): void
    {
        Cache::forget($this->typingKey($conversation));
    }

    public function staffTyping(ChatConversation $conversation): bool
    {
        return Cache::has($this->typingKey($conversation));
    }

    private function typingKey(ChatConversation $conversation): string
    {
        return 'chat:typing:'.$conversation->getKey();
    }
}
