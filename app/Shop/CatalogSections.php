<?php

namespace App\Shop;

use App\Models\Category;
use App\Support\Search\ProductTextSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Разделы каталога по слову покупателя.
 *
 * Перенесено из kratonshop, где выросло из замера 04.09.2026: на «поршневой
 * компрессор в наличии, бюджет 300–400 тысяч» поиск словами отдал пять
 * ВИНТОВЫХ компрессоров, а бот сообщил, что поршневых в наличии нет, — при
 * поршневом на складе. Слово «поршневой» в названии товара обычно не пишут:
 * тип живёт в названии раздела, а раздел — это фильтр. Фильтр работает
 * точно, ранжирование — как повезёт.
 *
 * ДВА ПУТИ ПОИСКА, и оба нужны. По названию раздела «пылесос» находит
 * «Промышленные пылесосы», но не находит «Вытяжки и стружкоотсосы». По разделам
 * НАЙДЕННЫХ товаров — наоборот: слово из названия товара приводит в раздел,
 * хотя в имени раздела его нет.
 *
 * Живёт в App\Shop, а не в App\Services\Catalog, как у донора: класс знает
 * про Category, пивот product_categories и витрины брендов — то есть про
 * устройство ЭТОГО каталога.
 */
final class CatalogSections
{
    /** Сколько товаров из поисковой выдачи сворачивать в разделы. */
    private const PRODUCTS_SAMPLE = 60;

    public function __construct(private readonly ProductTextSearch $search) {}

    /**
     * Листовые разделы по слову, от самого подходящего к менее.
     *
     * У каждого проставлен `bot_products_count` — число активных товаров.
     *
     * @return Collection<int, Category>
     */
    public function find(string $query, int $limit = 6): Collection
    {
        $query = trim($query);

        if ($query === '') {
            return collect();
        }

        $byName = $this->byName($query);
        $found = $this->byProducts($query);

        $rows = [];

        foreach ($byName->merge($found['categories'])->unique('id') as $category) {
            /*
             * Витрины брендов («Выбор по производителю › Hansmann») — не разделы
             * техники, а способ показать поставщика. По числу товаров они
             * бывают крупнее любого настоящего раздела и по нему всегда
             * оказывались бы сверху.
             */
            if ($category->isBrandCategory()) {
                continue;
            }

            if ((int) $category->bot_products_count === 0) {
                continue;
            }

            $rows[] = [
                'category' => $category,
                /*
                 * Вес — сколько НАЙДЕННЫХ товаров лежит в разделе, а не
                 * сколько их там всего. Размер раздела говорит о складе,
                 * попадание найденного — о запросе. Совпадение по названию
                 * весомее любого попадания.
                 */
                'weight' => ($found['weights'][$category->getKey()] ?? 0)
                    + ($byName->contains('id', $category->getKey()) ? 1000 : 0),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $b['weight'] <=> $a['weight']);

        return collect(array_slice($rows, 0, $limit))->map(
            static fn (array $row): Category => $row['category'],
        );
    }

    /**
     * Разделы, чьё название содержит слова запроса.
     *
     * Сравнение идёт по ОСНОВЕ слова, а не целиком: разделы называются
     * «Винтовые компрессоры», покупатель говорит «винтовой», и точный LIKE
     * не совпадает ни разу. Основа берётся грубо — минус два знака с конца;
     * для русских прилагательных этого хватает (винтов|ой → винтов), а цена
     * ошибки — лишний раздел в списке, который отсеется весом.
     *
     * @return Collection<int, Category>
     */
    private function byName(string $query): Collection
    {
        $stems = [];

        foreach (preg_split('/\s+/u', $query) ?: [] as $word) {
            $word = trim($word, " \t\n\r\0\x0B.,;:()«»\"");

            if (mb_strlen($word) >= 4) {
                $stems[] = mb_substr($word, 0, mb_strlen($word) - 2);
            }
        }

        if ($stems === []) {
            return collect();
        }

        return $this->leafQuery()
            ->where(function (Builder $builder) use ($stems): void {
                // Все слова запроса — в названии раздела. ИЛИ дало бы
                // «Винтовые компрессоры» на запрос «поршневой компрессор».
                foreach ($stems as $stem) {
                    $builder->where('name', 'like', '%'.$stem.'%');
                }
            })
            ->limit(12)
            ->get();
    }

    /**
     * @return array{categories: Collection<int, Category>, weights: array<int, int>}
     */
    private function byProducts(string $query): array
    {
        $empty = ['categories' => collect(), 'weights' => []];

        try {
            // Той же точкой входа, что поиск товаров и шапка сайта: иначе
            // «хансман» или «бензогенератор tehnotek» не находят ни одного
            // товара, и разделов по ним тоже не будет.
            $ids = $this->search->keys($query, self::PRODUCTS_SAMPLE)->all();
        } catch (Throwable) {
            // Поиск недоступен — остаётся ветка по названию: половина
            // ответа лучше отказа.
            return $empty;
        }

        if ($ids === []) {
            return $empty;
        }

        $weights = DB::table('product_categories')
            ->whereIn('product_id', $ids)
            ->selectRaw('category_id, COUNT(*) as hits')
            ->groupBy('category_id')
            ->pluck('hits', 'category_id')
            ->map(static fn ($hits): int => (int) $hits)
            ->all();

        return [
            'categories' => $this->leafQuery()->whereIn('id', array_keys($weights))->get(),
            'weights' => $weights,
        ];
    }

    /**
     * Живые листовые разделы с числом активных товаров.
     *
     * Число считается одним подзапросом на выборку, а не запросом на раздел,
     * как у донора: разделов в двух ветках набирается до семидесяти.
     *
     * @return Builder<Category>
     */
    private function leafQuery(): Builder
    {
        return Category::query()
            ->leaf()
            ->withoutStaging()
            ->where('is_active', true)
            ->withCount(['products as bot_products_count' => static fn (Builder $q): Builder => $q->where('is_active', true)]);
    }
}
