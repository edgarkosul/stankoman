<?php

use App\Services\Ai\Exceptions\LlmException;
use App\Services\Ai\Providers\AitunnelLlmClient;
use GuzzleHttp\Exception\ConnectException as GuzzleConnectException;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/*
 * Дубль запроса вектора вопроса — AitunnelLlmClient::sendHedged().
 *
 * Гонка проверяется на живом сервере (`php -S` с роутером
 * tests/Fixtures/slow-gateway.php): весь смысл дубля — в настоящем цикле
 * curl, где два запроса идут одновременно, а фейк Laravel ждёт ответ
 * синхронно, прямо при отправке, и зависнуть не даёт. Остальное — фейком.
 */
uses(TestCase::class);

/**
 * Живой медленный шлюз: адрес и папка его счётчиков. Поднимается один раз
 * на процесс и гасится вместе с ним.
 *
 * @return array{url: string, dir: string}
 */
function slowGateway(): array
{
    static $gateway = null;

    if ($gateway !== null) {
        return $gateway;
    }

    $dir = sys_get_temp_dir().'/slow-gateway-'.getmypid();
    @mkdir($dir);

    for ($try = 0; $try < 5; $try++) {
        $port = random_int(20000, 60000);
        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:'.$port, __DIR__.'/../Fixtures/slow-gateway.php'],
            [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $pipes,
            null,
            // Воркеры: висящий первый запрос не должен держать второй.
            ['PHP_CLI_SERVER_WORKERS' => '4', 'SLOW_GATEWAY_DIR' => $dir],
        );

        for ($wait = 0; $wait < 50; $wait++) {
            if ($socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1)) {
                fclose($socket);
                register_shutdown_function(static function () use ($process, $dir): void {
                    proc_terminate($process);
                    array_map('unlink', glob($dir.'/*') ?: []);
                    @rmdir($dir);
                });

                return $gateway = ['url' => 'http://127.0.0.1:'.$port.'/v1', 'dir' => $dir];
            }

            if (! proc_get_status($process)['running']) {
                break;
            }

            usleep(20_000);
        }

        proc_terminate($process);
    }

    throw new RuntimeException('Не поднялся тестовый шлюз.');
}

function hedgedClient(int $hedgeMs = 50, int $queryTimeout = 5, int $retries = 1, string $baseUrl = 'https://api.example.test/v1'): AitunnelLlmClient
{
    return new AitunnelLlmClient(
        baseUrl: $baseUrl,
        apiKey: 'test-key',
        chatModel: 'test-model',
        embeddingModel: 'test-embedding',
        embeddingDimensions: 4,
        embeddingBatchSize: 1,
        queryCacheTtl: 0,
        timeout: 120,
        connectTimeout: 1,
        maxRetries: 1,
        connectRetries: 3,
        sessionAffinity: false,
        queryEmbeddingTimeout: $queryTimeout,
        queryEmbeddingRetries: $retries,
        queryEmbeddingHedgeMs: $hedgeMs,
    );
}

function hedgedEmbedding(float $value = 0.1): PromiseInterface
{
    return Http::response([
        'data' => [['index' => 0, 'embedding' => [$value, 0.2, 0.3, 0.4]]],
        'usage' => ['prompt_tokens' => 3],
    ]);
}

/** Клиент живого медленного шлюза. Прокси дева к 127.0.0.1 не пускаем. */
function slowGatewayClient(int $hedgeMs, int $queryTimeout = 5, int $retries = 1): AitunnelLlmClient
{
    $url = slowGateway()['url'];

    // На деве в окружении стоит прокси: тестовому шлюзу мимо него.
    foreach (['no_proxy', 'NO_PROXY'] as $name) {
        $value = (string) getenv($name);

        if (! str_contains($value, '127.0.0.1')) {
            putenv($name.'='.ltrim($value.',127.0.0.1', ','));
        }
    }

    return hedgedClient($hedgeMs, $queryTimeout, $retries, $url);
}

it('первый ответил до порога — второй запрос не уходит', function (): void {
    $label = uniqid('fast');

    $batch = slowGatewayClient(hedgeMs: 1000)->embed(['fast:'.$label], 'query');

    expect($batch->vectors[0][0])->toBe(0.1)
        ->and((int) file_get_contents(slowGateway()['dir'].'/'.md5($label)))->toBe(1);
});

it('первый завис — вектор приходит от второго, первого не ждёт', function (): void {
    // Ради этого всё и сделано: зависший запрос висит до таймаута,
    // а такой же, отправленный рядом, отвечает сразу.
    Log::spy();

    $started = microtime(true);
    $batch = slowGatewayClient(hedgeMs: 100)->embed(['hang-first:'.uniqid()], 'query');

    // Первый висит 3 с: ответ раньше — значит, его не ждали.
    expect($batch->vectors[0][0])->toBe(0.2)
        ->and(microtime(true) - $started)->toBeLessThan(1.5);

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Aitunnel hedge' && $context['winner'] === 'second')
        ->once();
});

it('первый упал сразу — второго не ждёт, повторяет как обычно', function (): void {
    // Отказ соединения чинится немедленным повтором (post()), и ждать
    // порога дубля здесь значило бы подарить посетителю лишние секунды.
    $attempts = 0;

    Http::fake(function () use (&$attempts): PromiseInterface {
        $attempts++;

        if ($attempts === 1) {
            throw new GuzzleConnectException(
                'cURL error 28: Connection timed out',
                new Request('POST', 'https://api.example.test/v1/embeddings'),
                null,
                ['connect_time' => 0.0, 'appconnect_time' => 0.0],
            );
        }

        return hedgedEmbedding(0.5);
    });

    $started = microtime(true);
    $batch = hedgedClient(hedgeMs: 3000)->embed(['сколько стоит доставка'], 'query');

    expect($batch->vectors[0][0])->toBe(0.5)
        ->and($attempts)->toBe(2)
        ->and(microtime(true) - $started)->toBeLessThan(1.0);
});

it('не ответил ни один из двух — сбой к сроку попытки, а не позже', function (): void {
    // Второй живёт до срока первого: потолок попытки с дублем не растёт.
    // Срок — 2 с; второй уходит на первой секунде, и со своим полным
    // таймаутом сдался бы только к третьей.
    $started = microtime(true);

    expect(fn () => slowGatewayClient(hedgeMs: 1000, queryTimeout: 2, retries: 0)->embed(['hang-all:'.uniqid()], 'query'))
        ->toThrow(LlmException::class);

    expect(microtime(true) - $started)->toBeGreaterThan(1.9)->toBeLessThan(2.7);
});

it('документы и модель не дублирует', function (): void {
    // Индексация идёт пачками и не ждёт посетителя, а дубль вызова модели —
    // двойная оплата и риск двух ответов.
    $attempts = 0;

    Http::fake(function ($request) use (&$attempts): PromiseInterface {
        $attempts++;

        return str_ends_with((string) $request->url(), '/embeddings')
            ? hedgedEmbedding()
            : Http::response([
                'choices' => [['message' => ['content' => 'ок'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 1],
            ]);
    });

    $client = hedgedClient(hedgeMs: 1);
    $client->embed(['статья'], 'doc');
    $client->chat('system', [['role' => 'user', 'content' => 'привет']]);

    expect($attempts)->toBe(2);
});
