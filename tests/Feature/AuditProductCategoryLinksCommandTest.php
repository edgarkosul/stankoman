<?php

use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

it('passes when every product is linked only to leaf categories', function (): void {
    $category = Category::query()->create([
        'name' => 'Корректная листовая категория',
        'slug' => 'valid-leaf-for-category-audit',
        'parent_id' => Category::defaultParentKey(),
        'order' => 1,
        'is_active' => true,
    ]);
    $product = Product::query()->create([
        'name' => 'Корректно привязанный товар',
        'slug' => 'valid-product-for-category-audit',
        'price_amount' => 1000,
    ]);
    $category->products()->attach($product->getKey(), ['is_primary' => true]);

    $exitCode = Artisan::call('categories:audit-product-links');

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('Привязок товаров к нелистовым категориям не найдено.');
});

it('fails and reports products linked directly to non leaf categories without changing data', function (): void {
    $parent = Category::query()->create([
        'name' => 'Нарушающая категория',
        'slug' => 'invalid-parent-for-category-audit',
        'parent_id' => Category::defaultParentKey(),
        'order' => 1,
        'is_active' => true,
    ]);
    $product = Product::query()->create([
        'name' => 'Товар с нарушением',
        'slug' => 'invalid-product-for-category-audit',
        'price_amount' => 1000,
    ]);
    $parent->products()->attach($product->getKey(), ['is_primary' => true]);

    DB::table('categories')->insert([
        'name' => 'Добавленная в обход защиты подкатегория',
        'slug' => 'raw-child-for-category-audit',
        'parent_id' => $parent->getKey(),
        'order' => 1,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $exitCode = Artisan::call('categories:audit-product-links');

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())
        ->toContain('Товар с нарушением')
        ->toContain('Нарушающая категория')
        ->toContain('Найдено некорректных привязок: 1.')
        ->and(DB::table('product_categories')
            ->where('product_id', $product->getKey())
            ->where('category_id', $parent->getKey())
            ->exists())->toBeTrue();
});
