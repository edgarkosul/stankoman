<?php

use App\Services\Ai\Providers\AitunnelLlmClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

/*
 * Объект `provider` у aitunnel: пустая настройка — поля нет вовсе, и шлюз
 * выбирает провайдера сам, как до появления настройки.
 */

function providerPayload(string $sort): array
{
    $sent = [];

    Http::fake(function ($request) use (&$sent) {
        $sent = $request->data();

        return Http::response(['choices' => [['message' => ['content' => 'ок'], 'finish_reason' => 'stop']]]);
    });

    (new AitunnelLlmClient(
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
        connectRetries: 0,
        sessionAffinity: false,
        providerSort: $sort,
    ))->chat('system', [['role' => 'user', 'content' => 'привет']]);

    return $sent;
}

it('без настройки объекта provider не шлёт', function (): void {
    expect(providerPayload(''))->not->toHaveKey('provider');
});

it('sort=latency уходит объектом provider', function (): void {
    expect(providerPayload('latency'))->toMatchArray(['provider' => ['sort' => 'latency']]);
});
