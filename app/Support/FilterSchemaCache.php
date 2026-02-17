<?php

namespace App\Support;

use App\Models\Category;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class FilterSchemaCache
{
    public static function key(int $categoryId): string
    {
        return "filters:schema:cat:{$categoryId}";
    }

    public static function forgetCategory(int $categoryId): void
    {
        Cache::forget(self::key($categoryId));
    }

    public static function forgetByAttribute(int $attributeId): void
    {
        if ($attributeId <= 0) {
            return;
        }

        if (! self::hasTables(['categories', 'attributes', 'category_attribute'])) {
            return;
        }

        self::forgetCategories(
            Category::query()
                ->whereHas('attributeDefs', fn ($q) => $q->whereKey($attributeId))
                ->pluck('id')
        );
    }

    public static function forgetByProductAttribute(int $productId, int $attributeId): void
    {
        if ($productId <= 0 || $attributeId <= 0) {
            return;
        }

        if (! self::hasTables(['product_categories', 'category_attribute'])) {
            return;
        }

        DB::table('product_categories as pc')
            ->join('category_attribute as ca', function ($join) use ($attributeId) {
                $join->on('ca.category_id', '=', 'pc.category_id')
                    ->where('ca.attribute_id', '=', $attributeId);
            })
            ->where('pc.product_id', $productId)
            ->pluck('pc.category_id')
            ->map(fn ($categoryId) => (int) $categoryId)
            ->unique()
            ->each(fn (int $categoryId) => self::forgetCategory($categoryId));
    }

    public static function forgetByProduct(int $productId): void
    {
        if ($productId <= 0) {
            return;
        }

        if (! self::hasTables(['product_categories'])) {
            return;
        }

        self::forgetCategories(
            DB::table('product_categories')
                ->where('product_id', $productId)
                ->pluck('category_id')
        );
    }

    /**
     * @param  iterable<int>|Collection<int, int>  $categoryIds
     */
    public static function forgetCategories(iterable|Collection $categoryIds): void
    {
        collect($categoryIds)
            ->map(fn ($categoryId) => (int) $categoryId)
            ->filter(fn (int $categoryId) => $categoryId > 0)
            ->unique()
            ->each(fn (int $categoryId) => self::forgetCategory($categoryId));
    }

    /**
     * @param  array<int, string>  $tables
     */
    private static function hasTables(array $tables): bool
    {
        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                return false;
            }
        }

        return true;
    }
}
