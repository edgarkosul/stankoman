<?php

namespace App\Services\Chat;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Services\Chat\Data\ClaimedLead;
use Illuminate\Support\Str;

/**
 * Переходы разговора между ботом и человеком.
 *
 * ЭСКАЛАЦИЯ НЕ МЕНЯЕТ СТАТУС. `escalated_at` — это пометка «вопрос ждёт
 * человека», а `status = operator` появляется только тогда, когда живой
 * оператор взял разговор.
 *
 * Почему не «эскалировал — значит статус operator». Во-первых, эскалацию
 * поднимает и обычный сбой: у джобы ответа любой провал кончается мягкой
 * передачей менеджеру, и сетевая икота выключала бы бота до конца разговора.
 * Во-вторых, эскалация в три часа ночи никого не будит: если бот замолкает
 * сразу, посетитель, задавший следом простой вопрос про оплату, не получит
 * ответа ни от бота, ни от человека.
 *
 * Оборотная сторона: пометка «ждёт человека» может провисеть до утра.
 * Поэтому основной канал — не живой перехват, а контакты в заявке:
 * её менеджер разберёт в любом случае, и письмо о ней уходит уже сейчас.
 *
 * Перехват, возврат боту, закрытие и ответ оператора зовёт карточка диалога
 * в админке. Уведомлений здесь пока нет — ни менеджерам о переданном вопросе,
 * ни покупателю об ответе, пришедшем в закрытую вкладку: разговор, ждущий
 * человека, сейчас виден только бейджем «Диалогов».
 *
 * Сотрудник приходит сюда номером и именем, а не моделью пользователя:
 * кто в магазине сотрудник, решает магазин, а сервисы чата моделей магазина
 * не знают (сторож — tests/Unit/AiServicesSeamTest.php).
 */
final class ChatEscalationService
{
    public function __construct(
        private readonly ChatConversationService $chat,
    ) {}

    /** Бот сам позвал человека инструментом escalate_to_operator. */
    public const TRIGGER_BOT = 'bot';

    /** Бот не смог ответить: упал шлюз, кончились шаги, умерла джоба. */
    public const TRIGGER_FAILURE = 'failure';

    /** Посетитель нажал «Позвать менеджера». */
    public const TRIGGER_VISITOR = 'visitor';

    /** Посетитель оставил контакты — заявку надо разобрать. */
    public const TRIGGER_CALLBACK = 'callback';

    /**
     * Вопрос ждёт человека.
     *
     * Пометка ставится один раз, а строка в ленте — на каждый повод: менеджер,
     * читающий разговор задним числом, должен видеть, сколько раз и почему
     * бот сдавался.
     */
    public function escalate(
        ChatConversation $conversation,
        string $trigger,
        ?string $reason = null,
    ): void {
        if ($conversation->escalated_at === null) {
            $conversation->forceFill(['escalated_at' => now()])->save();
        }

        $this->note($conversation, $this->noteText($trigger, $reason), meta: [
            'event' => 'escalated',
            'trigger' => $trigger,
            'reason' => $reason,
        ]);
    }

    /**
     * Оператор берёт разговор себе. С этой секунды бот молчит: isBotLed()
     * требует статуса bot, поэтому джоба ответа выходит, не тратя денег.
     */
    public function takeOver(ChatConversation $conversation, int $operatorId, string $operatorName): void
    {
        if ($conversation->isClosed()) {
            return;
        }

        // Сменился ли отвечающий с точки зрения покупателя. Переход одного
        // оператора к другому для него ничего не меняет, и объявлять о нём
        // незачем — а вот в заметках для магазина он остаётся.
        $switched = ! $conversation->isOperatorLed();

        $conversation->forceFill([
            'status' => ChatConversation::STATUS_OPERATOR,
            'operator_id' => $operatorId,
            'unread_for_staff' => 0,
        ])->save();

        /*
         * Бот мог в этот момент готовить ответ. Пометку снимаем сразу,
         * не дожидаясь джобы: иначе посетитель видел бы «печатает»
         * от бота и «в работе у менеджера» одновременно. Сам ответ бота,
         * если он всё-таки придёт, отбросит джоба — она перепроверяет
         * разговор после вызова модели.
         */
        $this->chat->clearPending($conversation);

        $this->note(
            $conversation,
            'Оператор '.$operatorName.' взял разговор в работу.',
            operatorId: $operatorId,
            meta: ['event' => 'taken_over'],
            visitorBody: $switched ? 'К разговору подключился менеджер.' : null,
        );
    }

    /**
     * Разговор возвращается боту. Пометку эскалации снимаем: вопрос
     * разобран, и в списке «ждут менеджера» ему делать нечего.
     */
    public function returnToBot(ChatConversation $conversation, ?int $operatorId = null): void
    {
        if ($conversation->isClosed()) {
            return;
        }

        $switched = $conversation->isOperatorLed();

        $conversation->forceFill([
            'status' => ChatConversation::STATUS_BOT,
            'operator_id' => null,
            'escalated_at' => null,
            'assistant_enabled' => true,
            'unread_for_staff' => 0,
        ])->save();

        $this->note(
            $conversation,
            'Разговор возвращён консультанту.',
            operatorId: $operatorId,
            meta: ['event' => 'returned_to_bot'],
            /*
             * Сказать покупателю обязательно. Последнее, что он услышал, —
             * «к разговору подключился менеджер», и после возврата эта фраза
             * становится неправдой: менеджер ушёл, а на экране всё
             * по-прежнему. Формулировка зеркальная, чтобы читалось как
             * продолжение, а не как новая тема.
             */
            visitorBody: $switched ? 'Менеджер передал разговор консультанту.' : null,
        );
    }

    public function close(ChatConversation $conversation, ?int $operatorId = null): void
    {
        if ($conversation->isClosed()) {
            return;
        }

        $conversation->forceFill([
            'status' => ChatConversation::STATUS_CLOSED,
            'unread_for_staff' => 0,
        ])->save();

        $this->chat->clearPending($conversation);

        $this->note(
            $conversation,
            'Разговор закрыт.',
            operatorId: $operatorId,
            meta: ['event' => 'closed'],
            visitorBody: 'Разговор завершён.',
        );
    }

    /**
     * Ответ живого оператора в ленту посетителя.
     *
     * Пишется тем же путём, что и ответ бота, потому что для посетителя это
     * одна и та же переписка: он не переключается между «чатом с ботом»
     * и «чатом с человеком».
     *
     * @param  array<string, mixed>  $meta  пометки для витрины. Единственная
     *                                      сегодня — `callback_requested`:
     *                                      по ней панель покупателя покажет
     *                                      форму контактов, ту же самую, что
     *                                      показывает бот своим инструментом.
     */
    public function reply(ChatConversation $conversation, int $operatorId, string $body, array $meta = []): ChatMessage
    {
        $message = $conversation->messages()->create([
            'role' => ChatMessage::ROLE_OPERATOR,
            'body' => trim($body),
            'operator_id' => $operatorId,
            'meta' => $meta === [] ? null : $meta,
        ]);

        $conversation->forceFill([
            'messages_count' => $conversation->messages_count + 1,
            'last_message_at' => now(),
            'unread_for_visitor' => $conversation->unread_for_visitor + 1,
            'unread_for_staff' => 0,
            // Оператор ответил — значит разговор его, даже если он писал
            // из карточки, не нажимая «Взять в работу».
            'status' => ChatConversation::STATUS_OPERATOR,
            'operator_id' => $conversation->operator_id ?? $operatorId,
        ])->save();

        // Оператор ответил, не нажимая «Взять в работу», — а бот, возможно,
        // всё ещё готовит свой ответ. Гасим ожидание здесь по той же
        // причине, что и в takeOver().
        $this->chat->clearPending($conversation);

        return $message;
    }

    /**
     * Заявка на связь, оформленная из чата.
     *
     * Связь нужна в обе стороны: менеджер в разговоре видит, что контакты
     * оставлены, а по заявке — откуда она пришла. Контакты при этом остаются
     * в заявке и в переписку с моделью не попадают.
     */
    public function attachLead(ChatConversation $conversation, ClaimedLead $lead): void
    {
        $conversation->forceFill([
            'callback_request_id' => $lead->id,
        ])->save();

        $this->note(
            $conversation,
            'Покупатель оставил '.$lead->contacts.', заявка №'.$lead->id.'.',
            meta: ['event' => 'callback_created', 'callback_request_id' => $lead->id],
        );

        $this->escalate($conversation, self::TRIGGER_CALLBACK, 'Оставлены контакты для ответа.');
    }

    /**
     * Служебная пометка в ленте.
     *
     * Роль `system` в историю для модели не попадает — это заметки на полях
     * для менеджера, читающего разговор задним числом.
     *
     * Посетителю такая заметка по умолчанию тоже не видна: она написана
     * внутренними словами. Но смена того, КТО отвечает, — это то, что
     * покупатель обязан знать. Для таких заметок есть `visitorBody`: свой
     * текст, своими словами, в той же строке ленты.
     *
     * @param  array<string, mixed>  $meta
     */
    public function note(
        ChatConversation $conversation,
        string $body,
        ?int $operatorId = null,
        array $meta = [],
        ?string $visitorBody = null,
    ): ChatMessage {
        return $conversation->messages()->create([
            'role' => ChatMessage::ROLE_SYSTEM,
            'body' => $body,
            'operator_id' => $operatorId,
            'meta' => array_filter(
                [...$meta, 'visitor_body' => $visitorBody],
                static fn ($value): bool => $value !== null && $value !== '',
            ),
        ]);
    }

    private function noteText(string $trigger, ?string $reason): string
    {
        $head = match ($trigger) {
            self::TRIGGER_BOT => 'Консультант передал вопрос менеджеру.',
            self::TRIGGER_FAILURE => 'Консультант не смог ответить, вопрос передан менеджеру.',
            self::TRIGGER_VISITOR => 'Покупатель попросил связать с менеджером.',
            self::TRIGGER_CALLBACK => 'Покупатель оставил контакты.',
            default => 'Вопрос передан менеджеру.',
        };

        return $reason === null || trim($reason) === ''
            ? $head
            : $head.' '.Str::limit(trim($reason), 500);
    }
}
