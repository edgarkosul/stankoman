<?php

use App\Services\Ai\Contracts\LlmClient;
use App\Services\Ai\Contracts\ReasoningSwitch;
use App\Services\Ai\Data\ChatResult;
use App\Services\Ai\Data\EmbeddingBatch;
use App\Services\Ai\Exceptions\LlmException;
use App\Services\Ai\Exceptions\PiiBlockedException;
use App\Services\Ai\Providers\FailoverLlmClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/*
 * Запасной канал чата: основной упал — тот же вызов отвечает запасной,
 * и пока идёт пауза, основной не зовётся вовсе. Перенесено из bots.
 */
uses(TestCase::class);

beforeEach(fn () => Cache::forget(FailoverLlmClient::PAUSE_KEY));
afterEach(fn () => Cache::forget(FailoverLlmClient::PAUSE_KEY));

/** Канал-заглушка: отвечает своим именем или бросает заданное. */
function scriptedChannel(string $name, ?Throwable $failure = null): LlmClient
{
    return new class($name, $failure) implements LlmClient, ReasoningSwitch
    {
        public int $calls = 0;

        public bool $withoutReasoning = false;

        public function __construct(private readonly string $name, private readonly ?Throwable $failure) {}

        public function chat(string $system, array $messages, array $tools = [], ?int $maxTokens = null, ?string $sessionId = null, ?string $toolChoice = null): ChatResult
        {
            $this->calls++;

            if ($this->failure !== null) {
                throw $this->failure;
            }

            return new ChatResult(content: $this->name.($this->withoutReasoning ? ' без рассуждений' : ''));
        }

        public function withoutReasoning(): LlmClient
        {
            $copy = clone $this;
            $copy->withoutReasoning = true;

            return $copy;
        }

        public function embed(array $texts, string $mode = 'doc'): EmbeddingBatch
        {
            return new EmbeddingBatch([], 'emb', 4);
        }

        public function chatModel(): string
        {
            return $this->name;
        }

        public function embeddingModel(): string
        {
            return 'emb';
        }

        public function embeddingDimensions(): int
        {
            return 4;
        }
    };
}

function failoverAsk(LlmClient $client): string
{
    return $client->chat('system', [['role' => 'user', 'content' => 'привет']])->content;
}

it('основной ответил — запасной не нужен', function (): void {
    $primary = scriptedChannel('deepseek');
    $reserve = scriptedChannel('aitunnel');

    expect(failoverAsk(new FailoverLlmClient($primary, $reserve, 120)))->toBe('deepseek')
        ->and($reserve->calls)->toBe(0)
        ->and(Cache::has(FailoverLlmClient::PAUSE_KEY))->toBeFalse();
});

it('основной упал — отвечает запасной, основной на паузе', function (): void {
    Log::spy();
    $primary = scriptedChannel('deepseek', new LlmException('Шлюз ответил HTTP 402', status: 402));
    $reserve = scriptedChannel('aitunnel');
    $client = new FailoverLlmClient($primary, $reserve, 120);

    expect(failoverAsk($client))->toBe('aitunnel')
        ->and(failoverAsk($client))->toBe('aitunnel')
        // Второй вопрос — сразу в запасной: пауза.
        ->and($primary->calls)->toBe(1)
        ->and($reserve->calls)->toBe(2);

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $message === 'LLM failover'
        && $context['from'] === 'deepseek' && $context['to'] === 'aitunnel' && $context['status'] === 402);
});

it('пауза кончилась — снова основной', function (): void {
    $primary = scriptedChannel('deepseek', new LlmException('timeout'));
    $client = new FailoverLlmClient($primary, scriptedChannel('aitunnel'), 120);

    failoverAsk($client);
    Cache::forget(FailoverLlmClient::PAUSE_KEY);
    failoverAsk($client);

    expect($primary->calls)->toBe(2);
});

it('без паузы основной зовётся на каждый вызов', function (): void {
    $primary = scriptedChannel('deepseek', new LlmException('down', status: 502));
    $client = new FailoverLlmClient($primary, scriptedChannel('aitunnel'), 0);

    failoverAsk($client);
    failoverAsk($client);

    expect($primary->calls)->toBe(2)
        ->and(Cache::has(FailoverLlmClient::PAUSE_KEY))->toBeFalse();
});

it('сбой запасного уходит наверх', function (): void {
    $client = new FailoverLlmClient(
        scriptedChannel('deepseek', new LlmException('down', status: 502)),
        scriptedChannel('aitunnel', new LlmException('тоже down', status: 503)),
        120,
    );

    expect(fn () => failoverAsk($client))->toThrow(LlmException::class, 'тоже down');
});

it('персональные данные — не повод менять канал', function (): void {
    $reserve = scriptedChannel('aitunnel');
    $client = new FailoverLlmClient(scriptedChannel('deepseek', new PiiBlockedException('pii', ['phone'])), $reserve, 120);

    expect(fn () => failoverAsk($client))->toThrow(PiiBlockedException::class)
        ->and($reserve->calls)->toBe(0)
        ->and(Cache::has(FailoverLlmClient::PAUSE_KEY))->toBeFalse();
});

it('без рассуждений — оба канала', function (): void {
    $healthy = new FailoverLlmClient(scriptedChannel('deepseek'), scriptedChannel('aitunnel'), 120);
    $broken = new FailoverLlmClient(scriptedChannel('deepseek', new LlmException('down')), scriptedChannel('aitunnel'), 0);

    expect(failoverAsk($healthy->withoutReasoning()))->toBe('deepseek без рассуждений')
        ->and(failoverAsk($broken->withoutReasoning()))->toBe('aitunnel без рассуждений')
        ->and($healthy->chatModel())->toBe('deepseek');
});
