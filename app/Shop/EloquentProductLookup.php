<?php

namespace App\Shop;

use App\Models\Category;
use App\Models\Product;
use App\Shop\Catalog\CatalogSemanticSearch;
use App\Support\Products\DiscountVisibility;
use App\Support\Products\ProductSpecs;
use App\Support\Search\ProductTextSearch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Laravel\Scout\Builder as ScoutBuilder;
use Siteko\Chatbot\Ai\Contracts\ProductLookup;
use Siteko\Chatbot\Ai\Contracts\SpecLookup;
use Siteko\Chatbot\Ai\Data\CatalogSection;
use Siteko\Chatbot\Ai\Data\ProductCard;
use Siteko\Chatbot\Ai\Data\ProductMatches;
use Siteko\Chatbot\Ai\Data\ProductQuery;
use Siteko\Chatbot\Ai\Data\SpecCandidate;
use Siteko\Chatbot\Ai\Data\SpecScope;
use Siteko\Chatbot\Ai\Support\ProductTextExtractor;
use Siteko\Chatbot\Ai\Tools\ToolContext;
use Siteko\Chatbot\Catalog\CatalogQueryShape;
use Throwable;

/**
 * Каталог intertooler для ассистента.
 *
 * Читает из MySQL в момент вопроса, а не из поискового индекса: цена
 * и остаток меняются, а индекс — снимок. Индекс отвечает на «что похоже
 * на запрос», база — на «сколько это стоит прямо сейчас».
 *
 * Цена считается тем же правилом и теми же вызовами DiscountVisibility,
 * что и крупное число на карточке товара (ProductController::buildSummary).
 * Назвать в чате другое число значит спорить с собственной витриной.
 */
final class EloquentProductLookup implements ProductLookup, SpecLookup
{
    private const BRANDS_CACHE_KEY = 'ai:catalog:brands:v1';

    /**
     * Список брендов меняется разве что с приходом нового поставщика, а зовут
     * его на каждый товарный вопрос, поэтому час в кэше — нормальный срок.
     */
    private const BRANDS_CACHE_TTL = 3600;

    public function __construct(
        /** Поиск по словам — общий с витриной, см. search(). */
        private readonly ProductTextSearch $search,
        private readonly CatalogSections $sections,
        private readonly ProductSpecs $specs,
        private readonly ProductTextExtractor $extractor,
        /** Потолок описания в знаках — config chatbot.agent.product_description_limit. */
        private readonly int $descriptionLimit,
        /** Сколько строк характеристик у товара в СПИСКЕ выдачи; у одной карточки — все. */
        private readonly int $specsInList,
        /** Ставка НДС из настроек — та же, что стоит под ценой на карточке. */
        private readonly int $vatRate,
        /**
         * Поиск по смыслу — зеркало `products_semantic` (фаза 8).
         *
         * Необязателен намеренно: до первого прогона `ai:catalog-embed`,
         * в тестах и при сломанном зеркале бот ищет по словам. Это хуже
         * подбирает, но не отказывает покупателю.
         */
        private readonly ?CatalogSemanticSearch $semantic = null,
    ) {}

    /** Каталог у магазина есть всегда: это и есть магазин. */
    public function hasCatalog(): bool
    {
        return true;
    }

    /**
     * @param  array<string, mixed>  $attributes  кто спрашивает — `ToolContext::$attributes`
     */
    public function find(string $sku, string $slug, array $attributes = []): ?ProductCard
    {
        $sku = trim($sku);
        $slug = trim($slug);

        if ($sku === '' && $slug === '') {
            return null;
        }

        $product = Product::query()
            ->when($slug !== '', fn ($q) => $q->where('slug', $slug))
            ->when($slug === '', fn ($q) => $q->where('sku', $sku))
            ->where('is_active', true)
            ->first();

        /*
         * Второй заход — по обозначению модели, а не по артикулу.
         *
         * Найдено у донора замером 04.09.2026: покупатель называет машину так,
         * как она написана на шильдике, — «ВК-J 15/10 TG», — бот честно
         * подставляет это в вызов и получает «товар не найден» о компрессоре,
         * который стоит на складе. Отказ по существующему товару — худший вид
         * ошибки: покупатель уходит к тому, у кого «есть».
         */
        $product ??= $this->byDesignation($sku !== '' ? $sku : $slug);

        return $product === null
            ? null
            : $this->card($product, self::seesMemberPrices($attributes), withDescription: true, specsLimit: 0);
    }

    public function search(ProductQuery $query): ProductMatches
    {
        $filter = self::filterFor($query);

        /*
         * Вектор считаем ДО поиска и по оригинальной формулировке покупателя:
         * смысл живёт в словах, а не в их записи, и «компрессор для гаража»
         * эмбеддер понимает, а «kompressor dlya garazha» — нет. Пустой вектор
         * значит «искать по смыслу нечем», и дальше всё идёт как раньше.
         */
        $vector = $this->semantic?->vectorFor($query->text) ?? [];
        $semantic = false;

        /*
         * Порядок выдачи. Пусто — обычный порядок поиска, по совпадению:
         * цена на него не влияет, и «подбери подешевле» без этого правила
         * отдавало первую пятёрку по релевантности (разговор 5, 05.10.2026).
         */
        $sort = $query->sort === null ? [] : ['price:'.$query->sort->direction()];

        /*
         * Слова ищет ProductTextSearch — та же точка входа, что у шапки сайта
         * и страницы поиска. В ней три правила, и своей копии ни одного у бота
         * быть не должно: у донора разошлись ровно две такие копии, и бот
         * отвечал «не нашлось» на то, что показывал сайт.
         *
         *   - латиница: «хансман» иначе не находит Hansmann;
         *   - написание бренда вместо прочтения: «сталекс» → Stalex;
         *   - пустой многословный запрос повторяется без слов, которых нет
         *     в каталоге: «бензогенератор tehnotek» давал 0 при 78 товарах бренда.
         *
         * Слова проверяются без фильтров вызывающего — вопрос «есть ли такое
         * слово в каталоге вообще». Поэтому пустоту от фильтра по типу повтор
         * без слов не маскирует: её разбирает сам инструмент.
         */
        $outcome = $this->search->run(
            $query->text,
            function (ScoutBuilder $search) use ($query, $filter, $vector, $sort, &$semantic): Collection {
                /*
                 * Зеркало держит и слова, и вектор, поэтому «BSM-115» находится
                 * точным совпадением, «для гаража» — по смыслу, и оба в одном
                 * запросе вместе с фильтрами. Слова отдаём ровно те, что ушли бы
                 * в витринный индекс: их уже нормализовал ProductTextSearch.
                 */
                $keys = $vector === [] ? null : $this->semantic?->keys(
                    (string) $search->query,
                    $vector,
                    $filter,
                    $query->limit,
                    $query->designation,
                    $sort,
                );

                if ($keys !== null) {
                    $semantic = true;

                    return collect($keys);
                }

                /*
                 * Опции собираются ОДНИМ массивом: `Builder::options()`
                 * у Scout присваивает, а не дополняет, и второй вызов
                 * молча снёс бы фильтр — поиск продолжал бы работать,
                 * только «до 100 тысяч» перестало бы значить что-либо.
                 */
                $options = array_filter([
                    'filter' => $filter !== '' ? $filter : null,
                    'sort' => $sort !== [] ? $sort : null,
                ], static fn (mixed $value): bool => $value !== null);

                return $search
                    ->when($options !== [], static fn (ScoutBuilder $search): ScoutBuilder => $search->options($options))
                    ->take($query->limit)
                    ->keys();
            },
            /*
             * При гибриде выдача почти никогда не пуста — вектор всегда назовёт
             * ближайших соседей, — поэтому слова проверяются отдельной пробой.
             * Иначе бот молча выдал бы за ответ товары, подобранные по смыслу
             * выдуманного слова: шумовой пол вектора (0.75–0.78) выше цены
             * точного попадания с опечаткой.
             */
            probeAlways: $vector !== [],
        );

        $ids = $outcome->result->map(static fn ($id): int => (int) $id)->all();

        if ($ids === []) {
            return new ProductMatches([], $outcome->text, $outcome->unmatched, $outcome->relaxed, $semantic);
        }

        $products = Product::query()
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        $cards = [];

        /*
         * Описание в выдачу не входит — пять карточек по три тысячи знаков
         * утопили бы ответ, — но у ОДНОГО товара оно появляется: у того,
         * который покупатель назвал по обозначению. У донора на «какой уровень
         * шума у ВК-J 15/10 TG» бот увидел короткий список характеристик
         * и ответил «на сайте не указано», хотя шум стоял в описании.
         */
        $described = false;

        // Порядок задаёт релевантность из индекса, а не БД.
        foreach ($ids as $id) {
            $product = $products->get($id);

            if ($product === null) {
                continue;
            }

            /*
             * «Назвал по обозначению» — это ещё и форма запроса, а не только
             * совпадение букв. У донора условие стоит одно: обозначение целиком
             * нашлось в названии. Но `core()` склеивает слова, поэтому под него
             * подходит и обычное «компрессор», и «винтовой компрессор» — то есть
             * описание на три тысячи знаков привешивалось почти к каждому поиску.
             * Замер донора, ради которого ветка вообще есть, был про «уровень шума
             * у ВК-J 15/10 TG»: обозначение уникально, и описание нужно там.
             */
            $isNamed = ! $described
                && CatalogQueryShape::looksLikeModel($query->text)
                && CatalogQueryShape::namesProduct(
                    $query->text,
                    (string) $product->name,
                    (string) $product->sku,
                );

            $described = $described || $isNamed;

            $cards[] = $this->card(
                $product,
                self::seesMemberPrices($query->attributes),
                withDescription: $isNamed,
                specsLimit: $isNamed ? 0 : $this->specsInList,
            );
        }

        return new ProductMatches($cards, $outcome->text, $outcome->unmatched, $outcome->relaxed, $semantic);
    }

    /**
     * Все товары разделов — кандидаты подбора по порогу (`find_by_spec`).
     *
     * Из базы, а не из индекса: смысл подбора в том, чтобы просмотреть
     * ВЕСЬ раздел, а индекс отдаёт окно по совпадению слов. Разделы —
     * сотни товаров (самый большой, «Винтовые компрессоры», — 568),
     * и описание с его тысячами знаков здесь не читается вовсе. Сверку
     * с порогом, порядок и подсказки делает движок (`SpecSearch`).
     *
     * Цена кандидата — базовая, без скидки: по ней движок ставит дешёвые
     * первыми, и порядок не должен зависеть от того, вошёл ли посетитель.
     * Цена по запросу (0) — null: про неё «дешевле» сказать нельзя.
     */
    public function specCandidates(SpecScope $scope): array
    {
        if ($scope->sectionIds === []) {
            return [];
        }

        return Product::query()
            ->select(['id', 'in_stock', 'price_amount', 'specs'])
            ->where('is_active', true)
            ->whereHas('categories', static fn ($q) => $q->whereIn('categories.id', $scope->sectionIds))
            ->when($scope->inStockOnly, static fn ($q) => $q->where('in_stock', true))
            ->when($scope->priceMin !== null, static fn ($q) => $q->where('price_amount', '>=', $scope->priceMin))
            ->when($scope->priceMax !== null, static fn ($q) => $q->where('price_amount', '<=', $scope->priceMax))
            ->orderBy('id')
            ->get()
            ->map(fn (Product $product): SpecCandidate => new SpecCandidate(
                id: (int) $product->getKey(),
                price: (int) $product->price_amount > 0 ? (int) $product->price_amount : null,
                inStock: (bool) $product->in_stock,
                rows: $this->rows($product),
            ))
            ->all();
    }

    /**
     * Карточки найденного подбором — в порядке номеров, без характеристик:
     * строки, по которым шёл подбор, движок показывает сам.
     *
     * @param  list<int>  $ids
     * @param  array<string, mixed>  $attributes
     * @return list<ProductCard>
     */
    public function cards(array $ids, array $attributes = []): array
    {
        if ($ids === []) {
            return [];
        }

        $products = Product::query()
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        $cards = [];

        foreach ($ids as $id) {
            if (($product = $products->get($id)) instanceof Product) {
                $cards[] = $this->card($product, self::seesMemberPrices($attributes), withDescription: false, specsLimit: 0, specs: []);
            }
        }

        return $cards;
    }

    public function sections(string $query, int $limit): array
    {
        return $this->sections->find($query, $limit)
            ->map(static fn (Category $category): CatalogSection => new CatalogSection(
                id: (int) $category->getKey(),
                path: $category->ancestorsAndSelf()->pluck('name')->implode(' › '),
                url: route('catalog.leaf', ['path' => $category->slug_path]),
                productsCount: (int) $category->bot_products_count,
            ))
            ->values()
            ->all();
    }

    public function brands(): array
    {
        return Cache::remember(self::BRANDS_CACHE_KEY, self::BRANDS_CACHE_TTL, static fn (): array => Product::query()
            ->where('is_active', true)
            ->whereNotNull('brand')
            ->where('brand', '<>', '')
            ->distinct()
            ->pluck('brand')
            // «Термит » на деве лежит с хвостовым пробелом — и не он один.
            ->map(static fn (mixed $brand): string => trim((string) $brand))
            ->filter(static fn (string $brand): bool => $brand !== '')
            ->unique()
            ->values()
            ->all());
    }

    public function memberPrices(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return Product::query()
            ->whereIn('id', $productIds)
            ->get(['id', 'price_amount', 'discount_price'])
            ->filter(static fn (Product $product): bool => DiscountVisibility::isDiscounted(
                (int) $product->price_int,
                $product->discount,
                true,
            ))
            ->map(static fn (Product $product): int => (int) $product->discount)
            ->values()
            ->all();
    }

    /**
     * Фильтр Meilisearch для запроса.
     *
     * Цена в индексе — `price_amount`, то есть БЕЗ скидки. Фильтр поэтому
     * грубый: вошедшему покупателю товар за 105 000 со скидкой до 95 000
     * не попадёт в «до 100 тысяч». Точную цену карточка всё равно берёт
     * из базы, а для отсечения «до 30 тысяч» этого достаточно; чинить —
     * объявлять `discount_price` фильтруемым, это правка настроек индекса.
     */
    public static function filterFor(ProductQuery $query): string
    {
        $parts = [];

        if ($query->inStockOnly) {
            $parts[] = 'in_stock = true';
        }

        if ($query->priceMin !== null) {
            $parts[] = 'price >= '.$query->priceMin;
        }

        if ($query->priceMax !== null) {
            $parts[] = 'price <= '.$query->priceMax;
        }

        if ($query->categoryId !== null) {
            $parts[] = 'category_ids = '.$query->categoryId;
        }

        if ($query->sectionIds !== []) {
            $parts[] = 'category_ids IN ['.implode(', ', array_map('intval', $query->sectionIds)).']';
        }

        return implode(' AND ', $parts);
    }

    /**
     * Товар по обозначению модели: поиск словами плюс строгая сверка.
     *
     * Поиск здесь — только способ сузить каталог до десятка кандидатов;
     * решает `namesProduct`, требующий, чтобы обозначение целиком нашлось
     * в названии или совпало с артикулом. Отдать «похожее» было бы хуже
     * отказа: бот назовёт цену и наличие ЧУЖОЙ машины.
     */
    private function byDesignation(string $text): ?Product
    {
        if (mb_strlen(CatalogQueryShape::core($text)) < 4) {
            return null;
        }

        try {
            // Через ту же точку входа, что и поиск: повтор без незнакомых
            // слов здесь безопасен — чужое отсеет строгая сверка ниже.
            $ids = $this->search->keys($text, 10)->all();
        } catch (Throwable) {
            // Поиск недоступен — это не повод отвечать неправдой.
            return null;
        }

        if ($ids === []) {
            return null;
        }

        $found = Product::query()
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        foreach ($ids as $id) {
            $product = $found->get($id);

            if ($product !== null
                && CatalogQueryShape::namesProduct($text, (string) $product->name, (string) $product->sku)) {
                return $product;
            }
        }

        return null;
    }

    /**
     * @param  list<array{name: string, value: string}>|null  $specs  строки вместо первых
     *                                                                с карточки — те, по которым шёл подбор
     */
    private function card(Product $product, bool $seesDiscounts, bool $withDescription, int $specsLimit, ?array $specs = null): ProductCard
    {
        [$price, $priceNote] = $this->price($product, $seesDiscounts);

        $warranty = trim((string) $product->warranty_display);

        return new ProductCard(
            id: (int) $product->getKey(),
            name: (string) $product->name,
            url: route('product.show', ['product' => $product->slug]),
            sku: trim((string) $product->sku),
            brand: trim((string) $product->brand),
            inStock: (bool) $product->in_stock,
            price: $price,
            priceNote: $priceNote,
            vatNote: $price !== null && $this->vatRate > 0 ? 'НДС '.$this->vatRate.'% в том числе' : '',
            warranty: $warranty !== '' ? $warranty : null,
            specs: $specs ?? $this->rows($product, $specsLimit),
            description: $withDescription
                ? $this->extractor->toText($product->description, $this->descriptionLimit)
                : null,
        );
    }

    /**
     * Строки характеристик для бота — те же, что на вкладке товара, но
     * с понятными названиями вместо загадок поставщика (`SupplierSpecNames`).
     *
     * @return list<array{name: string, value: string}>
     */
    private function rows(Product $product, int $limit = 0): array
    {
        return array_map(
            static fn (array $row): array => ['name' => SupplierSpecNames::readable($row['name']), 'value' => $row['value']],
            $this->specs->rows($product, $limit),
        );
    }

    /**
     * Видит ли посетитель цены «для своих». Решает панель чата при открытии
     * (вошёл в аккаунт — да), а движок только передаёт: см. `/chat/panel`.
     *
     * @param  array<string, mixed>  $attributes
     */
    private static function seesMemberPrices(array $attributes): bool
    {
        return (bool) ($attributes[ToolContext::SEES_MEMBER_PRICES] ?? false);
    }

    /**
     * Цена, которую видит этот посетитель, и что стоит рядом с ней.
     *
     * Четыре случая — ровно те, что рисует resources/views/pages/product/partials/summary.blade.php:
     *
     *   гость, скидка есть   — базовая цена, значок «−N%», «Зарегистрируйтесь и получите скидку»;
     *   гость, скидки нет    — базовая цена;
     *   вошёл, скидка есть   — цена со скидкой, базовая зачёркнута;
     *   цена 0               — «Цена по запросу».
     *
     * Промолчать о скидке гостю тоже ошибка, только в другую сторону: политика
     * заведена ради регистраций, и бот, не сказавший «зарегистрируйтесь —
     * будет дешевле», теряет ровно то, ради чего магазин прячет сумму.
     *
     * @return array{0: int|null, 1: string}
     */
    private function price(Product $product, bool $seesDiscounts): array
    {
        $base = (int) $product->price_int;

        if ($base <= 0) {
            return [null, ''];
        }

        $member = $product->discount;

        if (! DiscountVisibility::isDiscounted($base, $member, true)) {
            return [$base, ''];
        }

        $percent = (int) ($product->display_discount_percent ?? 0);

        if ($seesDiscounts) {
            return [(int) $member, 'это УЖЕ цена со скидкой'.($percent > 0 ? ' '.$percent.'%' : '')
                .' для зарегистрированных (без скидки '.number_format($base, 0, ',', ' ')
                .' руб., на карточке эта цена зачёркнута); покупатель видит её такой, потому что вошёл в аккаунт'];
        }

        // Значок сайт рисует только при ненулевом проценте, приглашение — всегда.
        $badge = $percent > 0
            ? 'рядом с ценой на карточке стоит значок «−'.$percent.'%», а под ней — '
            : 'под ценой на карточке стоит ';

        return [$base, $badge.'«Зарегистрируйтесь и получите скидку или войдите»: '
            .($percent > 0 ? 'скидка '.$percent.'% действует' : 'скидка действует')
            .' для зарегистрированных покупателей, после входа в аккаунт сайт покажет цену ниже. '
            .'Суммы со скидкой в этих данных нет — не называй и не вычисляй её'];
    }
}
