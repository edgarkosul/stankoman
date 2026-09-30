<?php

namespace App\Services\Messengers;

use App\Jobs\DeliverMaxMessageJob;
use App\Models\MessengerChannel;
use Closure;

/**
 * Разослать сообщение по чатам MAX, подписанным на тему.
 *
 * Каждому чату своя джоба: если один чат недоступен, остальные это не задерживает,
 * и повторы у каждого свои. Текст можно передать замыканием: тогда он
 * строится, только если есть кому отправлять, и лишних запросов за
 * товаром или покупателем не будет.
 *
 * Уведомление в MAX — всегда best-effort. Письмо менеджерам уходит
 * своим путём и от этого класса не зависит.
 */
final class MessengerNotifier
{
    public function __construct(private readonly MaxClient $max) {}

    public function configured(): bool
    {
        return $this->max->configured();
    }

    /** Есть ли хоть один чат, куда уйдёт сообщение на эту тему. */
    public function hasRecipients(string $topic): bool
    {
        return $this->configured() && MessengerChannel::query()->forTopic($topic)->exists();
    }

    /**
     * @param  string|Closure(): string  $text
     * @return int сколько чатов получат сообщение
     */
    public function notify(string $topic, string|Closure $text): int
    {
        if (! $this->configured()) {
            return 0;
        }

        $channels = MessengerChannel::query()->forTopic($topic)->pluck('id');

        if ($channels->isEmpty()) {
            return 0;
        }

        $text = $text instanceof Closure ? $text() : $text;

        foreach ($channels as $id) {
            DeliverMaxMessageJob::dispatch((int) $id, $text)->afterCommit();
        }

        return $channels->count();
    }
}
