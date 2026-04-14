<?php

namespace App\Support\Categories;

use App\Models\Category;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EmptyCategoryPruner
{
    /**
     * @return array{
     *     categories: Collection<int, Category>,
     *     branch_count: int,
     *     leaf_count: int
     * }
     */
    public function plan(): array
    {
        $categories = Category::query()
            ->select(['id', 'name', 'slug', 'parent_id', 'order'])
            ->withCount('products')
            ->orderBy('parent_id')
            ->orderBy('order')
            ->orderBy('id')
            ->get();

        $categoriesById = $categories->keyBy('id');
        $childrenByParent = $categories->groupBy('parent_id');
        $subtreeHasProducts = [];
        $depths = [];

        $resolveDepth = function (int $categoryId) use (&$resolveDepth, &$depths, $categoriesById): int {
            if (array_key_exists($categoryId, $depths)) {
                return $depths[$categoryId];
            }

            /** @var Category|null $category */
            $category = $categoriesById->get($categoryId);

            if ($category === null || (int) $category->parent_id === Category::defaultParentKey()) {
                return $depths[$categoryId] = 0;
            }

            return $depths[$categoryId] = $resolveDepth((int) $category->parent_id) + 1;
        };

        $hasProductsInSubtree = function (int $categoryId) use (&$hasProductsInSubtree, &$subtreeHasProducts, $categoriesById, $childrenByParent): bool {
            if (array_key_exists($categoryId, $subtreeHasProducts)) {
                return $subtreeHasProducts[$categoryId];
            }

            /** @var Category|null $category */
            $category = $categoriesById->get($categoryId);

            if ($category === null) {
                return $subtreeHasProducts[$categoryId] = false;
            }

            $hasProducts = ((int) $category->products_count) > 0;

            foreach ($childrenByParent->get($categoryId, collect()) as $child) {
                if ($hasProductsInSubtree((int) $child->id)) {
                    $hasProducts = true;
                }
            }

            return $subtreeHasProducts[$categoryId] = $hasProducts;
        };

        /** @var Collection<int, Category> $plannedCategories */
        $plannedCategories = $categories
            ->filter(function (Category $category) use ($hasProductsInSubtree, $resolveDepth, $childrenByParent): bool {
                if ($hasProductsInSubtree((int) $category->id)) {
                    return false;
                }

                $category->setAttribute('depth', $resolveDepth((int) $category->id));
                $category->setAttribute(
                    'node_type',
                    $childrenByParent->has($category->id) ? 'branch' : 'leaf'
                );

                return true;
            })
            ->values();

        return [
            'categories' => $plannedCategories,
            'branch_count' => $plannedCategories->where('node_type', 'branch')->count(),
            'leaf_count' => $plannedCategories->where('node_type', 'leaf')->count(),
        ];
    }

    /**
     * @param  Collection<int, Category>  $categories
     */
    public function prune(Collection $categories): int
    {
        if ($categories->isEmpty()) {
            return 0;
        }

        return DB::transaction(function () use ($categories): int {
            $deleted = 0;

            $categories
                ->sortByDesc(fn (Category $category): int => (int) ($category->getAttribute('depth') ?? 0))
                ->each(function (Category $category) use (&$deleted): void {
                    if ($category->delete()) {
                        $deleted++;
                    }
                });

            return $deleted;
        });
    }
}
