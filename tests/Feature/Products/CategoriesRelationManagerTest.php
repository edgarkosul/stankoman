<?php

use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\RelationManagers\CategoriesRelationManager;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseHas;

test('it attaches a leaf category in product categories relation manager', function (): void {
    $this->actingAs(User::factory()->create());

    $product = Product::query()->create([
        'name' => 'Тестовый товар',
        'slug' => 'test-product-categories-relation-manager',
        'price_amount' => 5000,
    ]);

    $parentCategory = Category::query()->create([
        'name' => 'Родительская категория',
        'slug' => 'parent-category-relation-manager-test',
        'parent_id' => -1,
        'order' => 10,
        'is_active' => true,
    ]);

    $leafCategory = Category::query()->create([
        'name' => 'Листовая категория',
        'slug' => 'leaf-category-relation-manager-test',
        'parent_id' => $parentCategory->id,
        'order' => 11,
        'is_active' => true,
    ]);

    Livewire::test(CategoriesRelationManager::class, [
        'ownerRecord' => $product,
        'pageClass' => EditProduct::class,
    ])
        ->callAction(TestAction::make('attachCategory')->table(), [
            'recordId' => $leafCategory->id,
        ])
        ->assertNotified();

    assertDatabaseHas('product_categories', [
        'product_id' => $product->id,
        'category_id' => $leafCategory->id,
        'is_primary' => 0,
    ]);
});

test('saving edit product page keeps category that was attached after initial form hydration', function (): void {
    $this->actingAs(User::factory()->create());

    $product = Product::query()->create([
        'name' => 'Тестовый товар для сохранения',
        'slug' => 'test-product-edit-save-categories-relation-manager',
        'price_amount' => 5000,
        'is_active' => false,
    ]);

    $rootCategory = Category::query()->create([
        'name' => 'Корневая категория',
        'slug' => 'root-category-edit-save-categories-test',
        'parent_id' => -1,
        'order' => 20,
        'is_active' => true,
    ]);

    $leafCategory = Category::query()->create([
        'name' => 'Листовая категория для сохранения',
        'slug' => 'leaf-category-edit-save-categories-test',
        'parent_id' => $rootCategory->id,
        'order' => 21,
        'is_active' => true,
    ]);

    $editPage = Livewire::test(EditProduct::class, [
        'record' => $product->getRouteKey(),
    ]);

    $product->categories()->attach($leafCategory->id, ['is_primary' => false]);

    $editPage
        ->call('save')
        ->assertHasNoErrors();

    assertDatabaseHas('product_categories', [
        'product_id' => $product->id,
        'category_id' => $leafCategory->id,
        'is_primary' => 0,
    ]);
});
