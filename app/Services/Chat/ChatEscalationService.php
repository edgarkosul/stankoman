<?php

namespace App\Services\Chat;

use App\Jobs\NotifyManagersAboutEscalationJob;
use App\Jobs\NotifyVisitorAboutOperatorReplyJob;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Services\Chat\Data\ClaimedLead;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

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
 * в админке. Сам сигнал — пометка на разговоре и бейдж «Диалогов»; доставку
 * сигнала людям сервис ставит в очередь и никогда не ждёт: почта и пуш
 * падают, а разговор от этого потеряться не должен.
 *
 * Сотрудник приходит сюда номером и именем, а не моделью пользователя:
 * кто в магазине сотрудник, решает магазин, а сервисы чата моделей магазина
 * не знают (сторож — tests/Unit/AiServicesSeamTest.php).
 */
final class ChatEscalationService
{
    public function __construct(
        private readonly ChatConversationService $chat,
        private readonly OperatorPresence $presence,
        private readonly int $notifyCooldownMinutes,
    ) {}

    /** Бот сам позвал человека инструментом escalate_to_operator. */
    public const TRIGGER_BOT = 'bot';

    /** Бот не смог ответить: упал шлюз, кончились шаги, умерла джоба. */
    public const TRIGGER_FAILURE = 'failure';

    /** Посетитель нажал «Позвать менеджера». */
    public const TRIGGER_VISITOR = 'visitor';

    /** Посетитель оставил контакты — заявку надо разобрать. */
    public const TRIGGER_CALLBACK = 'callback';

    /** Столько минут после последнего взгляда покупатель считается «в чате». */
    private const VISITOR_PRESENT_MINUTES = 3;

    /** Не чаще одного письма «менеджер ответил» в час на разговор. */
    private const VISITOR_NOTIFY_COOLDOWN_SECONDS = 3600;

    /**
     * Вопрос ждёт человека.
     *
     * Идемпотентна по смыслу, а не по букве: пометка ставится один раз,
     * строка в ленте — на каждый повод (менеджер, читающий разговор задним
     * числом, должен видеть, сколько раз и почему бот сдавался), а
     * уведомление уходит не чаще раза в notify_cooldown_minutes. Посетитель
     * может задать подряд три вопроса, на которые бот не ответит, —
     * менеджеру нужен один сигнал, а не три.
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

        // Разговор уже у человека — он и так его видит.
        if ($conversation->status === ChatConversation::STATUS_OPERATOR) {
            return;
        }

        /*
         * Кулдаун не распространяется на оставленные контакты. Три вопроса
         * подряд, на которые бот не ответил, — один сигнал; а «покупатель
         * оставил почту» это уже не сигнал, а задача, и терять её из-за того,
         * что десять минут назад была эскалация, нельзя. Повториться событие
         * не может: заявка на диалог заводится одна.
         */
        if ($trigger !== self::TRIGGER_CALLBACK && ! $this->passesNotifyCooldown($conversation)) {
            return;
        }

        try {
            NotifyManagersAboutEscalationJob::dispatch(
                $conversation->id,
                $trigger,
                $reason,
                $this->presence->isOnline(),
            );
        } catch (Throwable $e) {
            // Уведомление — доставка сигнала, а не сам сигнал: диалог уже
            // помечен и виден в «Диалогах». Падать из-за очереди незачем.
            Log::warning('Escalation notification not queued', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
        }
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

        // Следующая эскалация в этом разговоре должна дойти до менеджера,
        // даже если предыдущая была пять минут назад.
        Cache::forget($this->notifyKey($conversation));

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

        Cache::forget($this->notifyKey($conversation));
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

        $this->notifyVisitor($conversation, (string) $message->body);

        return $message;
    }

    /**
     * Вернуть покупателя в разговор письмом.
     *
     * Чат на витрине живёт только в открытой вкладке: менеджер отвечает
     * через двадцать минут, а покупатель к этому времени закрыл сайт.
     * Без письма ответ есть, а разговора нет.
     *
     * Три условия, и каждое отсекает шум. Почта есть только у вошедшего
     * в аккаунт — анонима письмом не вернуть вовсе. Покупатель, который
     * прямо сейчас смотрит в чат, увидит ответ поллингом. И не чаще раза
     * в час: переписка из пяти реплик менеджера не должна превращаться
     * в пять писем.
     *
     * Саму почту ищет джоба: `App\Models\User` здесь запрещён швом.
     */
    private function notifyVisitor(ChatConversation $conversation, string $body): void
    {
        if ($conversation->user_id === null) {
            return;
        }

        if ($conversation->last_seen_at !== null
            && $conversation->last_seen_at->greaterThan(now()->subMinutes(self::VISITOR_PRESENT_MINUTES))) {
            return;
        }

        if (! Cache::add($this->visitorNotifyKey($conversation), true, self::VISITOR_NOTIFY_COOLDOWN_SECONDS)) {
            return;
        }

        try {
            NotifyVisitorAboutOperatorReplyJob::dispatch(
                $conversation->id,
                // Письмо показывает реплику текстом, значит markdown
                // в нём остался бы звёздочками и скобками.
                app(ChatMarkdown::class)->toPlainText($body),
            );
        } catch (Throwable $e) {
            Log::warning('Chat visitor notification not queued', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
        }
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

    /**
     * Первый за окно сигнал об этом разговоре проходит, остальные — нет.
     * `Cache::add()` атомарен, поэтому две джобы, эскалировавшие
     * одновременно, не дадут двух писем.
     */
    private function passesNotifyCooldown(ChatConversation $conversation): bool
    {
        if ($this->notifyCooldownMinutes <= 0) {
            return true;
        }

        return Cache::add(
            $this->notifyKey($conversation),
            true,
            $this->notifyCooldownMinutes * 60,
        );
    }

    private function notifyKey(ChatConversation $conversation): string
    {
        return 'chat:escalation:notified:'.$conversation->id;
    }

    private function visitorNotifyKey(ChatConversation $conversation): string
    {
        return 'chat:visitor-notified:'.$conversation->id;
    }
}
