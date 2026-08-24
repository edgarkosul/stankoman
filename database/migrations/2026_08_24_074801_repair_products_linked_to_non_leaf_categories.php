<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('categories')
            || ! Schema::hasTable('products')
            || ! Schema::hasTable('product_categories')) {
            return;
        }

        DB::transaction(function (): void {
            $this->ensureElectricQuadCategory();
            $this->repairUnambiguousLinks();
        });

        Cache::forget('catalog.menu.v2');
    }

    public function down(): void {}

    private function ensureElectricQuadCategory(): void
    {
        $productId = DB::table('products')
            ->where('slug', 'detskii-elektrokvadrocikl-sneg-leto-r-red')
            ->value('id');

        if ($productId === null) {
            return;
        }

        $parentId = DB::table('product_categories')
            ->join('categories', 'categories.id', '=', 'product_categories.category_id')
            ->where('product_categories.product_id', $productId)
            ->whereExists(function ($query): void {
                $query
                    ->selectRaw('1')
                    ->from('categories as child_categories')
                    ->whereColumn('child_categories.parent_id', 'categories.id');
            })
            ->orderByDesc('product_categories.is_primary')
            ->value('categories.id');

        if ($parentId === null || $this->hasLinkedLeafDescendant((int) $productId, (int) $parentId)) {
            return;
        }

        $categoryId = DB::table('categories')
            ->where('parent_id', $parentId)
            ->where('slug', 'elektrokvadrocikly')
            ->value('id');

        if ($categoryId === null) {
            $categoryId = DB::table('categories')->insertGetId([
                'name' => 'Электроквадроциклы',
                'slug' => 'elektrokvadrocikly',
                'parent_id' => $parentId,
                'order' => ((int) DB::table('categories')->where('parent_id', $parentId)->max('order')) + 1,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('product_categories')->insertOrIgnore([
            'product_id' => $productId,
            'category_id' => $categoryId,
            'is_primary' => false,
        ]);
    }

    private function hasLinkedLeafDescendant(int $productId, int $parentId): bool
    {
        $categories = DB::table('categories')
            ->select(['id', 'parent_id'])
            ->get();
        $parentById = $categories->pluck('parent_id', 'id');
        $parentIds = $categories
            ->pluck('parent_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->flip();

        return DB::table('product_categories')
            ->where('product_id', $productId)
            ->pluck('category_id')
            ->contains(function (mixed $categoryId) use ($parentById, $parentIds, $parentId): bool {
                $categoryId = (int) $categoryId;

                return ! $parentIds->has($categoryId)
                    && $this->isDescendantOf($categoryId, $parentId, $parentById);
            });
    }

    private function repairUnambiguousLinks(): void
    {
        $categories = DB::table('categories')
            ->select(['id', 'parent_id'])
            ->get();
        $parentById = $categories->pluck('parent_id', 'id');
        $categoryIdsWithChildren = $categories
            ->pluck('parent_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->flip();

        DB::table('product_categories')
            ->select(['product_id', 'category_id', 'is_primary'])
            ->orderBy('product_id')
            ->get()
            ->groupBy('product_id')
            ->each(function (Collection $links) use ($categoryIdsWithChildren, $parentById): void {
                $links
                    ->filter(fn (object $link): bool => $categoryIdsWithChildren->has((int) $link->category_id))
                    ->each(function (object $invalidLink) use ($links, $categoryIdsWithChildren, $parentById): void {
                        $targetCategoryIds = $links
                            ->reject(fn (object $link): bool => $categoryIdsWithChildren->has((int) $link->category_id))
                            ->filter(fn (object $link): bool => $this->isDescendantOf(
                                (int) $link->category_id,
                                (int) $invalidLink->category_id,
                                $parentById,
                            ))
                            ->pluck('category_id')
                            ->map(static fn (mixed $id): int => (int) $id)
                            ->unique()
                            ->values();

                        if ($targetCategoryIds->count() !== 1) {
                            return;
                        }

                        $productId = (int) $invalidLink->product_id;
                        $targetCategoryId = (int) $targetCategoryIds->first();

                        if ((bool) $invalidLink->is_primary) {
                            DB::table('product_categories')
                                ->where('product_id', $productId)
                                ->update(['is_primary' => false]);

                            DB::table('product_categories')
                                ->where('product_id', $productId)
                                ->where('category_id', $targetCategoryId)
                                ->update(['is_primary' => true]);
                        }

                        DB::table('product_categories')
                            ->where('product_id', $productId)
                            ->where('category_id', $invalidLink->category_id)
                            ->delete();
                    });
            });
    }

    private function isDescendantOf(int $categoryId, int $ancestorId, Collection $parentById): bool
    {
        $visited = [];
        $currentId = $categoryId;

        while ($parentById->has($currentId) && ! isset($visited[$currentId])) {
            $visited[$currentId] = true;
            $currentId = (int) $parentById->get($currentId);

            if ($currentId === $ancestorId) {
                return true;
            }

            if ($currentId <= 0) {
                return false;
            }
        }

        return false;
    }
};
