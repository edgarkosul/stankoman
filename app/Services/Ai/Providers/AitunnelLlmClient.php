<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\Contracts\LlmClient;
use App\Services\Ai\Contracts\ReasoningSwitch;
use App\Services\Ai\Data\ChatResult;
use App\Services\Ai\Data\EmbeddingBatch;
use App\Services\Ai\Data\ToolCall;
use App\Services\Ai\Exceptions\LlmException;
use App\Services\Ai\Exceptions\PiiBlockedException;
use App\Services\Ai\Support\GatewayAddressPin;
use GuzzleHttp\Exception\ConnectException as GuzzleConnectException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Клиент шлюза aitunnel.ru — OpenAI-совместимые /chat/completions и /embeddings
 * под одним ключом.
 *
 * Намеренно тонкий: ни цикла tool-use, ни сборки промпта, ни обрезки истории —
 * всё это живёт в агенте. Здесь только транспорт, ретраи, разбор ответа и учёт
 * стоимости.
 */
final class AitunnelLlmClient implements LlmClient, ReasoningSwitch
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $chatModel,
        private readonly string $embeddingModel,
        private readonly int $embeddingDimensions,
        private readonly int $embeddingBatchSize,
        private readonly int $queryCacheTtl,
        private readonly int $timeout,
        private readonly int $connectTimeout,
        private readonly int $maxRetries,
        private readonly int $connectRetries,
        private readonly bool $sessionAffinity,
        /**
         * Живой пин адреса. Необязателен: без него клиент работает как
         * раньше, полагаясь на DNS и ретраи.
         */
        private readonly ?GatewayAddressPin $pin = null,
        /** ai_support.agent.reasoning: '' | low | minimal | off. Не readonly — ради withoutReasoning(). */
        private string $reasoning = '',
        /**
         * ai_support.gateway.provider_sort: '' | latency | throughput | price.
         * Пустая — объекта `provider` в запросе нет, и шлюз выбирает сам.
         */
        private readonly string $providerSort = '',
    ) {}

    /**
     * Копия, а не переключатель: клиент — синглтон, и следующий разговор
     * обязан рассуждать так, как задано настройкой.
     */
    public function withoutReasoning(): LlmClient
    {
        $client = clone $this;
        $client->reasoning = 'off';

        return $client;
    }

    public function chatModel(): string
    {
        return $this->chatModel;
    }

    public function embeddingModel(): string
    {
        return $this->embeddingModel;
    }

    public function embeddingDimensions(): int
    {
        return $this->embeddingDimensions;
    }

    public function chat(
        string $system,
        array $messages,
        array $tools = [],
        ?int $maxTokens = null,
        ?string $sessionId = null,
        ?string $toolChoice = null,
    ): ChatResult {
        $payload = [
            'model' => $this->chatModel,
            'max_tokens' => $maxTokens ?? 1024,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ...$messages,
            ],
        ];

        /*
         * Шлюз понимает оба вида: reasoning_effort (как у OpenAI) и
         * reasoning.enabled (как у OpenRouter). Выключить совсем умеет только
         * второй: thinking.type=disabled оставлял десятки токенов рассуждений.
         */
        $payload += match ($this->reasoning) {
            '' => [],
            'off' => ['reasoning' => ['enabled' => false]],
            default => ['reasoning_effort' => $this->reasoning],
        };

        if ($tools !== []) {
            $payload['tools'] = $tools;

            if ($toolChoice !== null) {
                // 'auto' | 'none' | 'required' идут как есть; всё остальное
                // трактуем как имя конкретного инструмента.
                $payload['tool_choice'] = in_array($toolChoice, ['auto', 'none', 'required'], true)
                    ? $toolChoice
                    : ['type' => 'function', 'function' => ['name' => $toolChoice]];
            }
        }

        // Без session_id кеш префикса на коротких диалогах не включается вовсе:
        // привязка к провайдеру появляется только после первого попадания в кэш.
        // Замер: 0.06 ₽ первый запрос и 0.02 ₽ последующие против 0.10 ₽ всегда.
        if ($this->sessionAffinity && $sessionId !== null && $sessionId !== '') {
            $payload['session_id'] = mb_substr($sessionId, 0, 256);
        }

        /*
         * Кому из провайдеров модели отдать запрос. Без поля aitunnel выбирает
         * «по загруженности»; с sort=latency — того, кто быстрее всех выдаёт
         * первый токен. Замер bots на проде 07.10.2026 (147 ответов на вариант,
         * вперемешку): ответ p50 5,8 → 3,1 с, p95 24 → 7,7 с, 0,29 → 0,22 ₽ —
         * запросы липнут к одному провайдеру, и кэш префикса попадает чаще.
         * Кого выбрал шлюз, в ответе не видно; ПДн он маскирует до передачи
         * провайдеру, как и без поля.
         */
        if ($this->providerSort !== '') {
            $payload['provider'] = ['sort' => $this->providerSort];
        }

        $response = $this->post('/chat/completions', $payload);
        $body = $response->json();

        $message = $body['choices'][0]['message'] ?? [];
        $usage = $body['usage'] ?? [];

        // Модель может прислать текст И запрос инструментов одновременно
        // («Сейчас проверю наличие…» + tool_call). Это не ошибка формата;
        // такой текст пригождается как индикатор «уточняю».
        $toolCalls = array_map(
            static fn (array $raw): ToolCall => ToolCall::fromArray($raw),
            array_values($message['tool_calls'] ?? []),
        );

        return new ChatResult(
            content: (string) ($message['content'] ?? ''),
            toolCalls: $toolCalls,
            finishReason: (string) ($body['choices'][0]['finish_reason'] ?? ''),
            inputTokens: (int) ($usage['prompt_tokens'] ?? 0),
            outputTokens: (int) ($usage['completion_tokens'] ?? 0),
            cachedTokens: (int) ($usage['prompt_tokens_details']['cached_tokens'] ?? 0),
            costRub: (float) ($usage['cost_rub'] ?? 0),
            maskedTypes: $this->maskedTypes($response),
            model: (string) ($body['model'] ?? ''),
        );
    }

    public function embed(array $texts, string $mode = 'doc'): EmbeddingBatch
    {
        $texts = array_values($texts);

        if ($texts === []) {
            return new EmbeddingBatch([], $this->embeddingModel, $this->embeddingDimensions);
        }

        // Кэшируем только запросы посетителей: у магазинного FAQ короткий хвост,
        // и одни и те же формулировки повторяются изо дня в день. Документы
        // кэшировать незачем — индексация и так инкрементна по content_hash.
        $cacheable = $mode === 'query' && $this->queryCacheTtl > 0;

        $vectors = [];
        $missing = [];

        foreach ($texts as $i => $text) {
            $hit = $cacheable ? Cache::get($this->queryCacheKey($text)) : null;

            if (is_array($hit)) {
                $vectors[$i] = $hit;
            } else {
                $missing[$i] = $text;
            }
        }

        $promptTokens = 0;
        $costRub = 0.0;

        foreach (array_chunk($missing, $this->embeddingBatchSize, preserve_keys: true) as $batch) {
            $response = $this->post('/embeddings', [
                'model' => $this->embeddingModel,
                'input' => array_values($batch),
                'dimensions' => $this->embeddingDimensions,
            ]);

            $body = $response->json();
            $data = $body['data'] ?? [];

            if (count($data) !== count($batch)) {
                throw new LlmException(
                    'Шлюз вернул '.count($data).' векторов на '.count($batch).' текстов.'
                );
            }

            $promptTokens += (int) ($body['usage']['prompt_tokens'] ?? 0);
            $costRub += (float) ($body['usage']['cost_rub'] ?? 0);

            // Порядок в ответе не гарантирован ничем, кроме поля index —
            // полагаться на позицию в массиве нельзя.
            $positions = array_keys($batch);

            foreach ($data as $offset => $item) {
                $position = $positions[$item['index'] ?? $offset] ?? null;

                if ($position === null) {
                    throw new LlmException('Шлюз вернул вектор с неизвестным index.');
                }

                $vector = array_map('floatval', $item['embedding'] ?? []);

                if (count($vector) !== $this->embeddingDimensions) {
                    throw new LlmException(
                        'Ожидали '.$this->embeddingDimensions.' измерений, получили '.count($vector).
                        '. Модель '.$this->embeddingModel.' проигнорировала параметр dimensions?'
                    );
                }

                $vectors[$position] = $vector;

                if ($cacheable) {
                    Cache::put($this->queryCacheKey($texts[$position]), $vector, $this->queryCacheTtl);
                }
            }
        }

        ksort($vectors);

        return new EmbeddingBatch(
            vectors: array_values($vectors),
            model: $this->embeddingModel,
            dimensions: $this->embeddingDimensions,
            promptTokens: $promptTokens,
            costRub: $costRub,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function post(string $path, array $payload): Response
    {
        $attempt = 0;

        while (true) {
            $attempt++;

            $request = Http::withToken($this->apiKey)
                ->acceptJson()
                ->asJson()
                ->connectTimeout($this->connectTimeout)
                ->timeout($this->timeout);

            // Заведомо живой адрес вместо жребия по DNS-ответу. Пин ставит
            // ai:gateway-probe; его отсутствие — это не ошибка, а обычный
            // режим «полагаемся на DNS».
            $resolve = $this->pin?->resolveEntry();

            if ($resolve !== null) {
                $request = $request->withOptions(['curl' => [CURLOPT_RESOLVE => [$resolve]]]);
            }

            try {
                $response = $request->post($this->baseUrl.$path, $payload);
            } catch (ConnectionException $e) {
                $sent = $this->requestLeftTheMachine($e);
                $limit = $sent ? $this->maxRetries : $this->connectRetries;

                /*
                 * Пин привёл в никуда — снимаем его немедленно, не дожидаясь
                 * следующей пробы. Иначе все оставшиеся попытки уткнутся
                 * в тот же мёртвый адрес, и ретраи, весь смысл которых
                 * в новом жребии, перестанут работать.
                 */
                if (! $sent && $resolve !== null) {
                    $this->pin?->forget();
                }

                if ($attempt > $limit) {
                    throw new LlmException('Шлюз недоступен: '.$e->getMessage(), previous: $e);
                }

                Log::warning('Aitunnel connect retry', [
                    'path' => $path,
                    'attempt' => $attempt,
                    // Ушёл ли запрос — это и есть причина разной настойчивости.
                    'sent' => $sent,
                    // Адрес, на котором сорвалось. Ради него всё и затевалось:
                    // без него в логе видно «шлюз недоступен», а не «недоступен
                    // вот этот edge Cloudflare, а соседний отвечает за 20 мс».
                    'ip' => $this->remoteIp($e),
                    'error' => $e->getMessage(),
                ]);

                /*
                 * Соединение не поднялось — паузы не нужно: попытка уже
                 * простояла connect_timeout, и это более чем достаточный
                 * backoff. Ждать здесь значило бы дарить посетителю лишние
                 * секунды «печатает» ровно в том случае, который чинится
                 * немедленным повтором.
                 */
                if ($sent) {
                    $this->backoff($attempt);
                }

                continue;
            }

            if ($response->successful()) {
                $this->logMasking($path, $response);

                return $response;
            }

            // Блокировка по персональным данным — не сбой связи, повторять
            // бессмысленно: тот же текст завернут снова.
            if ($response->status() === 400) {
                $error = $response->json('error') ?? [];

                if (($error['type'] ?? $error['code'] ?? null) === 'pii_blocked') {
                    throw new PiiBlockedException(
                        (string) ($error['message'] ?? 'Запрос заблокирован из-за персональных данных.'),
                        $this->maskedTypes($response),
                    );
                }
            }

            // 429 и 5xx — временные. Остальные 4xx повторять незачем:
            // это наша ошибка в запросе, и она не рассосётся.
            $retryable = $response->status() === 429 || $response->serverError();

            if (! $retryable || $attempt > $this->maxRetries) {
                throw new LlmException(sprintf(
                    'Шлюз ответил HTTP %d на %s: %s',
                    $response->status(),
                    $path,
                    mb_substr($response->body(), 0, 300),
                ));
            }

            Log::warning('Aitunnel retry', [
                'path' => $path,
                'status' => $response->status(),
                'attempt' => $attempt,
            ]);

            $this->backoff($attempt);
        }
    }

    private function backoff(int $attempt): void
    {
        usleep(min(4_000_000, 250_000 * (2 ** ($attempt - 1))));
    }

    /**
     * Успел ли запрос покинуть машину.
     *
     * От этого зависит, сколько раз повторять, и разница тут денежная,
     * а не стилистическая. Соединение не поднялось — сервер нас не видел,
     * модель не работала, повтор бесплатен и нужен настойчиво. Соединение
     * поднялось, а ответ не пришёл — модель, возможно, отработала и деньги
     * списаны; повтор тогда платит дважды и рискует двумя ответами на один
     * вопрос, поэтому остаётся осторожным.
     *
     * По классу исключения эти случаи не различить: Guzzle 7 кладёт cURL 28
     * в ConnectException независимо от того, на какой фазе истекло время
     * (CurlFactory::createRejection, список $connectionErrors). Поэтому
     * смотрим в контекст обработчика — это curl_getinfo(): нулевой
     * appconnect_time означает, что TLS-сессия не состоялась, а без неё
     * ни один байт запроса на сервер не ушёл.
     *
     * Чего в контексте нет — то считаем отправленным. Ошибиться в эту
     * сторону значит недоретраить, в обратную — заплатить дважды.
     *
     * Перенесено из kratonshop по его аварии 17.09.2026: api.aitunnel.ru
     * отдаёт два адреса Cloudflare, и TCP до одного из них с того сервера
     * терялся (ICMP проходил, SYN — нет). Резолвер выбирал мёртвый адрес
     * в ~70% случаев, и каждый такой вызов висел весь connect_timeout.
     */
    private function requestLeftTheMachine(ConnectionException $e): bool
    {
        $context = $this->handlerContext($e);

        // На http запроса без TLS appconnect_time нулевой и у здорового
        // соединения — там признаком служит сам факт установленного TCP.
        $key = str_starts_with($this->baseUrl, 'https://') ? 'appconnect_time' : 'connect_time';

        if (! array_key_exists($key, $context)) {
            return true;
        }

        return (float) $context[$key] > 0.0;
    }

    /** Адрес, к которому шло соединение, — если обработчик его назвал. */
    private function remoteIp(ConnectionException $e): ?string
    {
        $ip = $this->handlerContext($e)['primary_ip'] ?? null;

        return is_string($ip) && $ip !== '' ? $ip : null;
    }

    /**
     * curl_getinfo() из-под Guzzle. Пустой массив — обработчик не curl
     * либо подменён в тестах.
     *
     * @return array<string, mixed>
     */
    private function handlerContext(ConnectionException $e): array
    {
        $previous = $e->getPrevious();

        return $previous instanceof GuzzleConnectException ? $previous->getHandlerContext() : [];
    }

    /**
     * След маскирования на шлюзе — в лог, обязательно.
     *
     * Маскирование включается НЕ у нас: это настройка ключа в панели
     * aitunnel, и переключить её может кто угодно и когда угодно, не
     * трогая наш код. Единственное, чем это состояние проявляется в
     * работе, — заголовки ответа. Без записи в лог мы не смогли бы
     * ни доказать, что слой работает, ни заметить, что он выключился.
     *
     * Пишем ТОЛЬКО типы — «phone», «email». Сами значения не пишем
     * никогда: смысл всей затеи в том, чтобы персональные данные не
     * расползались, а лог — это ровно то место, куда они расползаются
     * в первую очередь.
     *
     * Молчим, когда не сработало ничего: строка «маскирование не
     * понадобилось» на каждый ход утопила бы лог.
     */
    private function logMasking(string $path, Response $response): void
    {
        $types = $this->maskedTypes($response);

        if ($types === []) {
            return;
        }

        Log::info('Aitunnel masked PII', [
            'path' => $path,
            'masked' => $response->header('X-AITunnel-Masked'),
            'types' => $types,
        ]);
    }

    /**
     * Что шлюз распознал как персональные данные и подменил синтетикой.
     * Заголовков нет, если не сработало ничего — и их же нет, если маскирование
     * на ключе вовсе выключено. Различать эти два случая умеет ai:kb-doctor.
     *
     * @return list<string>
     */
    private function maskedTypes(Response $response): array
    {
        $header = $response->header('X-AITunnel-Masked-Types');

        if ($header === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $header))));
    }

    private function queryCacheKey(string $text): string
    {
        // Модель и размерность — часть ключа: иначе после их смены поиск молча
        // продолжит отдавать векторы от прежней модели.
        return 'ai:emb:'.$this->embeddingModel.':'.$this->embeddingDimensions.':'
            .hash('sha256', mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? $text)));
    }
}
