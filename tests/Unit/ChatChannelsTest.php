<?php

use App\Services\Ai\Contracts\LlmClient;
use App\Services\Ai\Exceptions\LlmException;
use App\Services\Ai\Providers\AitunnelLlmClient;
use App\Services\Ai\Providers\ChatChannels;
use App\Services\Ai\Providers\FailoverLlmClient;
use App\Services\Ai\Providers\SplitLlmClient;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/*
 * Каналы чата: aitunnel как был, DeepSeek — сам, без посредников. Главное
 * здесь — что уходит в API: каналы получают одно и то же, отличаясь только
 * тем, что у API разное. Перенесено из bots (ChatChannelsTest).
 */
uses(TestCase::class);

beforeEach(function (): void {
    config([
        'ai_support.embedding.fake' => false,
        'ai_support.gateway.base_url' => 'https://aitunnel.test/v1',
        'ai_support.gateway.key' => 'aitunnel-key',
        'ai_support.gateway.provider_sort' => '',
        'ai_support.gateway.max_retries' => 1,
        'ai_support.gateway.connect_retries' => 0,
        'ai_support.gateway.pin.enabled' => false,
        'ai_support.agent.model' => 'deepseek-v4.1-flash',
        'ai_support.agent.reasoning' => '',
        'ai_support.agent.session_affinity' => true,
        'ai_support.agent.channel' => 'aitunnel',
        'ai_support.agent.reserve' => 'aitunnel',
        'ai_support.agent.reserve_pause' => 120,
        'ai_support.deepseek.base_url' => 'https://deepseek.test',
        'ai_support.deepseek.key' => 'deepseek-key',
        'ai_support.deepseek.model' => 'deepseek-flash',
        'ai_support.deepseek.max_retries' => 0,
        'ai_support.deepseek.connect_retries' => 0,
        'ai_support.deepseek.proxy' => '',
        'ai_support.deepseek.usd_rub' => 100,
    ]);

    Cache::forget(FailoverLlmClient::PAUSE_KEY);
});

afterEach(fn () => Cache::forget(FailoverLlmClient::PAUSE_KEY));

/**
 * Что уходит в DeepSeek на ход с инструментами.
 *
 * @return array<string, mixed>
 */
function deepseekPayload(?string $toolChoice, string $reasoning = ''): array
{
    $sent = [];

    Http::fake(function (Request $request) use (&$sent) {
        $sent = $request->data();

        return Http::response(['choices' => [['message' => ['content' => 'ок'], 'finish_reason' => 'stop']]]);
    });

    $tools = [['type' => 'function', 'function' => ['name' => 'search_knowledge_base', 'parameters' => ['type' => 'object']]]];

    app(ChatChannels::class)->deepseek(reasoning: $reasoning)
        ->chat('system', [['role' => 'user', 'content' => 'привет']], tools: $tools, toolChoice: $toolChoice);

    return $sent;
}

/**
 * Один ход через клиент; что ушло в API и куда.
 *
 * @return array{url: string, payload: array<string, mixed>, token: string, result: mixed}
 */
function channelCall(LlmClient $client, array $usage = []): array
{
    $sent = [];

    Http::fake(function (Request $request) use (&$sent, $usage) {
        $sent = ['url' => $request->url(), 'payload' => $request->data(), 'token' => $request->header('Authorization')[0] ?? ''];

        return Http::response([
            'model' => 'deepseek-flash',
            'choices' => [['message' => ['content' => 'ок'], 'finish_reason' => 'stop']],
            'usage' => $usage,
        ]);
    });

    $result = $client->chat('system', [['role' => 'user', 'content' => 'привет']], sessionId: 'conv-1');

    return [...$sent, 'result' => $result];
}

it('aitunnel по умолчанию шлёт ровно то же, что до каналов', function (): void {
    $call = channelCall(app(ChatChannels::class)->chat('aitunnel'), ['cost_rub' => 0.12]);

    expect($call['url'])->toBe('https://aitunnel.test/v1/chat/completions')
        ->and($call['token'])->toBe('Bearer aitunnel-key')
        ->and(array_keys($call['payload']))->toBe(['model', 'max_tokens', 'messages', 'session_id'])
        ->and($call['payload']['model'])->toBe('deepseek-v4.1-flash')
        ->and($call['result']->costRub)->toBe(0.12)
        ->and($call['result']->channel)->toBe('aitunnel');
});

it('aitunnel с provider.sort — из настройки или из имени канала', function (): void {
    config(['ai_support.gateway.provider_sort' => 'latency']);

    expect(channelCall(app(ChatChannels::class)->chat('aitunnel'))['payload']['provider'])->toBe(['sort' => 'latency'])
        ->and(channelCall(app(ChatChannels::class)->aitunnel(sort: ''))['payload'])->not->toHaveKey('provider');

    config(['ai_support.gateway.provider_sort' => '']);

    $call = channelCall(app(ChatChannels::class)->chat('aitunnel+throughput'));

    expect($call['payload']['provider'])->toBe(['sort' => 'throughput'])
        ->and($call['result']->channel)->toBe('aitunnel+throughput');
});

it('без ключа канал не собирается, неизвестный канал — тоже', function (): void {
    $channels = app(ChatChannels::class);

    expect(fn () => $channels->chat('openai'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $channels->chat('deepseek+latency'))->toThrow(InvalidArgumentException::class);

    config(['ai_support.deepseek.key' => '']);

    expect(fn () => $channels->deepseek())->toThrow(LlmException::class, 'AI_DEEPSEEK_KEY');
});

it('DeepSeek — своя модель и адрес, без session_id и provider', function (): void {
    config(['ai_support.gateway.provider_sort' => 'latency']);

    $call = channelCall(app(ChatChannels::class)->chat('deepseek'));

    expect($call['url'])->toBe('https://deepseek.test/chat/completions')
        ->and($call['token'])->toBe('Bearer deepseek-key')
        ->and(array_keys($call['payload']))->toBe(['model', 'max_tokens', 'messages'])
        ->and($call['payload']['model'])->toBe('deepseek-flash')
        ->and($call['result']->channel)->toBe('deepseek');
});

it('DeepSeek: рассуждения — reasoning_effort, выключение — thinking', function (): void {
    expect(deepseekPayload(null, 'low'))->toMatchArray(['reasoning_effort' => 'low'])
        // Уровня minimal у DeepSeek нет — ближайший low.
        ->and(deepseekPayload(null, 'minimal'))->toMatchArray(['reasoning_effort' => 'low'])
        ->and(deepseekPayload(null, 'off'))->toMatchArray(['thinking' => ['type' => 'disabled']])
        ->not->toHaveKeys(['reasoning', 'reasoning_effort'])
        ->and(deepseekPayload(null))->not->toHaveKeys(['thinking', 'reasoning', 'reasoning_effort']);
});

it('DeepSeek: обязательный вызов инструмента — без рассуждений, иначе 400', function (): void {
    // Наш первый шаг ответа — required (ShopAssistant::toolChoice).
    expect(deepseekPayload('required'))->toMatchArray(['tool_choice' => 'required', 'thinking' => ['type' => 'disabled']])
        ->and(deepseekPayload('search_knowledge_base', 'low'))->toMatchArray(['thinking' => ['type' => 'disabled']])
        ->not->toHaveKey('reasoning_effort')
        ->and(deepseekPayload('auto'))->not->toHaveKey('thinking')
        ->and(deepseekPayload('none', 'low'))->toMatchArray(['tool_choice' => 'none', 'reasoning_effort' => 'low'])
        ->not->toHaveKey('thinking');
});

it('DeepSeek: у вызова инструмента в истории — reasoning_content, у сделанного без рассуждений — пустой', function (): void {
    // Без поля следующий шаг с рассуждениями — 400, и ответ молча уходит
    // в запасной канал (пробы bots с srv2 08.10.2026).
    $sent = [];

    Http::fake(function (Request $request) use (&$sent) {
        $sent = $request->data();

        return Http::response(['choices' => [['message' => ['content' => 'ок', 'reasoning_content' => 'Ответ готов.'], 'finish_reason' => 'stop']]]);
    });

    $call = static fn (string $id): array => ['id' => $id, 'type' => 'function', 'function' => ['name' => 'search_knowledge_base', 'arguments' => '{}']];

    $result = app(ChatChannels::class)->deepseek()->chat('system', [
        ['role' => 'user', 'content' => 'Какая гарантия?'],
        ['role' => 'assistant', 'content' => 'Пять лет.'],
        ['role' => 'user', 'content' => 'А доставка?'],
        // Первый шаг ответа — обязательный вызов, он без рассуждений.
        ['role' => 'assistant', 'content' => '', 'tool_calls' => [$call('c1')]],
        ['role' => 'tool', 'tool_call_id' => 'c1', 'content' => '590 ₽'],
        ['role' => 'assistant', 'content' => '', 'reasoning_content' => 'Нужен ещё срок.', 'tool_calls' => [$call('c2')]],
        ['role' => 'tool', 'tool_call_id' => 'c2', 'content' => '1–2 дня'],
    ], tools: [['type' => 'function', 'function' => ['name' => 'search_knowledge_base']]], toolChoice: 'auto');

    // [0] — системный промпт.
    expect($sent['messages'][4])->toMatchArray(['reasoning_content' => ''])
        ->and($sent['messages'][6])->toMatchArray(['reasoning_content' => 'Нужен ещё срок.'])
        // Прошлый ход — простой текст, поле ему не нужно.
        ->and($sent['messages'][2])->not->toHaveKey('reasoning_content')
        // Рассуждение ответа — в результат: его вернут в историю следующего шага.
        ->and($result->reasoning)->toBe('Ответ готов.');
});

it('шлюзу reasoning_content в истории не шлём', function (): void {
    $sent = [];

    Http::fake(function (Request $request) use (&$sent) {
        $sent = $request->data();

        return Http::response(['choices' => [['message' => ['content' => 'ок', 'reasoning' => 'Думаю.'], 'finish_reason' => 'stop']]]);
    });

    $result = app(ChatChannels::class)->aitunnel()->chat('system', [
        ['role' => 'user', 'content' => 'Сколько доставка?'],
        ['role' => 'assistant', 'content' => '', 'reasoning_content' => 'Нужна цена.',
            'tool_calls' => [['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'x', 'arguments' => '{}']]]],
        ['role' => 'tool', 'tool_call_id' => 'c1', 'content' => '590 ₽'],
    ], tools: [['type' => 'function', 'function' => ['name' => 'x']]], toolChoice: 'auto');

    expect($sent['messages'][2])->not->toHaveKey('reasoning_content')
        ->and($sent['messages'][2]['tool_calls'][0]['id'])->toBe('c1')
        ->and($result->reasoning)->toBe('Думаю.');
});

it('шлюз обязательный вызов инструмента получает с рассуждениями, как раньше', function (): void {
    $sent = [];

    Http::fake(function (Request $request) use (&$sent) {
        $sent = $request->data();

        return Http::response(['choices' => [['message' => ['content' => 'ок'], 'finish_reason' => 'stop']]]);
    });

    app(ChatChannels::class)->aitunnel(reasoning: 'low')->chat('system', [['role' => 'user', 'content' => 'привет']],
        tools: [['type' => 'function', 'function' => ['name' => 'x']]], toolChoice: 'required');

    expect($sent)->toMatchArray(['reasoning_effort' => 'low', 'tool_choice' => 'required'])->not->toHaveKey('thinking');
});

it('DeepSeek: цены в ответе нет — считаем по токенам, в пик вдвое дороже', function (): void {
    $usage = ['prompt_tokens' => 2_000_000, 'prompt_cache_hit_tokens' => 1_000_000, 'prompt_cache_miss_tokens' => 1_000_000, 'completion_tokens' => 1_000_000];

    // Среда, 08:00 UTC — пик: (0.006 + 0.30 + 1.20) $ × 100 ₽. Пояс — явно:
    // у приложения Москва, а пик DeepSeek задан в UTC.
    Carbon::setTestNow(Carbon::parse('2026-10-07 08:00:00', 'UTC'));
    $peak = channelCall(app(ChatChannels::class)->deepseek(), $usage)['result'];

    // Суббота — вне пика весь день.
    Carbon::setTestNow(Carbon::parse('2026-10-10 08:00:00', 'UTC'));
    $offPeak = channelCall(app(ChatChannels::class)->deepseek(), $usage)['result'];

    Carbon::setTestNow();

    expect($peak->costRub)->toEqualWithDelta(150.6, 0.0001)
        ->and($offPeak->costRub)->toEqualWithDelta(75.3, 0.0001)
        // Кэш ввода — из полей DeepSeek, если OpenAI-шного нет.
        ->and($peak->cachedTokens)->toBe(1_000_000);
});

it('DeepSeek через прокси — пин адреса не нужен', function (): void {
    config(['ai_support.deepseek.proxy' => 'socks5h://127.0.0.1:10808']);
    $options = [];

    Http::fake(function (Request $request, array $sent) use (&$options) {
        $options = $sent;

        return Http::response(['choices' => [['message' => ['content' => 'ок'], 'finish_reason' => 'stop']]]);
    });

    app(ChatChannels::class)->deepseek()->chat('system', [['role' => 'user', 'content' => 'привет']]);

    expect($options['proxy'] ?? null)->toBe('socks5h://127.0.0.1:10808')
        ->and($options['curl'][CURLOPT_RESOLVE] ?? null)->toBeNull();
});

it('сбой несёт код ответа — для строки LLM failover', function (): void {
    Http::fake(fn () => Http::response(['error' => ['message' => 'The `reasoning_content` in the thinking mode must be passed back']], 400));

    try {
        app(ChatChannels::class)->deepseek()->chat('system', [['role' => 'user', 'content' => 'привет']]);
        $status = null;
    } catch (LlmException $e) {
        $status = $e->status;
    }

    expect($status)->toBe(400);
});

it('отказ прокси на CONNECT — такой же сбой с кодом, а не исключение мимо запасного', function (): void {
    // Guzzle отдаёт 403 прокси исключением с ответом (дев 07.10.2026). Без
    // перехвата оно проходило мимо FailoverLlmClient, который ловит LlmException.
    Http::fake(fn () => throw new RequestException(new Response(new Psr7Response(403, [], ''))));

    expect(fn () => app(ChatChannels::class)->deepseek()->chat('system', [['role' => 'user', 'content' => 'привет']]))
        ->toThrow(fn (LlmException $e) => expect($e->status)->toBe(403));
});

it('по умолчанию в контейнере — клиент aitunnel как есть', function (): void {
    expect(app(LlmClient::class))->toBeInstanceOf(AitunnelLlmClient::class)
        ->and(app(LlmClient::class)->chatModel())->toBe('deepseek-v4.1-flash');
});

it('канал deepseek: чат через DeepSeek с запасным aitunnel, эмбеддинги — через aitunnel', function (): void {
    config(['ai_support.agent.channel' => 'deepseek', 'ai_support.embedding.dimensions' => 4, 'ai_support.embedding.query_cache_ttl' => 0]);
    $urls = [];

    Http::fake(function (Request $request) use (&$urls) {
        $urls[] = $request->url();

        return match (true) {
            str_ends_with($request->url(), '/embeddings') => Http::response(['data' => [['index' => 0, 'embedding' => [0.1, 0.2, 0.3, 0.4]]]]),
            str_starts_with($request->url(), 'https://deepseek.test') && count($urls) > 2 => Http::response(['error' => ['message' => 'Insufficient Balance']], 402),
            default => Http::response(['choices' => [['message' => ['content' => 'ок'], 'finish_reason' => 'stop']]]),
        };
    });

    $client = app(LlmClient::class);
    $client->chat('system', [['role' => 'user', 'content' => 'привет']]);
    $client->embed(['вопрос'], 'query');
    // Баланс кончился — тот же вызов отвечает aitunnel, DeepSeek на паузе.
    $result = $client->chat('system', [['role' => 'user', 'content' => 'ещё']]);
    $client->chat('system', [['role' => 'user', 'content' => 'и ещё']]);

    expect($client)->toBeInstanceOf(SplitLlmClient::class)
        ->and($client->chatModel())->toBe('deepseek-flash')
        ->and($client->embeddingModel())->toBe(config('ai_support.embedding.model'))
        ->and($result->channel)->toBe('aitunnel')
        ->and($urls)->toBe([
            'https://deepseek.test/chat/completions',
            'https://aitunnel.test/v1/embeddings',
            'https://deepseek.test/chat/completions',
            'https://aitunnel.test/v1/chat/completions',
            'https://aitunnel.test/v1/chat/completions',
        ]);
});

it('запасной не задан или совпадает с основным — без запасного', function (): void {
    config(['ai_support.agent.channel' => 'deepseek', 'ai_support.agent.reserve' => '']);
    $none = app(ChatChannels::class)->client();

    config(['ai_support.agent.reserve' => 'deepseek']);
    $same = app(ChatChannels::class)->client();

    Http::fake(fn () => Http::response('down', 502));

    foreach ([$none, $same] as $client) {
        expect(fn () => $client->chat('system', [['role' => 'user', 'content' => 'привет']]))
            ->toThrow(LlmException::class);
    }

    Http::assertSentCount(2);
});
