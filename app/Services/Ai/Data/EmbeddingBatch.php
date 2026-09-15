<?php

namespace App\Services\Ai\Data;

/**
 * Результат одного вызова /embeddings: векторы плюс то, что нужно для учёта
 * денег и для проверки, что индекс и запрос считались одной и той же моделью.
 */
final readonly class EmbeddingBatch
{
    /**
     * @param  list<list<float>>  $vectors  в порядке переданных текстов
     */
    public function __construct(
        public array $vectors,
        public string $model,
        public int $dimensions,
        public int $promptTokens = 0,
        public float $costRub = 0.0,
    ) {}

    /** @return list<float> */
    public function first(): array
    {
        return $this->vectors[0] ?? [];
    }

    public function count(): int
    {
        return count($this->vectors);
    }
}
