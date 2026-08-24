<?php

use App\Filament\Pages\CategoryTree;
use App\Filament\Resources\Categories\CategoryResource;
use App\Models\Category;
use App\Models\Product;
use SolutionForest\FilamentTree\Actions\Action;

test('category tree create child action opens category create page with selected parent', function (): void {
    $parentCategory = Category::query()->create([
        'name' => 'Компрессоры',
        'slug' => 'kompressory',
        'parent_id' => Category::defaultParentKey(),
        'order' => 1,
        'is_active' => true,
    ]);

    $page = new CategoryTree;

    $method = new ReflectionMethod(CategoryTree::class, 'getTreeActions');
    $method->setAccessible(true);

    /** @var array<int, Action> $actions */
    $actions = $method->invoke($page);

    $createChildAction = collect($actions)->first(
        fn (Action $action): bool => $action->getName() === 'createChild'
    );

    expect($createChildAction)->toBeInstanceOf(Action::class)
        ->and($createChildAction?->record($parentCategory)->getUrl())
        ->toBe(CategoryResource::getUrl('create', ['parent_id' => $parentCategory]));
});

test('category tree hides create child action for a category with products', function (): void {
    $parentCategory = Category::query()->create([
        'name' => 'Категория с товаром',
        'slug' => 'category-with-product',
        'parent_id' => Category::defaultParentKey(),
        'order' => 2,
        'is_active' => true,
    ]);

    $product = Product::query()->create([
        'name' => 'Товар без допустимой подкатегории',
        'slug' => 'product-without-allowed-child-category',
        'price_amount' => 1000,
    ]);

    $parentCategory->products()->attach($product->getKey(), ['is_primary' => true]);

    $page = new CategoryTree;
    $method = new ReflectionMethod(CategoryTree::class, 'getTreeActions');
    $method->setAccessible(true);
    $createChildAction = collect($method->invoke($page))->first(
        fn (Action $action): bool => $action->getName() === 'createChild'
    );

    expect($createChildAction?->record($parentCategory)->isVisible())->toBeFalse();
});
