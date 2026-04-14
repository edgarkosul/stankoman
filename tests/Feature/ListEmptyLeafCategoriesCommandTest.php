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

test('it lists only empty category branches when branches option is enabled', function (): void {
    $emptyRoot = Category::query()->create([
        'name' => 'Пустая ветка',
        'slug' => 'empty-branch-root',
        'parent_id' => Category::defaultParentKey(),
        'order' => 1,
        'is_active' => true,
    ]);

    $emptyIntermediate = Category::query()->create([
        'name' => 'Пустая вложенная ветка',
        'slug' => 'empty-branch-child',
        'parent_id' => $emptyRoot->id,
        'order' => 1,
        'is_active' => true,
    ]);

    Category::query()->create([
        'name' => 'Пустой лист',
        'slug' => 'empty-branch-leaf',
        'parent_id' => $emptyIntermediate->id,
        'order' => 1,
        'is_active' => true,
    ]);

    $nonEmptyRoot = Category::query()->create([
        'name' => 'Непустая ветка',
        'slug' => 'non-empty-branch-root',
        'parent_id' => Category::defaultParentKey(),
        'order' => 2,
        'is_active' => true,
    ]);

    $nonEmptyLeaf = Category::query()->create([
        'name' => 'Лист с товаром',
        'slug' => 'non-empty-branch-leaf',
        'parent_id' => $nonEmptyRoot->id,
        'order' => 1,
        'is_active' => true,
    ]);

    Category::query()->create([
        'name' => 'Одиночный пустой лист',
        'slug' => 'standalone-empty-leaf',
        'parent_id' => Category::defaultParentKey(),
        'order' => 3,
        'is_active' => true,
    ]);

    $product = Product::query()->create([
        'name' => 'Товар для непустой ветки',
        'slug' => 'test-product-for-non-empty-branch',
        'price_amount' => 3000,
        'currency' => 'RUB',
    ]);

    $nonEmptyLeaf->products()->attach($product->id, [
        'is_primary' => true,
    ]);

    $exitCode = Artisan::call('categories:list-empty-leaves', [
        '--branches' => true,
    ]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Пустая ветка')
        ->and($output)->toContain('Пустая вложенная ветка')
        ->and($output)->toContain('empty-branch-root')
        ->and($output)->toContain('empty-branch-child')
        ->and($output)->not->toContain('Пустой лист')
        ->and($output)->not->toContain('Непустая ветка')
        ->and($output)->not->toContain('Одиночный пустой лист');
});

test('it reports when there are no empty category branches', function (): void {
    $rootCategory = Category::query()->create([
        'name' => 'Корневая ветка',
        'slug' => 'root-branch-with-products',
        'parent_id' => Category::defaultParentKey(),
        'order' => 1,
        'is_active' => true,
    ]);

    $leafCategoryWithProduct = Category::query()->create([
        'name' => 'Листовая категория с товаром',
        'slug' => 'branch-leaf-with-product',
        'parent_id' => $rootCategory->id,
        'order' => 1,
        'is_active' => true,
    ]);

    Category::query()->create([
        'name' => 'Одиночный пустой лист',
        'slug' => 'branch-standalone-empty-leaf',
        'parent_id' => Category::defaultParentKey(),
        'order' => 2,
        'is_active' => true,
    ]);

    $product = Product::query()->create([
        'name' => 'Товар для ветки',
        'slug' => 'test-product-for-branch-command',
        'price_amount' => 4000,
        'currency' => 'RUB',
    ]);

    $leafCategoryWithProduct->products()->attach($product->id, [
        'is_primary' => true,
    ]);

    $exitCode = Artisan::call('categories:list-empty-leaves', [
        '--branches' => true,
    ]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Пустые ветки категорий не найдены.');
});
