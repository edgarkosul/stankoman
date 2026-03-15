<?php

use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Support\Products\CategoryProductImageCandidates;
use Livewire\Livewire;

test('create category page asks to save record before choosing product image', function (): void {
    $user = User::factory()->create();

    config([
        'filament_admin.emails' => [strtolower((string) $user->email)],
    ]);

    $this->actingAs($user);

    Livewire::test(CreateCategory::class)
        ->assertSee('Сохраните категорию, затем выберите изображение из товаров.')
        ->assertDontSee('Выбрать из товаров');
});

test('edit category page uses deduplicated descendant product images and saves normalized image path', function (): void {
    $user = User::factory()->create();

    config([
        'filament_admin.emails' => [strtolower((string) $user->email)],
    ]);

    $this->actingAs($user);

    $parentCategory = Category::query()->create([
        'name' => 'Родительская категория',
        'slug' => 'edit-category-image-picker-parent',
        'parent_id' => -1,
        'order' => 1,
        'is_active' => true,
    ]);

    $leafOne = Category::query()->create([
        'name' => 'Лист 1',
        'slug' => 'edit-category-image-picker-leaf-one',
        'parent_id' => $parentCategory->id,
        'order' => 2,
        'is_active' => true,
    ]);

    $leafTwo = Category::query()->create([
        'name' => 'Лист 2',
        'slug' => 'edit-category-image-picker-leaf-two',
        'parent_id' => $parentCategory->id,
        'order' => 3,
        'is_active' => true,
    ]);

    $otherRoot = Category::query()->create([
        'name' => 'Посторонняя категория',
        'slug' => 'edit-category-image-picker-other-root',
        'parent_id' => -1,
        'order' => 4,
        'is_active' => true,
    ]);

    $otherLeaf = Category::query()->create([
        'name' => 'Посторонний лист',
        'slug' => 'edit-category-image-picker-other-leaf',
        'parent_id' => $otherRoot->id,
        'order' => 5,
        'is_active' => true,
    ]);

    $primaryCandidate = Product::query()->create([
        'name' => 'Leaf One Product',
        'slug' => 'edit-category-image-picker-leaf-one-product',
        'price_amount' => 10_000,
        'image' => '/storage/pics/shared-leaf-image.jpg',
        'is_active' => true,
        'popularity' => 500,
    ]);

    $duplicateCandidate = Product::query()->create([
        'name' => 'Leaf Two Duplicate',
        'slug' => 'edit-category-image-picker-leaf-two-duplicate',
        'price_amount' => 11_000,
        'image' => 'pics/shared-leaf-image.jpg',
        'is_active' => true,
        'popularity' => 10,
    ]);

    $externalCandidate = Product::query()->create([
        'name' => 'External Product',
        'slug' => 'edit-category-image-picker-external-product',
        'price_amount' => 12_000,
        'image' => 'https://cdn.example.test/products/external-image.jpg',
        'is_active' => true,
        'popularity' => 100,
    ]);

    $galleryOnlyProduct = Product::query()->create([
        'name' => 'Gallery Only Product',
        'slug' => 'edit-category-image-picker-gallery-only-product',
        'price_amount' => 13_000,
        'thumb' => 'pics/thumb-only.jpg',
        'gallery' => ['pics/gallery-only.jpg'],
        'is_active' => true,
        'popularity' => 50,
    ]);

    $unrelatedProduct = Product::query()->create([
        'name' => 'Unrelated Product',
        'slug' => 'edit-category-image-picker-unrelated-product',
        'price_amount' => 14_000,
        'image' => 'pics/unrelated-image.jpg',
        'is_active' => true,
        'popularity' => 700,
    ]);

    $primaryCandidate->categories()->attach($leafOne->id, ['is_primary' => true]);
    $duplicateCandidate->categories()->attach($leafTwo->id, ['is_primary' => true]);
    $externalCandidate->categories()->attach($leafTwo->id, ['is_primary' => true]);
    $galleryOnlyProduct->categories()->attach($leafOne->id, ['is_primary' => true]);
    $unrelatedProduct->categories()->attach($otherLeaf->id, ['is_primary' => true]);

    $candidates = app(CategoryProductImageCandidates::class)->paginate($parentCategory);

    expect($candidates->total())->toBe(2)
        ->and(collect($candidates->items())->pluck('product_name')->all())->toBe([
            'Leaf One Product',
            'External Product',
        ])
        ->and(collect($candidates->items())->pluck('path')->all())->toBe([
            'pics/shared-leaf-image.jpg',
            'https://cdn.example.test/products/external-image.jpg',
        ]);

    Livewire::test(EditCategory::class, [
        'record' => $parentCategory->getRouteKey(),
    ])
        ->call('selectCategoryImage', '/storage/pics/shared-leaf-image.jpg')
        ->call('save')
        ->assertHasNoFormErrors();

    expect($parentCategory->refresh()->img)->toBe('pics/shared-leaf-image.jpg')
        ->and($parentCategory->image_url)->toEndWith('/storage/pics/shared-leaf-image.jpg');
});
