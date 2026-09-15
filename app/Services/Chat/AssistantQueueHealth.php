<?php

namespace App\Services\Chat;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Разгребается ли очередь ответов.
 *
 * Нужен из-за дыры, которую видно только на живой аварии: если воркер
 * не поднят, задача уходит в Redis и лежит там молча. Посетитель при этом
 * ТРИ С ПОЛОВИНОЙ МИНУТЫ смотрит на «печатает», потому что панель сдаётся
 * только на 210-й секунде — сроке, рассчитанном на самый долгий нормальный
 * ответ, а не на аварию.
 *
 * Судим по двум признакам сразу, и ни один из них поодиночке не годится.
 *
 * Размер очереди: если задач накопилось больше потолка, воркер заведомо
 * не успевает — неважно, жив он или нет. Но на первой же задаче размер
 * равен нулю, и мёртвого воркера так не поймать.
 *
 * Отметка живости: воркер ставит её, когда берёт задачу. Устарела —
 * значит, никто не берёт. Но сама по себе она врёт на тихой ночи: задач
 * не было, отметка старая, а воркер здоров. Поэтому смотрим на неё, только
 * когда в очереди уже кто-то ждёт.
 *
 * Отсюда честное ограничение: ПЕРВЫЙ посетитель после падения воркера
 * всё равно отстоит свои 210 секунд — в момент его вопроса очередь пуста
 * и судить не по чему. Ловим всех, кто за ним.
 */
final class AssistantQueueHealth
{
    private const SEEN_KEY = 'chat:worker:seen';

    public function __construct(
        private readonly string $connection,
        private readonly string $queue,
        private readonly int $backlogLimit,
        private readonly int $silenceSeconds,
    ) {}

    /**
     * Воркер взял задачу.
     *
     * Именно взял, а не доделал: нас интересует, работает ли он вообще.
     * Задача, упавшая на шлюзе, — это здоровый воркер и больная модель,
     * и путать их нельзя.
     */
    public function heartbeat(): void
    {
        Cache::put(self::SEEN_KEY, time(), 86400);
    }

    public function isStuck(): bool
    {
        $backlog = $this->backlog();

        // Очередь пуста — судить не по чему. Не мешаем работать.
        if ($backlog === 0) {
            return false;
        }

        if ($backlog >= $this->backlogLimit) {
            return true;
        }

        $seen = Cache::get(self::SEEN_KEY);

        // Ни одной задачи ещё не отработано — например, сразу после выката.
        // Отказывать на этом основании нельзя: воркер может быть здоров
        // и как раз сейчас поднимается.
        if (! is_numeric($seen)) {
            return false;
        }

        return (time() - (int) $seen) > $this->silenceSeconds;
    }

    private function backlog(): int
    {
        try {
            return Queue::connection($this->connection)->size($this->queue);
        } catch (Throwable) {
            // Не смогли спросить Redis — это отдельная авария, и её ловит
            // не эта проверка, а исключение на dispatch(). Здесь молчим,
            // чтобы не запретить чат из-за собственной диагностики.
            return 0;
        }
    }
}
