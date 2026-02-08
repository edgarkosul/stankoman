<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use App\Models\Category;

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
        Category::query()
            ->whereHas('attributeDefs', fn($q) => $q->whereKey($attributeId))
            ->pluck('id')
            ->each(fn ($cid) => self::forgetCategory($cid));
    }
}
