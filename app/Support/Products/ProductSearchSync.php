<?php

namespace App\Support\Products;

use App\Models\Product;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class ProductSearchSync
{
    /**
     * @param  array<int, int|string>  $ids
     * @return array{synced:int,removed:int}
     */
    public function syncIds(array $ids): array
    {
        $products = $this->productsForIds($ids);

        if ($products->isEmpty()) {
            return [
                'synced' => 0,
                'removed' => 0,
            ];
        }

        $searchable = $products
            ->filter(fn (Product $product): bool => $product->shouldBeSearchable())
            ->values();
        $unsearchable = $products
            ->reject(fn (Product $product): bool => $product->shouldBeSearchable())
            ->values();

        if ($searchable->isNotEmpty()) {
            $searchable->searchableSync();
        }

        if ($unsearchable->isNotEmpty()) {
            $unsearchable->unsearchableSync();
        }

        return [
            'synced' => $searchable->count(),
            'removed' => $unsearchable->count(),
        ];
    }

    /**
     * @param  array<int, int|string>  $ids
     */
    public function removeIds(array $ids): int
    {
        $products = $this->productsForIds($ids);

        if ($products->isEmpty()) {
            return 0;
        }

        $products->unsearchableSync();

        return $products->count();
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
     * @param  array<int, int|string>  $ids
     */
    private function productsForIds(array $ids): EloquentCollection
    {
        $normalizedIds = $this->normalizeIds($ids);

        if ($normalizedIds === []) {
            return Product::newCollection();
        }

        return Product::query()
            ->whereKey($normalizedIds)
            ->orderBy('id')
            ->get();
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
