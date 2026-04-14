<?php

use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\Artisan;

test('it reports all recursively empty categories in dry-run mode without deleting them', function (): void {
    $emptyRoot = Category::query()->create([
        'name' => 'Пустая ветка',
        'slug' => 'empty-prune-root',
        'parent_id' => Category::defaultParentKey(),
        'order' => 1,
        'is_active' => true,
    ]);

    $emptyBranch = Category::query()->create([
        'name' => 'Пустая вложенная ветка',
        'slug' => 'empty-prune-branch',
        'parent_id' => $emptyRoot->id,
        'order' => 1,
        'is_active' => true,
    ]);

    $emptyLeaf = Category::query()->create([
        'name' => 'Пустой лист',
        'slug' => 'empty-prune-leaf',
        'parent_id' => $emptyBranch->id,
        'order' => 1,
        'is_active' => true,
    ]);

    $standaloneLeaf = Category::query()->create([
        'name' => 'Одиночный пустой лист',
        'slug' => 'standalone-empty-prune-leaf',
        'parent_id' => Category::defaultParentKey(),
        'order' => 2,
        'is_active' => true,
    ]);

    $nonEmptyRoot = Category::query()->create([
        'name' => 'Непустая ветка',
        'slug' => 'non-empty-prune-root',
        'parent_id' => Category::defaultParentKey(),
        'order' => 3,
        'is_active' => true,
    ]);

    $nonEmptyLeaf = Category::query()->create([
        'name' => 'Лист с товаром',
        'slug' => 'non-empty-prune-leaf',
        'parent_id' => $nonEmptyRoot->id,
        'order' => 1,
        'is_active' => true,
    ]);

    $product = Product::query()->create([
        'name' => 'Товар для непустой ветки',
        'slug' => 'product-for-prune-command-dry-run',
        'price_amount' => 1000,
        'currency' => 'RUB',
    ]);

    $nonEmptyLeaf->products()->attach($product->id, [
        'is_primary' => true,
    ]);

    $exitCode = Artisan::call('categories:prune-empty');
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Режим: dry-run')
        ->and($output)->toContain('Пустая ветка')
        ->and($output)->toContain('Пустая вложенная ветка')
        ->and($output)->toContain('Пустой лист')
        ->and($output)->toContain('Одиночный пустой лист')
        ->and($output)->toContain('Категорий к удалению: 4')
        ->and($output)->toContain('Веток: 2')
        ->and($output)->toContain('Листьев: 2')
        ->and($output)->toContain('Dry-run: данные не изменены.')
        ->and(Category::query()->whereKey($emptyRoot->id)->exists())->toBeTrue()
        ->and(Category::query()->whereKey($emptyBranch->id)->exists())->toBeTrue()
        ->and(Category::query()->whereKey($emptyLeaf->id)->exists())->toBeTrue()
        ->and(Category::query()->whereKey($standaloneLeaf->id)->exists())->toBeTrue()
        ->and(Category::query()->whereKey($nonEmptyRoot->id)->exists())->toBeTrue()
        ->and(Category::query()->whereKey($nonEmptyLeaf->id)->exists())->toBeTrue();
});

test('it deletes all recursively empty categories in write mode', function (): void {
    $emptyRoot = Category::query()->create([
        'name' => 'Удаляемая ветка',
        'slug' => 'deletable-empty-root',
        'parent_id' => Category::defaultParentKey(),
        'order' => 1,
        'is_active' => true,
    ]);

    $emptyLeaf = Category::query()->create([
        'name' => 'Удаляемый лист',
        'slug' => 'deletable-empty-leaf',
        'parent_id' => $emptyRoot->id,
        'order' => 1,
        'is_active' => true,
    ]);

    $nonEmptyRoot = Category::query()->create([
        'name' => 'Оставляемая ветка',
        'slug' => 'kept-non-empty-root',
        'parent_id' => Category::defaultParentKey(),
        'order' => 2,
        'is_active' => true,
    ]);

    $nonEmptyLeaf = Category::query()->create([
        'name' => 'Оставляемый лист',
        'slug' => 'kept-non-empty-leaf',
        'parent_id' => $nonEmptyRoot->id,
        'order' => 1,
        'is_active' => true,
    ]);

    $product = Product::query()->create([
        'name' => 'Товар для write режима',
        'slug' => 'product-for-prune-command-write',
        'price_amount' => 2000,
        'currency' => 'RUB',
    ]);

    $nonEmptyLeaf->products()->attach($product->id, [
        'is_primary' => true,
    ]);

    $exitCode = Artisan::call('categories:prune-empty', [
        '--write' => true,
    ]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Режим: write')
        ->and($output)->toContain('Удалено категорий: 2')
        ->and(Category::query()->whereKey($emptyRoot->id)->exists())->toBeFalse()
        ->and(Category::query()->whereKey($emptyLeaf->id)->exists())->toBeFalse()
        ->and(Category::query()->whereKey($nonEmptyRoot->id)->exists())->toBeTrue()
        ->and(Category::query()->whereKey($nonEmptyLeaf->id)->exists())->toBeTrue();
});
