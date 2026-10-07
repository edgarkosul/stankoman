<?php

use App\Services\Ai\Providers\AitunnelLlmClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

/*
 * Шлюз понимает два вида параметра; здесь — какой уходит на какую настройку.
 */

function reasoningPayload(string $reasoning, bool $switched = false): array
{
    $sent = [];

    Http::fake(function ($request) use (&$sent) {
        $sent = $request->data();

        return Http::response(['choices' => [['message' => ['content' => 'ок'], 'finish_reason' => 'stop']]]);
    });

    $client = new AitunnelLlmClient(
        baseUrl: 'https://api.example.test/v1',
        apiKey: 'test-key',
        chatModel: 'test-model',
        embeddingModel: 'test-embedding',
        embeddingDimensions: 4,
        embeddingBatchSize: 8,
        queryCacheTtl: 0,
        timeout: 5,
        connectTimeout: 1,
        maxRetries: 0,
        sessionAffinity: false,
        reasoning: $reasoning,
    );

    ($switched ? $client->withoutReasoning() : $client)->chat('system', [['role' => 'user', 'content' => 'привет']]);

    return $sent;
}

it('без настройки параметров рассуждений не шлёт', function (): void {
    expect(reasoningPayload(''))->not->toHaveKeys(['reasoning', 'reasoning_effort']);
});

it('low и minimal — reasoning_effort, off — reasoning.enabled=false', function (): void {
    expect(reasoningPayload('low'))->toMatchArray(['reasoning_effort' => 'low'])
        ->and(reasoningPayload('off'))->toMatchArray(['reasoning' => ['enabled' => false]])
        ->not->toHaveKey('reasoning_effort');
});

it('без рассуждений — копия клиента, сам клиент не меняется', function (): void {
    // Повтор пустого ответа (ShopAssistant) идёт через копию: клиент — синглтон,
    // и следующий разговор должен рассуждать, как задано настройкой.
    expect(reasoningPayload('low', switched: true))->toMatchArray(['reasoning' => ['enabled' => false]])
        ->not->toHaveKey('reasoning_effort')
        ->and(reasoningPayload('low'))->toMatchArray(['reasoning_effort' => 'low']);
});
