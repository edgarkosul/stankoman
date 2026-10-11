<?php

namespace App\Providers;

use App\Models\CallbackRequest;
use App\Models\Product;
use App\Models\User;
use App\Services\Captcha\CaptchaManager;
use App\Services\Messengers\MaxChannelsNotifier;
use App\Shop\CallbackLeadIntake;
use App\Shop\Catalog\CatalogSemanticIndex;
use App\Shop\Catalog\CatalogSemanticSearch;
use App\Shop\CatalogSections;
use App\Shop\Chat\ChatAppearance;
use App\Shop\Chat\ChatPreviewGate;
use App\Shop\Chat\CookieConversationToken;
use App\Shop\Chat\ShopChatCaptcha;
use App\Shop\Chat\SitekoPanelCredit;
use App\Shop\Chat\VisitorReplyNotifier;
use App\Shop\EloquentProductLookup;
use App\Shop\Kb\PdfLinkBlockRenderer;
use App\Shop\Kb\RawHtmlBlockRenderer;
use App\Shop\Kb\ShipmentBlockRenderer;
use App\Shop\PageKbSource;
use App\Shop\ProductEmbeddingText;
use App\Shop\SettingsEscalationTarget;
use App\Shop\SettingsKbSource;
use App\Shop\ShopPageContext;
use App\Shop\ShopSettingsStore;
use App\Support\Products\ProductSpecs;
use App\Support\Search\ProductTextSearch;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;
use Meilisearch\Client as MeilisearchClient;
use Siteko\Chatbot\Ai\Contracts\LlmClient;
use Siteko\Chatbot\Ai\Contracts\ProductLookup;
use Siteko\Chatbot\Ai\Contracts\SpecLookup;
use Siteko\Chatbot\Ai\Support\PiiRedactor;
use Siteko\Chatbot\Ai\Support\ProductTextExtractor;
use Siteko\Chatbot\Chat\Contracts\ChatCaptcha;
use Siteko\Chatbot\Chat\Contracts\ConversationTokenStore;
use Siteko\Chatbot\Chat\Contracts\EscalationTarget;
use Siteko\Chatbot\Chat\Contracts\LeadIntake;
use Siteko\Chatbot\Chat\Contracts\PageContextSource;
use Siteko\Chatbot\Chat\Contracts\PanelCredit;
use Siteko\Chatbot\Contracts\SettingsStore;
use Siteko\Chatbot\Kb\Contracts\KbBlockRenderer;
use Siteko\Chatbot\Kb\HtmlKbTextExtractor;
use Siteko\Chatbot\Kb\KbSourceRegistry;
use Siteko\Chatbot\Models\ChatConversation;
use Siteko\Chatbot\Models\ChatMessage;
use Siteko\Chatbot\Notifications\Contracts\EscalationNotifier;
use Siteko\Chatbot\Settings\DatabaseSettingsStore;

/**
 * Бот магазина: движок — пакет siteko/chatbot, здесь — его швы к магазину.
 *
 * Пакет собирает агента, шлюз, базу знаний и чат сам (`ChatbotServiceProvider`)
 * и спрашивает у сайта только контракты: каталог и цены, страницу посетителя,
 * заявку, капчу, кто сотрудник, где лежит токен, какие у сайта настройки.
 * Их реализации — в `App\Shop`, и про модели магазина знают только они.
 *
 * Пакетные привязки сделаны через `*If`, а пакетный провайдер регистрируется
 * раньше этого: значит, привязки ниже — обычные, и они главнее.
 *
 * Всё, что читается из окружения, проходит через config() — не через env():
 * на проде конфиг закэширован. Свои ключи магазина — `config/ai_support.php`,
 * пакетные — `config/chatbot.php` пакета плюс наши два в `config/chatbot.php`.
 */
class AiSupportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerCatalog();
        $this->registerKnowledgeBase();
        $this->registerChat();
    }

    public function boot(): void
    {
        /*
         * Связи разговора с моделями магазина. У пакета их нет и быть
         * не может — он не знает, чем у сайта оформлены пользователь
         * и заявка, — а админке они нужны для имён и ссылок.
         */
        ChatConversation::resolveRelationUsing('user', fn (ChatConversation $conversation) => $conversation->belongsTo(User::class, 'user_id'));
        ChatConversation::resolveRelationUsing('operator', fn (ChatConversation $conversation) => $conversation->belongsTo(User::class, 'operator_id'));
        ChatConversation::resolveRelationUsing('lead', fn (ChatConversation $conversation) => $conversation->belongsTo(CallbackRequest::class, 'lead_ref'));
        ChatMessage::resolveRelationUsing('operator', fn (ChatMessage $message) => $message->belongsTo(User::class, 'operator_id'));

        // Письмо «менеджер ответил» вошедшему покупателю — см. VisitorReplyNotifier.
        ChatMessage::created(fn (ChatMessage $message) => $this->app->make(VisitorReplyNotifier::class)->schedule($message));
    }

    /**
     * Каталог за швом: инструменты бота знают только ProductLookup и
     * SpecLookup, а про Product, Category и ценовую политику знает
     * EloquentProductLookup.
     *
     * Синглтон: внутри одного ответа инструменты зовут его несколько раз,
     * а список брендов он кэширует у себя. О сайте он не знает — сайт один.
     */
    private function registerCatalog(): void
    {
        $this->app->singleton(EloquentProductLookup::class, fn (Application $app): EloquentProductLookup => new EloquentProductLookup(
            // Та же точка входа, что у шапки сайта: слова бот и витрина ищут одинаково.
            search: $app->make(ProductTextSearch::class),
            sections: $app->make(CatalogSections::class),
            specs: $app->make(ProductSpecs::class),
            extractor: $app->make(ProductTextExtractor::class),
            descriptionLimit: (int) config('chatbot.agent.product_description_limit'),
            specsInList: (int) config('chatbot.agent.product_specs_in_list'),
            // Та же ставка, что стоит под ценой на карточке товара.
            vatRate: (int) config('settings.product.stavka_nds'),
            /*
             * Смысл поверх слов. Пока зеркало не собрано — его нет, и поиск
             * идёт по словам: это рабочий режим до первого ai:catalog-embed,
             * а не поломка.
             */
            semantic: $app->make(CatalogSemanticSearch::class),
        ));

        $this->app->singleton(ProductLookup::class, fn (Application $app): ProductLookup => $app->make(EloquentProductLookup::class));
        $this->app->singleton(SpecLookup::class, fn (Application $app): SpecLookup => $app->make(EloquentProductLookup::class));

        /*
         * Зеркало каталога со смыслом. ОТДЕЛЬНЫЙ индекс, а не рабочий:
         * причина — в шапке CatalogSemanticIndex, и она про выручку витрины,
         * а не про чистоту.
         *
         * Настройки поиска по словам зеркало берёт у витрины (config/scout.php),
         * своей копии не заводит: расхождение здесь читалось бы покупателем
         * как «сайт и бот отвечают по-разному на один запрос».
         */
        $this->app->singleton(CatalogSemanticIndex::class, fn (Application $app): CatalogSemanticIndex => new CatalogSemanticIndex(
            client: $app->make(MeilisearchClient::class),
            indexName: (string) config('ai_support.catalog_search.index'),
            embedder: (string) config('ai_support.catalog_search.embedder'),
            dimensions: (int) config('chatbot.embedding.dimensions'),
            semanticRatio: (float) config('ai_support.catalog_search.semantic_ratio'),
            modelSemanticRatio: (float) config('ai_support.catalog_search.semantic_ratio_model'),
            settings: (array) config('scout.meilisearch.index-settings.'.Product::class, []),
        ));

        $this->app->singleton(CatalogSemanticSearch::class, fn (Application $app): CatalogSemanticSearch => new CatalogSemanticSearch(
            index: $app->make(CatalogSemanticIndex::class),
            llm: fn (): LlmClient => $app->make(LlmClient::class),
            // Вектор запроса считает шлюз, значит из текста уходят телефон
            // и почта — ровно тем же правилом, что и у базы знаний.
            redactor: $app->make(PiiRedactor::class),
        ));

        $this->app->singleton(ProductEmbeddingText::class, fn (Application $app): ProductEmbeddingText => new ProductEmbeddingText(
            extractor: $app->make(ProductTextExtractor::class),
            specs: $app->make(ProductSpecs::class),
            limit: (int) config('ai_support.catalog_search.text_limit'),
        ));
    }

    /**
     * Источники базы знаний магазина — страницы из белого списка и реквизиты.
     * Статьи («Статьи для бота») — источник самого пакета, `articles`.
     *
     * Название магазина в крошках — ровно `general.shop_name` без пробелов
     * по краям, как и у статей пакета (`site.name` из ShopSettingsStore):
     * крошки уходят в вектор каждого фрагмента.
     */
    private function registerKnowledgeBase(): void
    {
        $this->app->bind(PageKbSource::class, fn (Application $app): PageKbSource => new PageKbSource(
            extractor: $app->make(HtmlKbTextExtractor::class),
            slugs: array_values((array) config('ai_support.knowledge_base.static_pages', [])),
            shopName: self::shopName(),
        ));

        $this->app->bind(SettingsKbSource::class, fn (): SettingsKbSource => new SettingsKbSource(
            shopName: self::shopName(),
        ));

        $this->app->tag([PageKbSource::class, SettingsKbSource::class], KbSourceRegistry::TAG);

        // Свои блоки редактора: без них отгрузки, документы и вставки html
        // ушли бы из базы знаний пустотой.
        $this->app->tag([
            ShipmentBlockRenderer::class,
            PdfLinkBlockRenderer::class,
            RawHtmlBlockRenderer::class,
        ], KbBlockRenderer::TAG);
    }

    /**
     * Чат: настройки, токен, капча, заявка, сотрудники, страница посетителя
     * и то, что решает, кому и когда виджет виден.
     *
     * Всё, что знает о посетителе или читает настройки в момент вызова, —
     * scoped, как в пакете: воркер очереди сбрасывает такие экземпляры
     * между задачами.
     */
    private function registerChat(): void
    {
        $this->app->scoped(SettingsStore::class, fn (Application $app): SettingsStore => new ShopSettingsStore(
            $app->make(DatabaseSettingsStore::class),
        ));

        $this->app->scoped(ConversationTokenStore::class, fn (): ConversationTokenStore => new CookieConversationToken(
            (string) config('ai_support.chat.cookie'),
        ));

        $this->app->singleton(ChatCaptcha::class, fn (Application $app): ChatCaptcha => new ShopChatCaptcha(
            $app->make(CaptchaManager::class),
        ));

        // Форма контактов в чате — заявка «перезвоните».
        $this->app->singleton(LeadIntake::class, CallbackLeadIntake::class);

        // Где стоит посетитель и какую цену он видит — знает магазин.
        $this->app->singleton(PageContextSource::class, ShopPageContext::class);

        // Кто в магазине сотрудник и как зовут покупателя — тоже знание
        // магазина: ролей нет, всё решает список почт в настройках.
        $this->app->scoped(EscalationTarget::class, SettingsEscalationTarget::class);

        /*
         * Пуш в MAX — во все чаты, подписанные на вопросы из чата («Продажи» →
         * «Уведомления в MAX»), а не в один чат из настроек бота, как у пакета.
         * Ни одного такого чата нет — канал молча выключается: письмо
         * и уведомление в админке доходят и без него.
         */
        $this->app->scoped(EscalationNotifier::class, MaxChannelsNotifier::class);

        $this->app->scoped(PanelCredit::class, SitekoPanelCredit::class);

        $this->app->scoped(ChatAppearance::class);

        /*
         * Сотрудник видит виджет и в предпросмотре. Кто сотрудник — решает
         * тот же список почт, что пускает в админку: ролей в проекте нет.
         */
        $this->app->singleton(ChatPreviewGate::class, fn (): ChatPreviewGate => new ChatPreviewGate(
            previewOnly: (bool) config('ai_support.chat.preview.enabled'),
            key: (string) config('ai_support.chat.preview.key'),
            isStaff: static fn (): bool => ($user = Auth::user()) instanceof User && $user->isFilamentAdmin(),
        ));
    }

    /**
     * Название магазина для крошек базы знаний — из настроек, а не строкой
     * в коде: крошки уходят в эмбеддинг префиксом каждого фрагмента и
     * обязаны совпадать с тем, что человек видит на сайте.
     */
    private static function shopName(): string
    {
        return trim((string) config('settings.general.shop_name'));
    }
}
