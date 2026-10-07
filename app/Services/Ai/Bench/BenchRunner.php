<?php

namespace App\Services\Ai\Bench;

use App\Services\Ai\Contracts\LlmClient;
use App\Services\Ai\Providers\AitunnelLlmClient;
use App\Services\Ai\ShopAssistant;
use App\Services\Ai\Support\GatewayAddressPin;
use App\Services\Ai\Support\OfferedLinkGuard;
use App\Services\Ai\Support\PiiRedactor;
use App\Services\Ai\Support\ProductLinkGuard;
use App\Services\Ai\Support\ReplyFormatter;
use App\Services\Ai\SystemPromptBuilder;
use App\Services\Ai\Tools\AssistantTool;
use Closure;

/**
 * Прогон набора вопросов через несколько вариантов ВПЕРЕМЕШКУ.
 *
 * Агента собирает сам, а не берёт из контейнера: смысл замера в том, чтобы
 * прогнать один и тот же набор через РАЗНЫЕ модели и провайдеров, а
 * привязанный к конфигу экземпляр этого не позволяет.
 *
 * Вперемешку — потому что шлюз плавает в разы за день: варианты, прогнанные
 * в разные часы, сравнивают часы, а не варианты (bots, 07.10.2026). Каждый
 * вопрос идёт через все варианты подряд, а очерёдность сдвигается на шаг
 * с каждым вопросом и прогоном: первым идёт то один, то другой.
 */
final class BenchRunner
{
    /**
     * @param  list<AssistantTool>  $tools
     * @param  (Closure(BenchVariant): LlmClient)|null  $clients  клиент варианта;
     *                                                            по умолчанию — шлюз из конфига
     */
    public function __construct(
        private readonly SystemPromptBuilder $prompts,
        private readonly PiiRedactor $redactor,
        private readonly ReplyFormatter $formatter,
        private readonly array $tools,
        private readonly ?Closure $clients = null,
    ) {}

    /**
     * @param  list<BenchVariant>  $variants
     * @param  list<BenchCase>  $cases
     * @param  callable(BenchResult): void|null  $onResult
     * @return list<BenchResult>
     */
    public function run(array $variants, array $cases, int $runs, ?callable $onResult = null): array
    {
        $assistants = [];

        foreach ($variants as $variant) {
            $assistants[$variant->label] = $this->assistantFor($variant);
        }

        $labels = array_keys($assistants);
        $results = [];

        for ($run = 1; $run <= $runs; $run++) {
            foreach ($cases as $index => $case) {
                foreach (self::order($labels, $run, $index) as $position => $label) {
                    /*
                     * Своя сессия на каждый прогон каждого вопроса.
                     *
                     * Иначе кэш префикса, который в проде экономит втрое, здесь
                     * исказил бы замер: первый вопрос платил бы полную цену,
                     * остальные — четверть. В проде у каждого посетителя свой
                     * диалог, и именно это мы и воспроизводим.
                     */
                    $reply = $assistants[$label]->ask(
                        $case->question,
                        sessionId: sprintf('bench-%s-%s-%d-%s', $label, $case->id, $run, bin2hex(random_bytes(3))),
                    );

                    $result = new BenchResult(
                        variant: $label,
                        case: $case,
                        run: $run,
                        reply: $reply,
                        violations: $case->violations($reply),
                        position: $position + 1,
                    );

                    $results[] = $result;

                    if ($onResult !== null) {
                        $onResult($result);
                    }
                }
            }
        }

        // Протокол — в порядке «вариант, прогон», как читают глазами.
        usort($results, static fn (BenchResult $a, BenchResult $b): int => [array_search($a->variant, $labels, true), $a->run]
            <=> [array_search($b->variant, $labels, true), $b->run]);

        return $results;
    }

    /**
     * Очерёдность вариантов для вопроса: сдвиг на шаг с каждым вопросом и
     * прогоном. Два варианта — то A→B, то B→A; за прогон каждый первым поровну.
     *
     * @param  list<string>  $labels
     * @return list<string>
     */
    public static function order(array $labels, int $run, int $index): array
    {
        $count = count($labels);

        if ($count < 2) {
            return $labels;
        }

        $shift = ($run - 1 + $index) % $count;

        return [...array_slice($labels, $shift), ...array_slice($labels, 0, $shift)];
    }

    private function assistantFor(BenchVariant $variant): ShopAssistant
    {
        $llm = $this->clients !== null ? ($this->clients)($variant) : $this->gatewayClient($variant);

        return new ShopAssistant(
            llm: $llm,
            prompts: $this->prompts,
            redactor: $this->redactor,
            formatter: $this->formatter,
            links: new ProductLinkGuard,
            offeredLinks: new OfferedLinkGuard,
            tools: $this->tools,
            maxIterations: (int) config('ai_support.agent.max_iterations'),
            maxTokens: (int) config('ai_support.agent.max_tokens'),
        );
    }

    /** Тот же клиент, что в проде, — кроме модели и провайдера варианта. */
    private function gatewayClient(BenchVariant $variant): AitunnelLlmClient
    {
        return new AitunnelLlmClient(
            baseUrl: (string) config('ai_support.gateway.base_url'),
            apiKey: (string) config('ai_support.gateway.key'),
            chatModel: $variant->model,
            embeddingModel: (string) config('ai_support.embedding.model'),
            embeddingDimensions: (int) config('ai_support.embedding.dimensions'),
            embeddingBatchSize: (int) config('ai_support.embedding.batch_size'),
            queryCacheTtl: (int) config('ai_support.embedding.query_cache_ttl'),
            timeout: (int) config('ai_support.gateway.timeout'),
            connectTimeout: (int) config('ai_support.gateway.connect_timeout'),
            maxRetries: (int) config('ai_support.gateway.max_retries'),
            connectRetries: (int) config('ai_support.gateway.connect_retries'),
            sessionAffinity: (bool) config('ai_support.agent.session_affinity'),
            pin: app(GatewayAddressPin::class),
            queryEmbeddingTimeout: (int) config('ai_support.embedding.query_timeout'),
            queryEmbeddingRetries: (int) config('ai_support.embedding.query_retries'),
            queryEmbeddingPause: (int) config('ai_support.embedding.query_pause'),
            queryEmbeddingHedgeMs: (int) config('ai_support.embedding.query_hedge_ms'),
            reasoning: (string) config('ai_support.agent.reasoning'),
            providerSort: $variant->providerSort,
        );
    }
}
