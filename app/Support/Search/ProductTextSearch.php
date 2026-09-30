<?php

namespace App\Support\Search;

use App\Models\Product;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\MeilisearchEngine;
use Meilisearch\Contracts\SearchQuery;
use Throwable;

/**
 * Поиск товаров по словам — одна точка входа для всех, кто ищет товар текстом.
 *
 * Здесь собраны три правила, и расходиться им между витриной и ассистентом
 * нельзя: покупатель прочтёт разные ответы на один запрос как обман.
 *
 * 1. Кириллица переводится в латиницу (LatinQuery).
 * 2. Кириллическое прочтение бренда заменяется его написанием (BrandSpelling).
 * 3. Пустой многословный запрос повторяется без слов, по которым в индексе
 *    нет ни одного товара (QueryRelaxation). Замер 15.09.2026: из 93 живых
 *    запросов с прода пустыми были 25, «инверторные генераторы» давали 0,
 *    а «генераторы инверторные» — 54.
 *
 * Как исполнить поиск — страницей, коллекцией, ключами, с фильтром или
 * без, — решает замыкание вызывающего. Поэтому класс не знает ни Livewire,
 * ни paginate(), ни вьюх, и ассистент переходит на него одной строкой (keys()).
 *
 * ЦЕНА. Запрос, который что-то нашёл, стоит ровно столько же, сколько раньше:
 * режим поиска не меняется. Пустой запрос из 2–8 слов — не больше двух
 * лишних обращений к Meilisearch: одна проба всех слов разом (multi-search)
 * и один повтор. Подсказки в шапке зовут поиск на каждую паузу в наборе
 * (debounce 300 мс), поэтому повторов не больше одного и цепочек нет.
 *
 * Индекс не трогается: всё это параметры запроса, пересборка не нужна.
 */
final class ProductTextSearch
{
    /** Бренды активных товаров. Сутки: новый бренд появляется редко, сбрасывать руками не нужно. */
    public const BRANDS_CACHE_KEY = 'search:product-text:brands';

    /**
     * Обе зависимости подменяются только в тестах: там драйвер Scout — collection,
     * и настоящей пробы по словам у него нет.
     *
     * @param  (Closure(list<string>): (list<int>|null))|null  $wordHits  попадания по каждому
     *                                                                    слову отдельно; null — проверить нечем
     * @param  (Closure(): list<string>)|null  $brands
     */
    public function __construct(
        private readonly ?Closure $wordHits = null,
        private readonly ?Closure $brands = null,
    ) {}

    /**
     * @template TResult
     *
     * @param  Closure(Builder<Product>): TResult  $run  как исполнить поиск по готовому запросу
     * @param  bool  $probeAlways  пробовать слова даже при непустой выдаче.
     *                             Нужно смысловому поиску бота: гибрид с вектором
     *                             возвращает ближайших соседей почти всегда, то есть
     *                             пустой выдачи — единственного нашего признака
     *                             «таких слов в каталоге нет» — просто не бывает,
     *                             и шум вектора уехал бы к покупателю как точный ответ
     * @return SearchOutcome<TResult>
     */
    public function run(string $query, Closure $run, bool $probeAlways = false): SearchOutcome
    {
        // Список брендов нужен только кириллице: латиницу BrandSpelling не трогает.
        $text = preg_match('/\p{Cyrillic}/u', $query) === 1
            ? BrandSpelling::substitute($query, $this->brands())
            : LatinQuery::normalize($query);

        $result = $run(Product::search($text));
        $words = $text === '' ? [] : explode(' ', $text);
        $empty = self::isEmpty($result);

        if ((! $empty && ! $probeAlways) || ! QueryRelaxation::worthProbing($words)) {
            return new SearchOutcome($result, $text);
        }

        $hits = $this->hits($words);

        if ($hits === null || count($hits) !== count($words)) {
            return new SearchOutcome($result, $text);
        }

        $unmatchedAt = QueryRelaxation::unmatched($hits);

        // Покупателю показываем его собственные слова, а не транслитерацию.
        $typed = explode(' ', trim((string) preg_replace('/\s+/u', ' ', $query)));
        $source = count($typed) === count($words) ? $typed : $words;
        $unmatched = array_map(static fn (int $i): string => $source[$i], $unmatchedAt);

        /*
         * Выдача уже есть — повторять нечего, но назвать слова, которых нет
         * в каталоге, всё равно надо: это и есть предупреждение вызывающему,
         * что выдача держится не на них.
         */
        if (! $empty) {
            return new SearchOutcome($result, $text, $unmatched);
        }

        $retry = QueryRelaxation::retryWords($words, $unmatchedAt);

        if ($retry === null) {
            return new SearchOutcome($result, $text, $unmatched);
        }

        $relaxedText = implode(' ', $retry);
        $relaxed = $run(Product::search($relaxedText));

        return self::isEmpty($relaxed)
            ? new SearchOutcome($result, $text, $unmatched)
            : new SearchOutcome($relaxed, $relaxedText, $unmatched, relaxed: true);
    }

    /**
     * Ключи найденных товаров в порядке релевантности индекса.
     *
     * @param  array<string, mixed>  $options  параметры Meilisearch, например ['filter' => 'category_ids IN [5]']
     * @return Collection<int, int|string>
     */
    public function keys(string $query, int $limit, array $options = []): Collection
    {
        return $this->run(
            $query,
            static fn (Builder $search): Collection => $search->options($options)->take($limit)->keys(),
        )->result;
    }

    /**
     * Сколько товаров находит каждое слово само по себе — одним запросом.
     *
     * Пробуем без фильтров вызывающего: вопрос «есть ли такое слово в каталоге
     * вообще», а не «в этом разделе». `limit: 0` — документы не нужны, только счёт.
     *
     * @param  list<string>  $words
     * @return list<int>|null
     */
    private function hits(array $words): ?array
    {
        if ($this->wordHits !== null) {
            return ($this->wordHits)($words);
        }

        $product = new Product;
        $engine = $product->searchableUsing();

        if (! $engine instanceof MeilisearchEngine) {
            return null;
        }

        try {
            $response = $engine->multiSearch(array_map(
                static fn (string $word): SearchQuery => (new SearchQuery)
                    ->setIndexUid($product->searchableAs())
                    ->setQuery($word)
                    ->setLimit(0),
                $words,
            ));
        } catch (Throwable) {
            // Проба не удалась — остаётся честная пустая выдача, а не ошибка.
            return null;
        }

        return array_map(
            static fn (array $result): int => (int) ($result['estimatedTotalHits'] ?? $result['totalHits'] ?? 0),
            $response['results'] ?? [],
        );
    }

    /**
     * @return list<string>
     */
    private function brands(): array
    {
        if ($this->brands !== null) {
            return ($this->brands)();
        }

        return Cache::remember(
            self::BRANDS_CACHE_KEY,
            now()->addDay(),
            static fn (): array => Product::query()
                ->where('is_active', true)
                ->whereNotNull('brand')
                ->where('brand', '<>', '')
                ->distinct()
                ->pluck('brand')
                ->all(),
        );
    }

    private static function isEmpty(mixed $result): bool
    {
        return match (true) {
            $result instanceof LengthAwarePaginator => $result->total() === 0,
            is_countable($result) => count($result) === 0,
            default => empty($result),
        };
    }
}
