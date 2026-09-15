<?php

namespace App\Services\Kb\Data;

/** Итоги переиндексации. Отдельный тип, потому что их показывают и в CLI, и админу. */
final readonly class KbIndexStats
{
    public function __construct(
        public int $documents = 0,
        public int $chunks = 0,
        /** Сколько фрагментов реально ушло на эмбеддинг — за это платим. */
        public int $embedded = 0,
        /** Сколько переиспользовали по совпадению content_hash. */
        public int $reused = 0,
        public int $deleted = 0,
        public int $promptTokens = 0,
        public float $costRub = 0.0,
    ) {}

    public function plus(self $other): self
    {
        return new self(
            documents: $this->documents + $other->documents,
            chunks: $this->chunks + $other->chunks,
            embedded: $this->embedded + $other->embedded,
            reused: $this->reused + $other->reused,
            deleted: $this->deleted + $other->deleted,
            promptTokens: $this->promptTokens + $other->promptTokens,
            costRub: $this->costRub + $other->costRub,
        );
    }
}
