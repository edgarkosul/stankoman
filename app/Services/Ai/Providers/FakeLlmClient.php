<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\Contracts\LlmClient;
use App\Services\Ai\Data\ChatResult;
use App\Services\Ai\Data\EmbeddingBatch;

/**
 * Заглушка без сети: вектор выводится детерминированно из хэша текста.
 *
 * Качество поиска на нём бессмысленно — близкие по смыслу тексты не окажутся
 * рядом. Но весь остальной тракт (чанкер → упаковка → вставка → выборка →
 * распаковка → сортировка по близости) прогоняется целиком, а тесты идут
 * без ключа, без денег и без интернета.
 *
 * Включается `AI_EMBEDDING_FAKE=true` — в `phpunit.xml` он стоит всегда.
 */
final class FakeLlmClient implements LlmClient
{
    public function __construct(
        private readonly int $dimensions = 1024,
        private readonly string $model = 'fake-embedding',
    ) {}

    public function chatModel(): string
    {
        return 'fake-chat';
    }

    public function embeddingModel(): string
    {
        return $this->model;
    }

    public function embeddingDimensions(): int
    {
        return $this->dimensions;
    }

    public function chat(
        string $system,
        array $messages,
        array $tools = [],
        ?int $maxTokens = null,
        ?string $sessionId = null,
        ?string $toolChoice = null,
    ): ChatResult {
        return new ChatResult(
            content: 'Заглушка: модель не вызывалась (AI_EMBEDDING_FAKE).',
            finishReason: 'stop',
        );
    }

    public function embed(array $texts, string $mode = 'doc'): EmbeddingBatch
    {
        $vectors = [];

        foreach (array_values($texts) as $text) {
            $vectors[] = $this->vectorFor($text);
        }

        return new EmbeddingBatch($vectors, $this->model, $this->dimensions);
    }

    /**
     * Одинаковый текст обязан давать одинаковый вектор — иначе тест на
     * инкрементную переиндексацию по content_hash проверял бы случайность.
     *
     * @return list<float>
     */
    private function vectorFor(string $text): array
    {
        $seed = crc32(hash('sha256', $text));
        $vector = [];
        $sum = 0.0;

        // Линейный конгруэнтный генератор, а не mt_rand: mt_srand глобален и
        // затоптал бы состояние случайности во всём процессе теста.
        $state = $seed;

        for ($i = 0; $i < $this->dimensions; $i++) {
            $state = ($state * 1103515245 + 12345) & 0x7FFFFFFF;
            $value = ($state / 0x7FFFFFFF) * 2.0 - 1.0;
            $vector[] = $value;
            $sum += $value * $value;
        }

        // Нормируем: настоящие модели отдают единичные векторы, и весь
        // дальнейший код считает косинус скалярным произведением.
        $norm = sqrt($sum) ?: 1.0;

        return array_map(static fn (float $v): float => $v / $norm, $vector);
    }
}
