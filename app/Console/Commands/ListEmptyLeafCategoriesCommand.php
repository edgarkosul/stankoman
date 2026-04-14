<?php

namespace App\Console\Commands;

use App\Models\Category;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class ListEmptyLeafCategoriesCommand extends Command
{
    protected $signature = 'categories:list-empty-leaves
        {--branches : Вывести все пустые ветки вместо пустых концевых категорий}
        {--branch-roots : Вывести только верхние корни пустых веток}';

    protected $description = 'Вывести пустые концевые категории или ветки категорий без товаров';

    public function handle(): int
    {
        $listEmptyBranchRoots = (bool) $this->option('branch-roots');
        $listEmptyBranches = $listEmptyBranchRoots || (bool) $this->option('branches');
        $categories = match (true) {
            $listEmptyBranchRoots => $this->emptyBranchRootCategories(),
            $listEmptyBranches => $this->emptyBranchCategories(),
            default => $this->emptyLeafCategories(),
        };

        if ($categories->isEmpty()) {
            $this->info(
                match (true) {
                    $listEmptyBranchRoots => 'Корни пустых веток категорий не найдены.',
                    $listEmptyBranches => 'Пустые ветки категорий не найдены.',
                    default => 'Концевые категории без товаров не найдены.',
                }
            );

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Name', 'Slug', 'Parent ID'],
            $categories
                ->map(
                    static fn (Category $category): array => [
                        $category->id,
                        $category->name,
                        $category->slug,
                        $category->parent_id,
                    ]
                )
                ->all()
        );

        $this->info('Найдено: '.$categories->count());

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Category>
     */
    private function emptyLeafCategories(): Collection
    {
        return Category::query()
            ->select(['id', 'name', 'slug', 'parent_id'])
            ->leaf()
            ->doesntHave('products')
            ->orderBy('parent_id')
            ->orderBy('order')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, Category>
     */
    private function emptyBranchCategories(): Collection
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

        return $categories
            ->filter(function (Category $category) use ($childrenByParent, $hasProductsInSubtree): bool {
                return $childrenByParent->has($category->id)
                    && ! $hasProductsInSubtree((int) $category->id);
            })
            ->values();
    }

    /**
     * @return Collection<int, Category>
     */
    private function emptyBranchRootCategories(): Collection
    {
        $emptyBranches = $this->emptyBranchCategories();

        if ($emptyBranches->isEmpty()) {
            return $emptyBranches;
        }

        $emptyBranchIds = $emptyBranches
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->flip();

        return $emptyBranches
            ->filter(
                static fn (Category $category): bool => ! $emptyBranchIds->has((int) $category->parent_id)
            )
            ->values();
    }
}
