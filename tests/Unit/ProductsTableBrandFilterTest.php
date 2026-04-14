<?php

use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\Tables\ProductsTable;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Filament\Tables\Filters\BaseFilter;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('adds a searchable brand filter with distinct non-empty options', function () {
    Product::query()->create([
        'name' => 'Bosch drill',
        'slug' => 'bosch-drill',
        'brand' => 'Bosch',
        'price_amount' => 1000,
    ]);

    Product::query()->create([
        'name' => 'Makita drill',
        'slug' => 'makita-drill',
        'brand' => 'Makita',
        'price_amount' => 1200,
    ]);

    Product::query()->create([
        'name' => 'Blank brand product',
        'slug' => 'blank-brand-product',
        'brand' => '',
        'price_amount' => 900,
    ]);

    Product::query()->create([
        'name' => 'Null brand product',
        'slug' => 'null-brand-product',
        'brand' => null,
        'price_amount' => 800,
    ]);

    $filter = configuredProductsTableFilter('brand');

    expect($filter)->toBeInstanceOf(BaseFilter::class)
        ->and($filter->getOptions())->toBe([
            'Bosch' => 'Bosch',
            'Makita' => 'Makita',
        ])
        ->and($filter->getSearchable())->toBeTrue()
        ->and($filter->isMultiple())->toBeTrue();
});

it('adds a searchable multi-select categories filter', function () {
    Category::query()->create([
        'name' => 'Category A',
        'slug' => 'category-a',
        'parent_id' => -1,
        'order' => 1,
    ]);

    $filter = configuredProductsTableFilter('categories');

    expect($filter)->toBeInstanceOf(BaseFilter::class)
        ->and($filter->getSearchable())->toBeTrue()
        ->and($filter->isMultiple())->toBeTrue();
});

it('filters the products table by multiple brands for bulk selection workflows', function () {
    $this->actingAs(User::factory()->create());

    $bosch = Product::query()->create([
        'name' => 'Bosch drill',
        'slug' => 'bosch-drill-table',
        'brand' => 'Bosch',
        'price_amount' => 1000,
    ]);

    $makita = Product::query()->create([
        'name' => 'Makita drill',
        'slug' => 'makita-drill-table',
        'brand' => 'Makita',
        'price_amount' => 1200,
    ]);

    $dewalt = Product::query()->create([
        'name' => 'Dewalt drill',
        'slug' => 'dewalt-drill-table',
        'brand' => 'Dewalt',
        'price_amount' => 1400,
    ]);

    Livewire::test(ListProducts::class)
        ->filterTable('staging_category', false)
        ->filterTable('brand', ['Bosch', 'Makita'])
        ->assertCanSeeTableRecords([$bosch, $makita])
        ->assertCanNotSeeTableRecords([$dewalt]);
});

it('filters the products table by multiple categories', function () {
    $this->actingAs(User::factory()->create());

    $firstCategory = Category::query()->create([
        'name' => 'Category A',
        'slug' => 'category-a',
        'parent_id' => -1,
        'order' => 1,
    ]);

    $secondCategory = Category::query()->create([
        'name' => 'Category B',
        'slug' => 'category-b',
        'parent_id' => -1,
        'order' => 2,
    ]);

    $thirdCategory = Category::query()->create([
        'name' => 'Category C',
        'slug' => 'category-c',
        'parent_id' => -1,
        'order' => 3,
    ]);

    $firstProduct = Product::query()->create([
        'name' => 'Product in category A',
        'slug' => 'product-in-category-a',
        'price_amount' => 1000,
    ]);
    $firstProduct->categories()->attach($firstCategory->id, ['is_primary' => true]);

    $secondProduct = Product::query()->create([
        'name' => 'Product in category B',
        'slug' => 'product-in-category-b',
        'price_amount' => 1100,
    ]);
    $secondProduct->categories()->attach($secondCategory->id, ['is_primary' => true]);

    $thirdProduct = Product::query()->create([
        'name' => 'Product in category C',
        'slug' => 'product-in-category-c',
        'price_amount' => 1200,
    ]);
    $thirdProduct->categories()->attach($thirdCategory->id, ['is_primary' => true]);

    Livewire::test(ListProducts::class)
        ->filterTable('staging_category', false)
        ->filterTable('categories', [$firstCategory->id, $secondCategory->id])
        ->assertCanSeeTableRecords([$firstProduct, $secondProduct])
        ->assertCanNotSeeTableRecords([$thirdProduct]);
});

function configuredProductsTableFilter(string $name): ?BaseFilter
{
    $table = ProductsTable::configure(Table::make(app(ListProducts::class)));

    return $table->getFilter($name);
}
