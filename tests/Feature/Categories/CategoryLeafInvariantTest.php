<?php

use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

it('rejects creating a child inside a category with products at model level', function (): void {
    $occupiedCategory = Category::query()->create([
        'name' => 'Категория с товаром',
        'slug' => 'occupied-category-for-child-invariant',
        'parent_id' => Category::defaultParentKey(),
        'order' => 1,
        'is_active' => true,
    ]);
    $product = Product::query()->create([
        'name' => 'Товар в занятой категории',
        'slug' => 'product-in-occupied-category',
        'price_amount' => 1000,
    ]);
    $occupiedCategory->products()->attach($product->getKey(), ['is_primary' => true]);

    expect(fn () => Category::query()->create([
        'name' => 'Недопустимая подкатегория',
        'slug' => 'invalid-child-of-occupied-category',
        'parent_id' => $occupiedCategory->getKey(),
        'order' => 1,
        'is_active' => true,
    ]))->toThrow(ValidationException::class);

    expect(Category::query()->where('slug', 'invalid-child-of-occupied-category')->exists())->toBeFalse();
});

it('rejects moving a category inside a category with products', function (): void {
    $occupiedCategory = Category::query()->create([
        'name' => 'Занятая категория для перемещения',
        'slug' => 'occupied-category-for-moving',
        'parent_id' => Category::defaultParentKey(),
        'order' => 1,
        'is_active' => true,
    ]);
    $sourceParent = Category::query()->create([
        'name' => 'Исходный родитель',
        'slug' => 'source-parent-for-moving',
        'parent_id' => Category::defaultParentKey(),
        'order' => 2,
        'is_active' => true,
    ]);
    $child = Category::query()->create([
        'name' => 'Перемещаемая категория',
        'slug' => 'moving-category',
        'parent_id' => $sourceParent->getKey(),
        'order' => 1,
        'is_active' => true,
    ]);
    $product = Product::query()->create([
        'name' => 'Товар в цели перемещения',
        'slug' => 'product-in-moving-target',
        'price_amount' => 1000,
    ]);
    $occupiedCategory->products()->attach($product->getKey(), ['is_primary' => true]);

    expect(fn () => $child->update(['parent_id' => $occupiedCategory->getKey()]))
        ->toThrow(ValidationException::class);

    expect($child->fresh()?->parent_id)->toBe($sourceParent->getKey());
});

it('rejects attaching a product to a non leaf category', function (): void {
    $parentCategory = Category::query()->create([
        'name' => 'Нелистовая категория',
        'slug' => 'non-leaf-for-product-attach',
        'parent_id' => Category::defaultParentKey(),
        'order' => 1,
        'is_active' => true,
    ]);
    Category::query()->create([
        'name' => 'Листовая подкатегория',
        'slug' => 'leaf-under-non-leaf-for-product-attach',
        'parent_id' => $parentCategory->getKey(),
        'order' => 1,
        'is_active' => true,
    ]);
    $product = Product::query()->create([
        'name' => 'Товар для нелистовой категории',
        'slug' => 'product-for-non-leaf-category',
        'price_amount' => 1000,
    ]);

    expect(fn () => $parentCategory->products()->attach($product->getKey(), ['is_primary' => true]))
        ->toThrow(ValidationException::class);

    expect($product->categories()->exists())->toBeFalse();
});

it('keeps the old primary category when the target is invalid and switches atomically when valid', function (): void {
    $root = Category::query()->create([
        'name' => 'Корень для основных категорий',
        'slug' => 'root-for-primary-category',
        'parent_id' => Category::defaultParentKey(),
        'order' => 1,
        'is_active' => true,
    ]);
    $firstLeaf = Category::query()->create([
        'name' => 'Первая листовая',
        'slug' => 'first-primary-leaf',
        'parent_id' => $root->getKey(),
        'order' => 1,
        'is_active' => true,
    ]);
    $secondLeaf = Category::query()->create([
        'name' => 'Вторая листовая',
        'slug' => 'second-primary-leaf',
        'parent_id' => $root->getKey(),
        'order' => 2,
        'is_active' => true,
    ]);
    $product = Product::query()->create([
        'name' => 'Товар со сменой основной категории',
        'slug' => 'product-changing-primary-category',
        'price_amount' => 1000,
    ]);
    $product->categories()->attach($firstLeaf->getKey(), ['is_primary' => true]);
    $product->categories()->attach($secondLeaf->getKey(), ['is_primary' => false]);

    DB::table('product_categories')->insert([
        'product_id' => $product->getKey(),
        'category_id' => $root->getKey(),
        'is_primary' => false,
    ]);

    expect(fn () => $product->setPrimaryCategory($root))->toThrow(ValidationException::class);
    expect($product->primaryCategory()?->getKey())->toBe($firstLeaf->getKey());

    $product->setPrimaryCategory($secondLeaf);

    expect($product->primaryCategory()?->getKey())->toBe($secondLeaf->getKey())
        ->and(DB::table('product_categories')
            ->where('product_id', $product->getKey())
            ->where('is_primary', true)
            ->count())->toBe(1);
});
