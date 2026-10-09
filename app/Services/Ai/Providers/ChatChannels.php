<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\Contracts\LlmClient;
use App\Services\Ai\Exceptions\LlmException;
use App\Services\Ai\Support\DeepSeekPricing;
use App\Services\Ai\Support\GatewayAddressPin;
use InvalidArgumentException;

/**
 * Каналы чата: каким путём модель получает запрос.
 *
 *   aitunnel — как было всегда: чат и эмбеддинги под одним ключом;
 *   deepseek — только чат, сам API DeepSeek, без посредников.
 *
 * Канал выбирает `ai_support.agent.channel` (AI_CHAT_CHANNEL); остальной бот
 * видит только LlmClient. Эмбеддинги — всегда aitunnel: векторы в базе
 * посчитаны там. У канала deepseek есть запасной (`ai_support.agent.reserve`,
 * FailoverLlmClient).
 *
 * Здесь же клиенты для замера (ai:bench): модель и `provider.sort` aitunnel
 * поверх настроек. Перенесено из bots (6c62f89), без RouterAI.
 */
final class ChatChannels
{
    public const AITUNNEL = 'aitunnel';

    public const DEEPSEEK = 'deepseek';

    public function __construct(
        private readonly ?GatewayAddressPin $pin = null,
    ) {}

    /**
     * Клиент для контейнера: чат — каналом из настроек, при его сбое —
     * запасным; эмбеддинги — aitunnel.
     */
    public function client(): LlmClient
    {
        $aitunnel = $this->aitunnel();
        $channel = (string) config('ai_support.agent.channel');

        // Канал по умолчанию — ровно тот объект, что был до каналов.
        if ($channel === self::AITUNNEL) {
            return $aitunnel;
        }

        $chat = $this->chat($channel);
        $reserve = (string) config('ai_support.agent.reserve');

        if ($reserve !== '' && $reserve !== $channel) {
            $chat = new FailoverLlmClient($chat, $this->chat($reserve), (int) config('ai_support.agent.reserve_pause'));
        }

        return new SplitLlmClient(chat: $chat, embeddings: $aitunnel);
    }

    /**
     * Клиент чата канала по имени: `aitunnel`, `aitunnel+latency`, `deepseek`.
     * У `aitunnel` без плюса `provider.sort` — из настройки.
     *
     * @param  string|null  $reasoning  вместо ai_support.agent.reasoning
     */
    public function chat(string $channel, ?string $reasoning = null): AitunnelLlmClient
    {
        [$name, $sort] = array_pad(explode('+', $channel, 2), 2, null);

        return match ($name) {
            self::AITUNNEL => $this->aitunnel(reasoning: $reasoning, sort: $sort),
            self::DEEPSEEK => $sort === null
                ? $this->deepseek(reasoning: $reasoning)
                : throw new InvalidArgumentException('У DeepSeek нет provider.sort: канал «'.$channel.'».'),
            default => throw new InvalidArgumentException('Неизвестный канал чата «'.$channel.'»: aitunnel, aitunnel+latency, deepseek.'),
        };
    }

    /**
     * @param  string|null  $model  вместо ai_support.agent.model — замер моделей
     * @param  string|null  $sort  вместо ai_support.gateway.provider_sort; '' — без поля `provider`
     */
    public function aitunnel(?string $model = null, ?string $reasoning = null, ?string $sort = null): AitunnelLlmClient
    {
        $key = (string) config('ai_support.gateway.key');

        if ($key === '') {
            throw new LlmException(
                'Не задан AI_GATEWAY_KEY. Для работы без сети — AI_EMBEDDING_FAKE=true.'
            );
        }

        $sort ??= (string) config('ai_support.gateway.provider_sort');

        return new AitunnelLlmClient(
            baseUrl: (string) config('ai_support.gateway.base_url'),
            apiKey: $key,
            chatModel: $model ?? (string) config('ai_support.agent.model'),
            embeddingModel: (string) config('ai_support.embedding.model'),
            embeddingDimensions: (int) config('ai_support.embedding.dimensions'),
            embeddingBatchSize: (int) config('ai_support.embedding.batch_size'),
            queryCacheTtl: (int) config('ai_support.embedding.query_cache_ttl'),
            timeout: (int) config('ai_support.gateway.timeout'),
            connectTimeout: (int) config('ai_support.gateway.connect_timeout'),
            maxRetries: (int) config('ai_support.gateway.max_retries'),
            connectRetries: (int) config('ai_support.gateway.connect_retries'),
            sessionAffinity: (bool) config('ai_support.agent.session_affinity'),
            pin: $this->pin,
            queryEmbeddingTimeout: (int) config('ai_support.embedding.query_timeout'),
            queryEmbeddingRetries: (int) config('ai_support.embedding.query_retries'),
            queryEmbeddingPause: (int) config('ai_support.embedding.query_pause'),
            queryEmbeddingHedgeMs: (int) config('ai_support.embedding.query_hedge_ms'),
            providerSort: $sort,
            reasoning: $reasoning ?? (string) config('ai_support.agent.reasoning'),
            channel: $sort === '' ? self::AITUNNEL : self::AITUNNEL.'+'.$sort,
        );
    }

    public function deepseek(?string $reasoning = null): AitunnelLlmClient
    {
        $key = (string) config('ai_support.deepseek.key');

        if ($key === '') {
            throw new LlmException('Не задан AI_DEEPSEEK_KEY: без него канал чата deepseek не работает.');
        }

        $proxy = (string) config('ai_support.deepseek.proxy');

        return new AitunnelLlmClient(
            baseUrl: (string) config('ai_support.deepseek.base_url'),
            apiKey: $key,
            chatModel: (string) config('ai_support.deepseek.model'),
            // Эмбеддингов у DeepSeek нет (SplitLlmClient), но конструктору нужны.
            embeddingModel: (string) config('ai_support.embedding.model'),
            embeddingDimensions: (int) config('ai_support.embedding.dimensions'),
            embeddingBatchSize: (int) config('ai_support.embedding.batch_size'),
            queryCacheTtl: 0,
            timeout: (int) config('ai_support.deepseek.timeout'),
            connectTimeout: (int) config('ai_support.deepseek.connect_timeout'),
            maxRetries: (int) config('ai_support.deepseek.max_retries'),
            connectRetries: (int) config('ai_support.deepseek.connect_retries'),
            // Кэш префикса DeepSeek включает сам, без привязки к сессии
            // (проба bots 08.10.2026: попадания со второго запроса).
            sessionAffinity: false,
            reasoning: $reasoning ?? (string) config('ai_support.agent.reasoning'),
            channel: self::DEEPSEEK,
            dialect: ApiDialect::DeepSeek,
            pricing: DeepSeekPricing::fromConfig(),
            proxy: $proxy === '' ? null : $proxy,
        );
    }
}
