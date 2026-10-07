<?php

use App\Services\Ai\Exceptions\LlmException;
use App\Services\Ai\Providers\AitunnelLlmClient;
use App\Services\Ai\Support\GatewayAddressPin;
use GuzzleHttp\Exception\ConnectException as GuzzleConnectException;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/*
 * Настойчивость клиента зависит от того, ушёл ли запрос с машины, —
 * см. AitunnelLlmClient::requestLeftTheMachine(). Базы для проверки
 * не нужно, сеть подменяется фейком.
 */
uses(TestCase::class);

/**
 * Обрыв ровно в том виде, в каком его приносит Guzzle: cURL 28 и в обоих
 * случаях один и тот же класс исключения. Различает их только контекст
 * обработчика, поэтому именно он здесь и подставляется.
 */
function gatewayConnectException(float $appconnectTime): GuzzleConnectException
{
    return new GuzzleConnectException(
        'cURL error 28: SSL connection timeout',
        new Request('POST', 'https://api.example.test/v1/chat/completions'),
        null,
        [
            'errno' => 28,
            'primary_ip' => '104.21.64.27',
            'connect_time' => $appconnectTime > 0.0 ? 0.02 : 0.0,
            'appconnect_time' => $appconnectTime,
        ],
    );
}

function gatewayClient(
    int $maxRetries = 1,
    int $connectRetries = 3,
    ?GatewayAddressPin $pin = null,
    int $queryEmbeddingRetries = 1,
    int $queryEmbeddingPause = 0,
): AitunnelLlmClient {
    return new AitunnelLlmClient(
        baseUrl: 'https://api.example.test/v1',
        apiKey: 'test-key',
        chatModel: 'test-model',
        embeddingModel: 'test-embedding',
        embeddingDimensions: 4,
        embeddingBatchSize: 1,
        queryCacheTtl: 0,
        timeout: 120,
        connectTimeout: 1,
        maxRetries: $maxRetries,
        connectRetries: $connectRetries,
        sessionAffinity: false,
        pin: $pin,
        queryEmbeddingTimeout: 15,
        queryEmbeddingRetries: $queryEmbeddingRetries,
        queryEmbeddingPause: $queryEmbeddingPause,
    );
}

function gatewayEmbedding(): PromiseInterface
{
    return Http::response([
        'data' => [['index' => 0, 'embedding' => [0.1, 0.2, 0.3, 0.4]]],
        'usage' => ['prompt_tokens' => 3],
    ]);
}

it('повторяет настойчиво, когда соединение не поднялось', function (): void {
    // TLS не состоялся — значит, ни один байт запроса не ушёл, модель
    // не работала и платить не за что. Повторяем столько, сколько
    // разрешает connect_retries.
    $attempts = 0;

    Http::fake(function () use (&$attempts): never {
        $attempts++;

        throw gatewayConnectException(appconnectTime: 0.0);
    });

    expect(fn () => gatewayClient(connectRetries: 3)->chat('system', [['role' => 'user', 'content' => 'привет']]))
        ->toThrow(LlmException::class);

    expect($attempts)->toBe(4);
});

it('не повторяет настойчиво, когда запрос уже ушёл', function (): void {
    // TLS поднялся, запрос отправлен, ответа нет. Модель могла отработать,
    // деньги могли списаться: здесь настойчивость оплачивается дважды,
    // поэтому остаётся общий max_retries.
    $attempts = 0;

    Http::fake(function () use (&$attempts): never {
        $attempts++;

        throw gatewayConnectException(appconnectTime: 0.12);
    });

    expect(fn () => gatewayClient(maxRetries: 1, connectRetries: 3)->chat('system', [['role' => 'user', 'content' => 'привет']]))
        ->toThrow(LlmException::class);

    expect($attempts)->toBe(2);
});

it('доходит до ответа, когда мёртвым оказался только первый адрес', function (): void {
    // Ради этого всё и сделано: DNS отдаёт несколько адресов, часть из них
    // не отвечает, и каждая новая попытка тянет жребий заново.
    $attempts = 0;

    Http::fake(function () use (&$attempts) {
        $attempts++;

        if ($attempts < 3) {
            throw gatewayConnectException(appconnectTime: 0.0);
        }

        return Http::response([
            'choices' => [['message' => ['content' => 'ок'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 1],
            'model' => 'test-model',
        ]);
    });

    $result = gatewayClient()->chat('system', [['role' => 'user', 'content' => 'привет']]);

    expect($result->content)->toBe('ок')
        ->and($attempts)->toBe(3);
});

it('пишет в лог адрес, на котором сорвалось', function (): void {
    // Без адреса в логе авария выглядит как «шлюз недоступен», хотя
    // недоступен один edge из двух, а соседний отвечает за 20 мс.
    Log::spy();

    Http::fake(function (): never {
        throw gatewayConnectException(appconnectTime: 0.0);
    });

    try {
        gatewayClient(connectRetries: 1)->chat('system', [['role' => 'user', 'content' => 'привет']]);
    } catch (LlmException) {
        // Интересует запись в логе, а не исключение.
    }

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Aitunnel connect retry'
            && $context['ip'] === '104.21.64.27'
            && $context['sent'] === false);
});

it('идёт на адрес из пина и снимает пин, когда соединение по нему не поднялось', function (): void {
    // Прокси в окружении гасит пин (GatewayAddressPin::proxy), а на деве он
    // стоит в шелле: убираем на время теста, иначе пин молча выключен.
    $saved = [];

    foreach (['https_proxy', 'HTTPS_PROXY', 'all_proxy', 'ALL_PROXY'] as $name) {
        $saved[$name] = getenv($name);
        putenv($name);
    }

    Cache::flush();

    try {
        $pin = new GatewayAddressPin('https://api.example.test/v1', '/public/models', 3, 1800, true);
        $pin->remember('192.0.2.1');

        $resolves = [];

        Http::fake(function ($request, array $options) use (&$resolves) {
            $resolves[] = $options['curl'][CURLOPT_RESOLVE] ?? null;

            if (count($resolves) === 1) {
                throw gatewayConnectException(appconnectTime: 0.0);
            }

            return Http::response(['choices' => [['message' => ['content' => 'ок'], 'finish_reason' => 'stop']]]);
        });

        $result = gatewayClient(pin: $pin)->chat('system', [['role' => 'user', 'content' => 'привет']]);

        // Первая попытка — по пину, вторая — уже по DNS: держаться за мёртвый
        // адрес хуже, чем жребий.
        expect($result->content)->toBe('ок')
            ->and($resolves)->toBe([['api.example.test:443:192.0.2.1'], null])
            ->and($pin->current())->toBeNull();
    } finally {
        foreach ($saved as $name => $value) {
            is_string($value) ? putenv("{$name}={$value}") : putenv($name);
        }
    }
});

it('вектор вопроса ждёт недолго, а модель — сколько нужно', function (): void {
    // Обычно шлюз отдаёт вектор за полсекунды, но бывает, что держит его
    // полминуты, — всё это время посетитель смотрит на «печатает».
    $timeouts = [];

    Http::fake(function ($request, array $options) use (&$timeouts) {
        $timeouts[basename($request->url())] = $options['timeout'] ?? null;

        return str_ends_with($request->url(), '/embeddings')
            ? gatewayEmbedding()
            : Http::response(['choices' => [['message' => ['content' => 'ок'], 'finish_reason' => 'stop']]]);
    });

    $client = gatewayClient();
    $client->embed(['как оплатить заказ'], mode: 'query');
    $queryTimeout = $timeouts['embeddings'];

    // Индексация идёт пачками — ей короткий таймаут не годится.
    $client->embed(['статья о доставке'], mode: 'doc');
    $client->chat('system', [['role' => 'user', 'content' => 'привет']]);

    expect($queryTimeout)->toBe(15)
        ->and($timeouts['embeddings'])->toBe(120)
        ->and($timeouts['completions'])->toBe(120);
});

it('вектор вопроса после потерянного ответа повторяет по своим правилам', function (): void {
    // Запрос ушёл, ответа нет. У модели повтор — риск двойной оплаты,
    // у эмбеддинга — доли копейки и никакого дубля в ленте.
    $attempts = 0;

    Http::fake(function () use (&$attempts) {
        $attempts++;

        if ($attempts <= 2) {
            throw gatewayConnectException(appconnectTime: 0.12);
        }

        return gatewayEmbedding();
    });

    $vector = gatewayClient(maxRetries: 0, queryEmbeddingRetries: 2)->embed(['как оплатить заказ'], mode: 'query')->first();

    expect($vector)->toBe([0.1, 0.2, 0.3, 0.4])
        ->and($attempts)->toBe(3);
});

it('индексация документов повторяет по общим правилам', function (): void {
    $attempts = 0;

    Http::fake(function () use (&$attempts): never {
        $attempts++;

        throw gatewayConnectException(appconnectTime: 0.12);
    });

    expect(fn () => gatewayClient(maxRetries: 1, queryEmbeddingRetries: 5)->embed(['статья о доставке'], mode: 'doc'))
        ->toThrow(LlmException::class);

    expect($attempts)->toBe(2);
});

it('после сбоя вектор вопроса не ждёт зависший шлюз снова', function (): void {
    // bots, 02.10.2026: /embeddings висел полчаса, ход с тремя поисками шёл
    // 142 с — каждый поиск заново ждал две попытки по 15 с.
    Cache::flush();
    $attempts = 0;

    Http::fake(function () use (&$attempts) {
        $attempts++;

        if ($attempts <= 2) {
            throw gatewayConnectException(appconnectTime: 0.12);
        }

        return gatewayEmbedding();
    });

    $client = gatewayClient(queryEmbeddingRetries: 1, queryEmbeddingPause: 120);

    expect(fn () => $client->embed(['как оплатить заказ'], mode: 'query'))->toThrow(LlmException::class);
    expect(fn () => $client->embed(['сроки доставки'], mode: 'query'))->toThrow(LlmException::class, 'недавно не ответил');

    // Второй вопрос на шлюз не ходил; индексация паузы не знает.
    expect($attempts)->toBe(2)
        ->and($client->embed(['статья о доставке'], mode: 'doc')->first())->toBe([0.1, 0.2, 0.3, 0.4])
        ->and($attempts)->toBe(3);

    // Пауза истекла — шлюз зовём снова.
    Cache::forget(AitunnelLlmClient::QUERY_PAUSE_KEY);

    expect($client->embed(['сроки доставки'], mode: 'query')->first())->toBe([0.1, 0.2, 0.3, 0.4]);
});
