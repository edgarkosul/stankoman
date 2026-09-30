<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\Contracts\LlmClient;
use App\Services\Ai\Data\ChatResult;
use App\Services\Ai\Data\EmbeddingBatch;
use App\Services\Ai\Data\ToolCall;
use App\Services\Ai\Exceptions\LlmException;
use App\Services\Ai\Exceptions\PiiBlockedException;
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
final class AitunnelLlmClient implements LlmClient
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
        private readonly bool $sessionAffinity,
    ) {}

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

            try {
                $response = Http::withToken($this->apiKey)
                    ->acceptJson()
                    ->asJson()
                    ->connectTimeout($this->connectTimeout)
                    ->timeout($this->timeout)
                    ->post($this->baseUrl.$path, $payload);
            } catch (ConnectionException $e) {
                if ($attempt > $this->maxRetries) {
                    throw new LlmException('Шлюз недоступен: '.$e->getMessage(), previous: $e);
                }

                $this->backoff($attempt);

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
