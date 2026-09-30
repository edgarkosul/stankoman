<?php

namespace App\Services\Messengers;

use App\Models\MessengerChannel;
use App\Services\Notifications\Contracts\EscalationNotifier;
use Throwable;

/**
 * Уведомление о вопросе из чата уходит в чаты MAX, которые на эти вопросы подписаны.
 *
 * Джоба эскалации сама решает, когда слать (вне смены откладывает до
 * утра), а этот класс только раскладывает сообщение по чатам.
 */
final class MaxChannelsNotifier implements EscalationNotifier
{
    public function __construct(private readonly MessengerNotifier $messengers) {}

    public function isConfigured(): bool
    {
        return $this->messengers->hasRecipients(MessengerChannel::TOPIC_CHAT);
    }

    public function send(string $text, ?string $url = null): bool
    {
        try {
            return $this->messengers->notify(
                MessengerChannel::TOPIC_CHAT,
                $url === null ? $text : $text."\n".$url,
            ) > 0;
        } catch (Throwable $e) {
            // Контракт — не бросать: сбой очереди не должен ронять джобу,
            // из-за которой не уйдёт письмо.
            report($e);

            return false;
        }
    }
}
