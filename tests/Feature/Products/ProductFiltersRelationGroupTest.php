<?php

use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Products\RelationManagers\AttributeOptionsRelationManager;
use App\Filament\Resources\Products\RelationManagers\AttributeValuesRelationManager;
use App\Filament\Resources\Products\RelationManagers\CategoriesRelationManager;
use App\Models\Product;
use App\Models\User;
use Filament\Resources\RelationManagers\RelationGroup;
use Livewire\Livewire;

test('product resource groups filter relation managers under filters tab', function (): void {
    $product = Product::query()->create([
        'name' => 'Товар для проверки relation group',
        'slug' => 'product-filters-relation-group-definition-test',
        'price_amount' => 9100,
    ]);

    $relations = ProductResource::getRelations();

    expect($relations)
        ->toHaveCount(2)
        ->and($relations[0])->toBeInstanceOf(RelationGroup::class)
        ->and($relations[0]->getLabel())->toBe('Фильтры')
        ->and($relations[0]->getManagers())->toBe([
            AttributeValuesRelationManager::class,
            AttributeOptionsRelationManager::class,
        ])
        ->and(AttributeValuesRelationManager::getTitle($product, EditProduct::class))->toBe('Свободные значения')
        ->and(AttributeOptionsRelationManager::getTitle($product, EditProduct::class))->toBe('Заданные варианты')
        ->and($relations[1])->toBe(CategoriesRelationManager::class);
});

test('edit product page renders grouped filter relation managers', function (): void {
    $this->actingAs(User::factory()->create());

    $product = Product::query()->create([
        'name' => 'Товар для вкладки фильтров',
        'slug' => 'product-filters-relation-group-test',
        'price_amount' => 9000,
    ]);

    Livewire::test(EditProduct::class, [
        'record' => $product->getRouteKey(),
    ])
        ->assertSee('Фильтры')
        ->assertSeeLivewire(AttributeValuesRelationManager::class)
        ->assertSeeLivewire(AttributeOptionsRelationManager::class);
});
