<?php

namespace App\Support\Products;

use App\Jobs\SyncProductsToSearch;
use App\Models\Product;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Единственная дверь в поисковый индекс помимо событий модели.
 *
 * Событий хватает там, где товар сохраняют моделью. Импорт поставщика,
 * массовый редактор в админке и правка привязки к категориям пишут запросами —
 * события не стреляют, и документ в Meilisearch остаётся со старым названием,
 * ценой и наличием, а удалённый товар висит в выдаче. Каждое такое место
 * обязано позвать syncIds()/queueIds() или removeIds() само.
 *
 * Ночная сверка `search:audit --fix` подстрахует, но только через сутки,
 * поэтому она не повод не звать отсюда.
 */
class ProductSearchSync
{
    /** Сколько id уезжает в одну джобу. */
    private const JOB_CHUNK = 1000;

    /** Сколько моделей поднимается из базы за раз. */
    private const LOAD_CHUNK = 500;

    /**
     * Привести документы в соответствие с базой прямо сейчас.
     *
     * @param  array<int, int|string>  $ids
     * @return array{synced:int,removed:int}
     */
    public function syncIds(array $ids): array
    {
        $ids = $this->normalizeIds($ids);
        $result = ['synced' => 0, 'removed' => 0];

        foreach (array_chunk($ids, self::LOAD_CHUNK) as $chunk) {
            $products = Product::query()
                ->whereKey($chunk)
                ->with('categories:id,name')
                ->orderBy('id')
                ->get();

            $searchable = $products
                ->filter(fn (Product $product): bool => $product->shouldBeSearchable())
                ->values();

            if ($searchable->isNotEmpty()) {
                $searchable->searchableSync();
                $result['synced'] += $searchable->count();
            }

            /*
             * Из индекса уходят одинаково и те, кто потерял право там быть
             * (сняли is_active), и те, кого в базе уже нет: в обоих случаях
             * надо удалить документ по id, модель для этого не нужна.
             */
            $gone = array_merge(
                $products
                    ->reject(fn (Product $product): bool => $product->shouldBeSearchable())
                    ->modelKeys(),
                array_values(array_diff($chunk, $products->modelKeys())),
            );

            $result['removed'] += $this->removeIds($gone);
        }

        return $result;
    }

    /**
     * То же самое, но через очередь.
     *
     * «Выделить все» в массовом редакторе — это весь каталог: несколько тысяч
     * моделей с категориями, десятки секунд. Столько админ ждать после нажатия
     * кнопки не должен, а джоба ещё и прочитает актуальное состояние.
     *
     * @param  array<int, int|string>  $ids
     */
    public function queueIds(array $ids): void
    {
        foreach (array_chunk($this->normalizeIds($ids), self::JOB_CHUNK) as $chunk) {
            SyncProductsToSearch::dispatch($chunk);
        }
    }

    /**
     * Убрать документы из индекса по id.
     *
     * @param  array<int, int|string>  $ids
     */
    public function removeIds(array $ids): int
    {
        $ids = $this->normalizeIds($ids);
        $removed = 0;

        foreach (array_chunk($ids, self::LOAD_CHUNK) as $chunk) {
            $this->placeholders($chunk)->unsearchableSync();
            $removed += count($chunk);
        }

        return $removed;
    }

    /**
     * @return array{indexed:int}
     */
    public function rebuildIndex(int $chunk = 500): array
    {
        $chunk = max(1, $chunk);
        $indexed = 0;

        Product::removeAllFromSearch();

        Product::makeAllSearchableQuery()
            ->with('categories:id,name')
            ->chunkById($chunk, function (EloquentCollection $products) use (&$indexed): void {
                $searchable = $products
                    ->filter(fn (Product $product): bool => $product->shouldBeSearchable())
                    ->values();

                if ($searchable->isEmpty()) {
                    return;
                }

                $searchable->searchableSync();
                $indexed += $searchable->count();
            });

        return [
            'indexed' => $indexed,
        ];
    }

    /**
     * Пустые модели с проставленным ключом.
     *
     * Загружать товары из базы здесь нельзя: удалять из индекса чаще всего
     * приходится как раз то, чего в базе уже нет — строки нет, документ есть,
     * и он останется в выдаче навсегда. Scout поступает так же: его джоба
     * RemoveFromSearch хранит только ключи и восстанавливает такие заглушки.
     *
     * @param  array<int, int>  $ids
     * @return EloquentCollection<int, Product>
     */
    private function placeholders(array $ids): EloquentCollection
    {
        return new EloquentCollection(array_map(
            static function (int $id): Product {
                $product = new Product;

                return $product->forceFill([$product->getScoutKeyName() => $id]);
            },
            $ids,
        ));
    }

    /**
     * @param  array<int, int|string>  $ids
     * @return array<int, int>
     */
    private function normalizeIds(array $ids): array
    {
        return collect($ids)
            ->filter(fn (mixed $id): bool => is_numeric($id) && ((int) $id) > 0)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
