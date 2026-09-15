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
 * Здесь пока витринная половина: пометка и строка в ленте. Уведомления
 * менеджеров о переданном вопросе, перехват оператором и возврат боту
 * живут на рабочем месте оператора и приходят вместе с ним.
 */
final class ChatEscalationService
{
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
