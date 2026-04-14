<?php

use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\Artisan;

test('it lists only leaf categories without products', function (): void {
    $parentCategory = Category::query()->create([
        'name' => 'Родительская категория',
        'slug' => 'root-category-for-empty-leaf-command',
        'parent_id' => Category::defaultParentKey(),
        'order' => 1,
        'is_active' => true,
    ]);

    $emptyLeafCategory = Category::query()->create([
        'name' => 'Пустая листовая категория',
        'slug' => 'empty-leaf-category',
        'parent_id' => $parentCategory->id,
        'order' => 1,
        'is_active' => true,
    ]);

    $leafCategoryWithProduct = Category::query()->create([
        'name' => 'Листовая категория с товаром',
        'slug' => 'leaf-category-with-product',
        'parent_id' => $parentCategory->id,
        'order' => 2,
        'is_active' => true,
    ]);

    $nonLeafCategory = Category::query()->create([
        'name' => 'Не листовая категория',
        'slug' => 'non-leaf-category',
        'parent_id' => Category::defaultParentKey(),
        'order' => 2,
        'is_active' => true,
    ]);

    Category::query()->create([
        'name' => 'Дочерняя категория',
        'slug' => 'child-of-non-leaf-category',
        'parent_id' => $nonLeafCategory->id,
        'order' => 1,
        'is_active' => true,
    ]);

    $product = Product::query()->create([
        'name' => 'Тестовый товар',
        'slug' => 'test-product-for-empty-leaf-command',
        'price_amount' => 1000,
        'currency' => 'RUB',
    ]);

    $leafCategoryWithProduct->products()->attach($product->id, [
        'is_primary' => true,
    ]);

    $exitCode = Artisan::call('categories:list-empty-leaves');
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Пустая листовая категория')
        ->and($output)->toContain('empty-leaf-category')
        ->and($output)->not->toContain('Листовая категория с товаром')
        ->and($output)->not->toContain('Не листовая категория');
});

test('it reports when there are no empty leaf categories', function (): void {
    $parentCategory = Category::query()->create([
        'name' => 'Родительская категория',
        'slug' => 'root-category-without-empty-leaves',
        'parent_id' => Category::defaultParentKey(),
        'order' => 1,
        'is_active' => true,
    ]);

    $leafCategoryWithProduct = Category::query()->create([
        'name' => 'Листовая категория с товаром',
        'slug' => 'leaf-category-with-product-only',
        'parent_id' => $parentCategory->id,
        'order' => 1,
        'is_active' => true,
    ]);

    $product = Product::query()->create([
        'name' => 'Тестовый товар без пустых листов',
        'slug' => 'test-product-without-empty-leaves',
        'price_amount' => 2000,
        'currency' => 'RUB',
    ]);

    $leafCategoryWithProduct->products()->attach($product->id, [
        'is_primary' => true,
    ]);

    $exitCode = Artisan::call('categories:list-empty-leaves');
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Концевые категории без товаров не найдены.');
});
