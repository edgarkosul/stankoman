<?php

namespace App\Services\Ai\Bench;

use App\Services\Ai\Data\AssistantReply;

/** Один прогон одного вопроса через один вариант. */
final readonly class BenchResult
{
    /**
     * @param  string  $variant  модель или «модель+sort» (BenchVariant)
     * @param  int  $position  каким по счёту вариант шёл на этом вопросе —
     *                         видно, не решает ли разницу очерёдность
     * @param  list<string>  $violations
     */
    public function __construct(
        public string $variant,
        public BenchCase $case,
        public int $run,
        public AssistantReply $reply,
        public array $violations,
        public int $position = 1,
    ) {}

    public function passed(): bool
    {
        return $this->violations === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'variant' => $this->variant,
            'model' => $this->reply->model,
            'case' => $this->case->id,
            'category' => $this->case->category,
            'run' => $this->run,
            'position' => $this->position,
            'question' => $this->case->question,
            'answer' => $this->reply->text,
            'stop_reason' => $this->reply->stopReason,
            'tool_calls' => $this->reply->toolCalls,
            'escalated' => $this->reply->escalated,
            'callback' => $this->reply->callbackRequested,
            'best_score' => $this->reply->bestScore,
            'kb_degraded' => $this->reply->kbDegraded,
            'input_tokens' => $this->reply->inputTokens,
            'output_tokens' => $this->reply->outputTokens,
            'cached_tokens' => $this->reply->cachedTokens,
            'cost_rub' => $this->reply->costRub,
            'latency_ms' => $this->reply->latencyMs,
            'violations' => $this->violations,
            'note' => $this->case->note,
        ];
    }
}
