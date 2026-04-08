<?php

use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\Tables\ProductsTable;
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

    $filter = configuredProductsTableBrandFilter('brand');

    expect($filter)->toBeInstanceOf(BaseFilter::class)
        ->and($filter->getOptions())->toBe([
            'Bosch' => 'Bosch',
            'Makita' => 'Makita',
        ])
        ->and($filter->getSearchable())->toBeTrue();
});

it('filters the products table by brand for bulk selection workflows', function () {
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

    Livewire::test(ListProducts::class)
        ->filterTable('staging_category', false)
        ->filterTable('brand', 'Bosch')
        ->assertCanSeeTableRecords([$bosch])
        ->assertCanNotSeeTableRecords([$makita]);
});

function configuredProductsTableBrandFilter(string $name): ?BaseFilter
{
    $table = ProductsTable::configure(Table::make(app(ListProducts::class)));

    return $table->getFilter($name);
}
