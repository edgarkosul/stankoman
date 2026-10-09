<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\Contracts\LlmClient;
use App\Services\Ai\Contracts\ReasoningSwitch;
use App\Services\Ai\Data\ChatResult;
use App\Services\Ai\Data\EmbeddingBatch;

/**
 * Чат одним клиентом, эмбеддинги — другим.
 *
 * Нужен каналу deepseek: модель отвечает через сам DeepSeek, а векторы —
 * только aitunnel. Векторы в базе знаний и в каталоге посчитаны там, а у
 * DeepSeek эмбеддингов нет вовсе; вектор вопроса от другой модели разошёлся
 * бы с ними — поиск при этом не падает, а тихо портится.
 */
final class SplitLlmClient implements LlmClient, ReasoningSwitch
{
    public function __construct(
        private readonly LlmClient $chat,
        private readonly LlmClient $embeddings,
    ) {}

    public function chat(
        string $system,
        array $messages,
        array $tools = [],
        ?int $maxTokens = null,
        ?string $sessionId = null,
        ?string $toolChoice = null,
    ): ChatResult {
        return $this->chat->chat($system, $messages, $tools, $maxTokens, $sessionId, $toolChoice);
    }

    public function embed(array $texts, string $mode = 'doc'): EmbeddingBatch
    {
        return $this->embeddings->embed($texts, $mode);
    }

    public function chatModel(): string
    {
        return $this->chat->chatModel();
    }

    public function embeddingModel(): string
    {
        return $this->embeddings->embeddingModel();
    }

    public function embeddingDimensions(): int
    {
        return $this->embeddings->embeddingDimensions();
    }

    public function withoutReasoning(): LlmClient
    {
        if (! $this->chat instanceof ReasoningSwitch) {
            return $this;
        }

        return new self($this->chat->withoutReasoning(), $this->embeddings);
    }
}
