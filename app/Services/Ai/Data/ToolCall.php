<?php

namespace App\Services\Ai\Data;

/** Запрос модели на вызов инструмента. */
final readonly class ToolCall
{
    /**
     * @param  array<string, mixed>  $arguments
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $arguments,
    ) {}

    /**
     * Аргументы приходят строкой JSON, и модель иногда присылает её битой.
     * Это штатный исход, а не исключительная ситуация: агент должен ответить
     * моделью же — «аргументы не разобраны, повтори», — а не падать.
     *
     * @param  array<string, mixed>  $raw
     */
    public static function fromArray(array $raw): self
    {
        $decoded = json_decode((string) ($raw['function']['arguments'] ?? '{}'), true);

        return new self(
            id: (string) ($raw['id'] ?? ''),
            name: (string) ($raw['function']['name'] ?? ''),
            arguments: is_array($decoded) ? $decoded : [],
        );
    }
}
