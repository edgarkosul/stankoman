<?php

namespace App\Support\Products;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class CategoryProductImageCandidates
{
    /**
     * @return LengthAwarePaginatorContract<int, array{
     *     path: string,
     *     preview_url: string,
     *     product_name: string,
     *     product_sku: string,
     *     is_active: bool
     * }>
     */
    public function paginate(Category $category, ?string $search = null, int $page = 1, int $perPage = 24): LengthAwarePaginatorContract
    {
        $scopeCategoryIds = $this->scopeLeafCategoryIds($category);

        if ($scopeCategoryIds === []) {
            return $this->emptyPaginator($page, $perPage);
        }

        $search = trim((string) $search);

        $products = Product::query()
            ->select([
                'products.id',
                'products.name',
                'products.sku',
                'products.image',
                'products.is_active',
                'products.popularity',
                'products.updated_at',
            ])
            ->whereHas('categories', function (Builder $query) use ($scopeCategoryIds): void {
                $query->whereIn('categories.id', $scopeCategoryIds);
            })
            ->whereNotNull('image')
            ->where('image', '!=', '')
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $searchQuery) use ($search): void {
                    $like = '%'.$search.'%';

                    $searchQuery
                        ->where('products.name', 'like', $like)
                        ->orWhere('products.sku', 'like', $like);
                });
            })
            ->orderByDesc('products.is_active')
            ->orderByDesc('products.popularity')
            ->orderByDesc('products.updated_at')
            ->orderByDesc('products.id')
            ->get();

        $candidates = [];

        foreach ($products as $product) {
            $path = Category::normalizeImagePath($product->image);
            $previewUrl = Category::resolveImageUrl($path);

            if ($path === null || $previewUrl === null || array_key_exists($path, $candidates)) {
                continue;
            }

            $candidates[$path] = [
                'path' => $path,
                'preview_url' => $previewUrl,
                'product_name' => trim((string) $product->name),
                'product_sku' => trim((string) ($product->sku ?? '')),
                'is_active' => (bool) $product->is_active,
            ];
        }

        $items = array_values($candidates);
        $page = max(1, $page);
        $perPage = max(1, $perPage);

        return new LengthAwarePaginator(
            items: array_slice($items, ($page - 1) * $perPage, $perPage),
            total: count($items),
            perPage: $perPage,
            currentPage: $page,
        );
    }

    /**
     * @return array<int, int>
     */
    public function scopeLeafCategoryIds(Category $category): array
    {
        $childrenByParent = Category::query()
            ->select(['id', 'parent_id'])
            ->orderBy('parent_id')
            ->orderBy('id')
            ->get()
            ->groupBy('parent_id');

        $categoryId = (int) $category->getKey();

        if (! isset($childrenByParent[$categoryId])) {
            return [$categoryId];
        }

        $leafCategoryIds = [];

        $walk = function (int $nodeId) use (&$walk, &$leafCategoryIds, $childrenByParent): void {
            $children = $childrenByParent[$nodeId] ?? collect();

            if ($children->isEmpty()) {
                $leafCategoryIds[$nodeId] = $nodeId;

                return;
            }

            foreach ($children as $child) {
                $walk((int) $child->id);
            }
        };

        $walk($categoryId);

        return array_values($leafCategoryIds);
    }

    protected function emptyPaginator(int $page, int $perPage): LengthAwarePaginatorContract
    {
        return new LengthAwarePaginator(
            items: [],
            total: 0,
            perPage: max(1, $perPage),
            currentPage: max(1, $page),
        );
    }
}
