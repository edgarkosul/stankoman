<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\Contracts\LlmClient;
use App\Services\Ai\Contracts\ReasoningSwitch;
use App\Services\Ai\Data\ChatResult;
use App\Services\Ai\Data\EmbeddingBatch;
use App\Services\Ai\Exceptions\LlmException;
use App\Services\Ai\Exceptions\PiiBlockedException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Чат основным каналом, при сбое — запасным (`ai_support.agent.reserve`).
 *
 * Основной — сам DeepSeek, запасной — aitunnel: это разные пути до одной
 * модели, и падают они по разным причинам. aitunnel дважды отрезала
 * фильтрация адресов Cloudflare (17.09 и 07.10.2026), а у DeepSeek свои
 * беды — кончился баланс (402), очередь в часы пик.
 *
 * Любой сбой основного — сеть, таймаут, 429, 5xx, 401, 402, даже 400 — и тот
 * же вызов уходит в запасной: посетителю нужен ответ, а не «передал
 * менеджеру». Цена — изредка двойная оплата вызова, ответ на который
 * потерялся в пути (повторы самого клиента такого избегают, AitunnelLlmClient).
 *
 * После сбоя основной на `reserve_pause` секунд не зовётся вовсе, иначе
 * каждый посетитель ждал бы его таймаут заново: тот же приём, что пауза
 * вектора вопроса (AitunnelLlmClient::QUERY_PAUSE_KEY).
 *
 * Шаги одного ответа могут идти разными каналами: история в формате OpenAI,
 * модель та же.
 *
 * Эмбеддингов не делает — в контейнере его оборачивает SplitLlmClient.
 * Перенесено из bots (6c62f89).
 */
final class FailoverLlmClient implements LlmClient, ReasoningSwitch
{
    public const PAUSE_KEY = 'ai:chat:primary-pause';

    public function __construct(
        private readonly LlmClient $primary,
        private readonly LlmClient $reserve,
        /** Сколько секунд после сбоя не звать основной. 0 — звать всегда. */
        private readonly int $pause,
    ) {}

    public function chat(
        string $system,
        array $messages,
        array $tools = [],
        ?int $maxTokens = null,
        ?string $sessionId = null,
        ?string $toolChoice = null,
    ): ChatResult {
        if (! Cache::has(self::PAUSE_KEY)) {
            try {
                return $this->primary->chat($system, $messages, $tools, $maxTokens, $sessionId, $toolChoice);
            } catch (PiiBlockedException $e) {
                // Персональные данные — это про текст, а не про канал: решает ShopAssistant.
                throw $e;
            } catch (LlmException $e) {
                if ($this->pause > 0) {
                    Cache::put(self::PAUSE_KEY, true, $this->pause);
                }

                Log::warning('LLM failover', [
                    'from' => self::name($this->primary),
                    'to' => self::name($this->reserve),
                    'status' => $e->status,
                    'pause' => $this->pause,
                    'error' => mb_substr($e->getMessage(), 0, 300),
                ]);
            }
        }

        return $this->reserve->chat($system, $messages, $tools, $maxTokens, $sessionId, $toolChoice);
    }

    public function withoutReasoning(): LlmClient
    {
        return new self(self::withoutReasoningOf($this->primary), self::withoutReasoningOf($this->reserve), $this->pause);
    }

    public function chatModel(): string
    {
        return $this->primary->chatModel();
    }

    public function embed(array $texts, string $mode = 'doc'): EmbeddingBatch
    {
        return $this->primary->embed($texts, $mode);
    }

    public function embeddingModel(): string
    {
        return $this->primary->embeddingModel();
    }

    public function embeddingDimensions(): int
    {
        return $this->primary->embeddingDimensions();
    }

    private static function withoutReasoningOf(LlmClient $client): LlmClient
    {
        return $client instanceof ReasoningSwitch ? $client->withoutReasoning() : $client;
    }

    private static function name(LlmClient $client): string
    {
        return $client instanceof AitunnelLlmClient ? $client->channel() : $client->chatModel();
    }
}
