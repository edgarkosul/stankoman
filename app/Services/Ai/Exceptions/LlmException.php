<?php

namespace App\Services\Ai\Exceptions;

use RuntimeException;
use Throwable;

/** Шлюз не ответил или ответил не тем. Для посетителя это мягкая эскалация. */
class LlmException extends RuntimeException
{
    public function __construct(
        string $message = '',
        ?Throwable $previous = null,
        /**
         * HTTP-код ответа; null — ответа не было вовсе. Нужен строке
         * `LLM failover`: 400 там значит «запрос не тот» (например, история
         * без reasoning_content), 402 — кончился баланс DeepSeek.
         */
        public readonly ?int $status = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
