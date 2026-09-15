<?php

namespace App\Services\Chat;

/**
 * Что делать с сообщением, которое посетитель только что отправил.
 *
 * Исходов четыре, и различать их обязательно: они отличаются не текстом
 * ошибки, а тем, что происходит с вопросом дальше.
 *
 *   ALLOW   — принимаем и отвечаем.
 *   SILENT  — не принимаем и НИЧЕГО не говорим. Так отвечают роботу:
 *             объяснять ему нечего, а любое сообщение об ошибке — это
 *             подсказка, как обойти проверку.
 *   REJECT  — не принимаем и объясняем человеку. Вопрос он задаст снова.
 *   BUDGET  — принимаем вопрос, но отвечает уже не бот: дневной бюджет
 *             магазина исчерпан, и разговор уходит менеджеру. Это не отказ
 *             посетителю, а смена собеседника, поэтому исход отдельный.
 */
final readonly class ChatAbuseVerdict
{
    public const ALLOW = 'allow';

    public const SILENT = 'silent';

    public const REJECT = 'reject';

    public const BUDGET = 'budget';

    private function __construct(
        public string $outcome,
        /** Текст для посетителя. Только у REJECT. */
        public ?string $message = null,
        /** Служебная причина — для лога и для уведомления менеджеру. */
        public string $reason = '',
    ) {}

    public static function allow(): self
    {
        return new self(self::ALLOW);
    }

    public static function silent(string $reason): self
    {
        return new self(self::SILENT, reason: $reason);
    }

    public static function reject(string $message, string $reason): self
    {
        return new self(self::REJECT, $message, $reason);
    }

    public static function budget(string $reason): self
    {
        return new self(self::BUDGET, reason: $reason);
    }

    public function allowed(): bool
    {
        return $this->outcome === self::ALLOW;
    }

    /** Молча выйти: ни сообщения, ни записи в ленте. */
    public function isSilent(): bool
    {
        return $this->outcome === self::SILENT;
    }

    public function isBudget(): bool
    {
        return $this->outcome === self::BUDGET;
    }
}
