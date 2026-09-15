<?php

namespace App\Providers;

use App\Services\Ai\Contracts\LlmClient;
use App\Services\Ai\Exceptions\LlmException;
use App\Services\Ai\Providers\AitunnelLlmClient;
use App\Services\Ai\Providers\FakeLlmClient;
use App\Services\Kb\Contracts\KbSource;
use App\Services\Kb\HtmlKbTextExtractor;
use App\Services\Kb\KbChunker;
use App\Services\Kb\KbVectorIndexer;
use App\Services\Kb\KbVectorStore;
use App\Services\Kb\Sources\KbArticleKbSource;
use App\Services\Kb\TiptapTextExtractor;
use App\Shop\PageKbSource;
use App\Shop\SettingsKbSource;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Сборка ИИ-ассистента.
 *
 * Всё, что читается из окружения, проходит здесь через config() — не через
 * getenv(). На проде конфиг закэширован, и прямое обращение к env() внутри
 * классов дало бы там другие значения, чем на dev: классическая ошибка,
 * которая проявляется только после деплоя.
 *
 * Классы App\Services\{Ai,Kb} про магазин не знают ничего. Всё знание о нём —
 * название, страницы, настройки — приходит отсюда и из App\Shop.
 */
class AiSupportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LlmClient::class, function (): LlmClient {
            $dimensions = (int) config('ai_support.embedding.dimensions');

            // Тесты и офлайн-отладка: детерминированный вектор из хэша.
            // Поиск на нём бессмыслен, но весь тракт прогоняется без сети.
            if ((bool) config('ai_support.embedding.fake')) {
                return new FakeLlmClient($dimensions);
            }

            $key = (string) config('ai_support.gateway.key');

            if ($key === '') {
                throw new LlmException(
                    'Не задан AI_GATEWAY_KEY. Для работы без сети — AI_EMBEDDING_FAKE=true.'
                );
            }

            return new AitunnelLlmClient(
                baseUrl: (string) config('ai_support.gateway.base_url'),
                apiKey: $key,
                chatModel: (string) config('ai_support.agent.model'),
                embeddingModel: (string) config('ai_support.embedding.model'),
                embeddingDimensions: $dimensions,
                embeddingBatchSize: (int) config('ai_support.embedding.batch_size'),
                queryCacheTtl: (int) config('ai_support.embedding.query_cache_ttl'),
                timeout: (int) config('ai_support.gateway.timeout'),
                connectTimeout: (int) config('ai_support.gateway.connect_timeout'),
                maxRetries: (int) config('ai_support.gateway.max_retries'),
                sessionAffinity: (bool) config('ai_support.agent.session_affinity'),
            );
        });

        $this->app->singleton(KbVectorStore::class, fn (Application $app): KbVectorStore => new KbVectorStore(
            llm: $app->make(LlmClient::class),
            table: (string) config('ai_support.knowledge_base.table'),
            defaultTopK: (int) config('ai_support.knowledge_base.default_top_k'),
        ));

        $this->app->singleton(KbVectorIndexer::class, fn (Application $app): KbVectorIndexer => new KbVectorIndexer(
            llm: $app->make(LlmClient::class),
            chunker: $app->make(KbChunker::class),
            table: (string) config('ai_support.knowledge_base.table'),
        ));

        $this->app->singleton(PageKbSource::class, fn (Application $app): PageKbSource => new PageKbSource(
            extractor: $app->make(HtmlKbTextExtractor::class),
            slugs: array_values((array) config('ai_support.knowledge_base.static_pages', [])),
            shopName: self::shopName(),
        ));

        $this->app->singleton(SettingsKbSource::class, fn (): SettingsKbSource => new SettingsKbSource(
            shopName: self::shopName(),
        ));

        $this->app->singleton(KbArticleKbSource::class, fn (Application $app): KbArticleKbSource => new KbArticleKbSource(
            extractor: $app->make(TiptapTextExtractor::class),
            shopName: self::shopName(),
        ));

        /*
         * Реестр источников: команды и джоба перебирают его, а не знают
         * про конкретные классы. Добавление источника — одна строка здесь.
         *
         * Порядок важен только для вывода команд: сначала то, что уже есть
         * на сайте, потом то, что пишут для бота руками.
         */
        $this->app->tag([PageKbSource::class, SettingsKbSource::class, KbArticleKbSource::class], 'kb.sources');

        $this->app->bind('kb.sources', fn (Application $app): array => array_values(
            iterator_to_array($app->tagged('kb.sources'))
        ));
    }

    /**
     * Все зарегистрированные источники базы знаний.
     *
     * @return list<KbSource>
     */
    public static function sources(): array
    {
        return app('kb.sources');
    }

    /**
     * Название магазина для крошек базы знаний — из настроек, а не строкой
     * в коде: крошки уходят в эмбеддинг префиксом каждого фрагмента, и
     * название должно совпадать с тем, что покупатель видит на сайте.
     */
    private static function shopName(): string
    {
        return trim((string) config('settings.general.shop_name'));
    }
}
