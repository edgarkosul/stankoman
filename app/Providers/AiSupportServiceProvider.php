<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Ai\AssistantConfig;
use App\Services\Ai\Contracts\LlmClient;
use App\Services\Ai\Contracts\ProductLookup;
use App\Services\Ai\Exceptions\LlmException;
use App\Services\Ai\Providers\AitunnelLlmClient;
use App\Services\Ai\Providers\FakeLlmClient;
use App\Services\Ai\ShopAssistant;
use App\Services\Ai\Support\PiiRedactor;
use App\Services\Ai\Support\ProductLinkGuard;
use App\Services\Ai\Support\ProductTextExtractor;
use App\Services\Ai\Support\ReplyFormatter;
use App\Services\Ai\SystemPromptBuilder;
use App\Services\Ai\Tools\AssistantTool;
use App\Services\Ai\Tools\BrowseCategoriesTool;
use App\Services\Ai\Tools\EscalateToOperatorTool;
use App\Services\Ai\Tools\GetProductTool;
use App\Services\Ai\Tools\RequestContactTool;
use App\Services\Ai\Tools\SearchKnowledgeBaseTool;
use App\Services\Ai\Tools\SearchProductsTool;
use App\Services\Catalog\CatalogBrands;
use App\Services\Chat\AssistantQueueHealth;
use App\Services\Chat\ChatAnswerCache;
use App\Services\Chat\ChatConversationService;
use App\Services\Chat\ChatMarkdown;
use App\Services\Chat\ChatPreviewGate;
use App\Services\Chat\Contracts\LeadIntake;
use App\Services\Chat\Contracts\PageContextSource;
use App\Services\Chat\OperatorPresence;
use App\Services\Kb\Contracts\KbSource;
use App\Services\Kb\HtmlKbTextExtractor;
use App\Services\Kb\KbArticleDrafter;
use App\Services\Kb\KbChunker;
use App\Services\Kb\KbGapAnalyzer;
use App\Services\Kb\KbGapClusterer;
use App\Services\Kb\KbVectorIndexer;
use App\Services\Kb\KbVectorStore;
use App\Services\Kb\Sources\KbArticleKbSource;
use App\Services\Kb\TiptapTextExtractor;
use App\Shop\CallbackLeadIntake;
use App\Shop\CatalogSections;
use App\Shop\EloquentProductLookup;
use App\Shop\PageKbSource;
use App\Shop\SettingsKbSource;
use App\Shop\ShopPageContext;
use App\Support\Products\ProductSpecs;
use App\Support\Search\ProductTextSearch;
use App\Support\WorkSchedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

/**
 * Сборка ИИ-ассистента.
 *
 * Всё, что читается из окружения, проходит здесь через config() — не через
 * getenv(). На проде конфиг закэширован, и прямое обращение к env() внутри
 * классов дало бы там другие значения, чем на dev: классическая ошибка,
 * которая проявляется только после деплоя.
 *
 * Классы App\Services\{Ai,Kb,Chat} про магазин не знают ничего. Всё знание
 * о нём — название, страницы, настройки, заявки — приходит отсюда и из App\Shop.
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

        /*
         * Разбор «Пробелов» отделён от группировки намеренно: сбор сигналов
         * ходит в базу, а сама кластеризация — чистая функция над векторами,
         * и проверяется тестом без единой таблицы.
         */
        $this->app->singleton(KbGapClusterer::class, fn (): KbGapClusterer => new KbGapClusterer(
            threshold: (float) config('ai_support.knowledge_base.gaps.cluster_threshold'),
        ));

        $this->app->singleton(KbGapAnalyzer::class, fn (Application $app): KbGapAnalyzer => new KbGapAnalyzer(
            clusterer: $app->make(KbGapClusterer::class),
            maxSignals: (int) config('ai_support.knowledge_base.gaps.max_signals'),
        ));

        $this->app->singleton(KbArticleDrafter::class, fn (Application $app): KbArticleDrafter => new KbArticleDrafter(
            llm: $app->make(LlmClient::class),
            redactor: $app->make(PiiRedactor::class),
            maxTokens: (int) config('ai_support.knowledge_base.draft.max_tokens'),
            minScore: (float) config('ai_support.knowledge_base.min_score'),
            shopName: self::shopName() ?: self::siteHost(),
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
         * Каталог за швом. Инструменты бота знают только контракт ProductLookup,
         * а про Product, Category и ценовую политику знает эта реализация.
         *
         * Синглтон: внутри одного ответа инструменты зовут её несколько раз,
         * а список брендов она кэширует у себя.
         */
        $this->app->singleton(ProductLookup::class, fn (Application $app): ProductLookup => new EloquentProductLookup(
            // Та же точка входа, что у шапки сайта: слова бот и витрина ищут одинаково.
            search: $app->make(ProductTextSearch::class),
            sections: $app->make(CatalogSections::class),
            specs: $app->make(ProductSpecs::class),
            extractor: $app->make(ProductTextExtractor::class),
            descriptionLimit: (int) config('ai_support.agent.product_description_limit'),
            specsInList: (int) config('ai_support.agent.product_specs_in_list'),
            // Та же ставка, что стоит под ценой на карточке товара.
            vatRate: (int) config('settings.product.stavka_nds'),
        ));

        $this->app->singleton(CatalogBrands::class, fn (Application $app): CatalogBrands => new CatalogBrands(
            products: $app->make(ProductLookup::class),
            lookalikes: array_values((array) config('ai_support.catalog_search.brand_lookalikes', [])),
        ));

        $this->app->singleton(SearchKnowledgeBaseTool::class, fn (Application $app): SearchKnowledgeBaseTool => new SearchKnowledgeBaseTool(
            store: $app->make(KbVectorStore::class),
            redactor: $app->make(PiiRedactor::class),
            minScore: (float) config('ai_support.knowledge_base.min_score'),
            topK: (int) config('ai_support.knowledge_base.default_top_k'),
        ));

        /*
         * Порядок в реестре — это порядок в системном промпте, который видит
         * модель. Поиск по базе первым не случайно: он должен читаться как
         * инструмент по умолчанию, а эскалация — как выход, когда он не помог.
         */
        $this->app->bind('assistant.tools', fn (Application $app): array => [
            $app->make(SearchKnowledgeBaseTool::class),
            $app->make(BrowseCategoriesTool::class),
            $app->make(SearchProductsTool::class),
            $app->make(GetProductTool::class),
            $app->make(EscalateToOperatorTool::class),
            $app->make(RequestContactTool::class),
        ]);

        /*
         * Сборщик промпта получает содержимое магазина оттуда, где его правит
         * владелец, а не из текста класса.
         *
         * Почта — ПУБЛИЧНАЯ, `company.public_email` (sales@intertooler.ru): её
         * бот называет покупателю, и она же стоит на «Контактах». Донорский
         * `settings.general.manager_emails` сюда не годится — здесь это личный
         * адрес менеджера, куда уходят уведомления, а не адрес для переписки
         * с покупателем.
         *
         * Кнопку звонка на карточке владелец может выключить (настройка
         * с 14.09.2026). Сравнение с false, а не проверка на истину, — как
         * в самой карточке: битое значение оставляет кнопку, а не прячет молча.
         */
        $this->app->singleton(SystemPromptBuilder::class, fn (): SystemPromptBuilder => new SystemPromptBuilder(
            shopName: self::shopName() ?: self::siteHost(),
            contactEmail: trim((string) config('company.public_email')),
            callButtonOnProductPage: config('settings.product.show_callback_button') !== false,
        ));

        $this->app->singleton(ShopAssistant::class, fn (Application $app): ShopAssistant => new ShopAssistant(
            llm: $app->make(LlmClient::class),
            prompts: $app->make(SystemPromptBuilder::class),
            redactor: $app->make(PiiRedactor::class),
            formatter: $app->make(ReplyFormatter::class),
            links: $app->make(ProductLinkGuard::class),
            tools: $app->make('assistant.tools'),
            maxIterations: (int) config('ai_support.agent.max_iterations'),
            maxTokens: (int) config('ai_support.agent.max_tokens'),
        ));

        $this->app->singleton(AssistantConfig::class, fn (): AssistantConfig => new AssistantConfig(
            shopName: self::shopName() ?: self::siteHost(),
        ));

        $this->registerChat();

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
     * Чат на витрине: разговор, его швы к магазину и то, что решает,
     * кому и когда он виден.
     */
    private function registerChat(): void
    {
        $this->app->singleton(ChatConversationService::class, fn (Application $app): ChatConversationService => new ChatConversationService(
            cookieName: (string) config('ai_support.chat.cookie'),
            historyMessages: (int) config('ai_support.chat.history_messages'),
            pendingTtl: (int) config('ai_support.chat.pending_ttl'),
            typingTtl: (int) config('ai_support.chat.typing_ttl'),
            redactor: $app->make(PiiRedactor::class),
        ));

        // Где стоит посетитель и какую цену он видит — знает магазин.
        $this->app->singleton(PageContextSource::class, ShopPageContext::class);

        // Форма контактов в чате — заявка «перезвоните» из фазы 1.
        $this->app->singleton(LeadIntake::class, CallbackLeadIntake::class);

        /*
         * Сотрудник видит виджет и в предпросмотре. Кто сотрудник — решает
         * тот же список почт, что пускает в админку: ролей в проекте нет.
         */
        $this->app->singleton(ChatPreviewGate::class, fn (): ChatPreviewGate => new ChatPreviewGate(
            previewOnly: (bool) config('ai_support.chat.preview.enabled'),
            key: (string) config('ai_support.chat.preview.key'),
            isStaff: static fn (): bool => ($user = Auth::user()) instanceof User && $user->isFilamentAdmin(),
        ));

        $this->app->singleton(ChatAnswerCache::class, fn (): ChatAnswerCache => new ChatAnswerCache(
            ttl: (int) config('ai_support.chat.answer_cache_ttl'),
            // Порог тот же, что у поиска: промах по базе знаний — сырьё
            // экрана «Пробелы», и кэшировать его нельзя.
            minScore: (float) config('ai_support.knowledge_base.min_score'),
        ));

        $this->app->singleton(AssistantQueueHealth::class, fn (): AssistantQueueHealth => new AssistantQueueHealth(
            connection: 'redis-assistant',
            queue: 'assistant',
            backlogLimit: (int) config('ai_support.chat.queue_backlog_limit'),
            silenceSeconds: (int) config('ai_support.chat.queue_silence'),
        ));

        /*
         * Разметка ленты. Свой хост берём из конфига приложения: от него
         * зависит, останется ли ссылка кликабельной, и на проде значение
         * обязано быть настоящим — иначе кликабельными не будут даже свои.
         */
        $this->app->singleton(ChatMarkdown::class, fn (): ChatMarkdown => new ChatMarkdown(
            appUrl: (string) config('app.url'),
            ownHosts: (array) config('ai_support.chat.own_hosts', []),
        ));

        /*
         * Расписание менеджеров — режим работы магазина из «Настроек», тот же,
         * что в шапке и подвале (решение 15.09.2026: один факт — одно место).
         * Конфиг ассистента — только запасное значение на случай, когда
         * настройки нет вовсе.
         */
        $this->app->singleton(OperatorPresence::class, fn (): OperatorPresence => new OperatorPresence(
            schedule: is_array(config('company.work_schedule'))
                ? WorkSchedule::fromConfig()->days
                : (array) config('ai_support.operators.schedule', []),
            timezone: (string) config('ai_support.operators.timezone', config('app.timezone')),
            activityWindowMinutes: (int) config('ai_support.operators.activity_window_minutes'),
            overrideTtlMinutes: (int) config('ai_support.operators.override_ttl_minutes'),
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
     * Все инструменты ассистента.
     *
     * @return list<AssistantTool>
     */
    public static function tools(): array
    {
        return app('assistant.tools');
    }

    /**
     * Название магазина для крошек базы знаний и для промпта — из настроек,
     * а не строкой в коде: крошки уходят в эмбеддинг префиксом каждого
     * фрагмента, а промптом бот представляется покупателю, и оба должны
     * совпадать с тем, что человек видит на сайте.
     */
    private static function shopName(): string
    {
        return trim((string) config('settings.general.shop_name'));
    }

    /**
     * Запасное имя магазина — домен сайта.
     *
     * Настройку можно очистить из админки, и тогда бот представлялся бы
     * «консультантом магазина ». Домен здесь лучше литерала в коде: он
     * тоже настоящее имя магазина и меняется вместе с ним.
     */
    private static function siteHost(): string
    {
        return (string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'магазина');
    }
}
