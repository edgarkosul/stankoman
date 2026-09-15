<?php

use App\Models\Attribute;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\Unit;
use App\Support\CompareMatrixBuilder;

/**
 * Мощность хранится в кВт. Одна категория показывает её в л.с. с одним знаком,
 * другая своей единицы не задаёт и показывает в кВт.
 *
 * @return array{attribute: Attribute, product: Closure(string, float, Category): Product, horsepowerCategory: Category, kilowattCategory: Category}
 */
function compareUnitsFixture(): array
{
    $kilowatt = Unit::query()->create([
        'name' => 'Киловатт',
        'symbol' => 'кВт',
        'dimension' => 'power',
        'base_symbol' => 'W',
        'si_factor' => 1000,
        'si_offset' => 0,
    ]);

    $horsepower = Unit::query()->create([
        'name' => 'Лошадиная сила',
        'symbol' => 'л.с.',
        'dimension' => 'power',
        'base_symbol' => 'W',
        'si_factor' => 735.49875,
        'si_offset' => 0,
    ]);

    $attribute = Attribute::query()->create([
        'name' => 'Мощность',
        'slug' => 'power-compare-units-test',
        'data_type' => 'number',
        'value_source' => 'free',
        'input_type' => 'number',
        'unit_id' => $kilowatt->id,
        'dimension' => 'power',
        'is_filterable' => true,
        'is_comparable' => true,
    ]);

    $horsepowerCategory = Category::query()->create([
        'name' => 'Садовые тракторы',
        'slug' => 'garden-tractors-compare-units-test',
        'parent_id' => Category::defaultParentKey(),
        'order' => 1,
        'is_active' => true,
    ]);

    $horsepowerCategory->attributeDefs()->attach($attribute->id, [
        'display_unit_id' => $horsepower->id,
        'number_decimals' => 1,
    ]);

    $kilowattCategory = Category::query()->create([
        'name' => 'Генераторы',
        'slug' => 'generators-compare-units-test',
        'parent_id' => Category::defaultParentKey(),
        'order' => 2,
        'is_active' => true,
    ]);

    $kilowattCategory->attributeDefs()->attach($attribute->id);

    $product = function (string $slug, float $kilowatts, Category $category) use ($attribute): Product {
        $product = Product::query()->create([
            'name' => $slug,
            'slug' => $slug,
            'price_amount' => 100000,
            'is_active' => true,
        ]);

        $product->categories()->attach($category->id, ['is_primary' => true]);

        ProductAttributeValue::query()->create([
            'product_id' => $product->id,
            'attribute_id' => $attribute->id,
            'value_number' => $kilowatts,
        ]);

        return $product;
    };

    return compact('attribute', 'product', 'horsepowerCategory', 'kilowattCategory');
}

/**
 * @param  array<int, Product>  $products
 * @return array{unit: string|null, labels: array<int, string|null>}
 */
function compareMatrixRowFor(Attribute $attribute, array $products): array
{
    $matrix = app(CompareMatrixBuilder::class)->build(
        Product::query()->whereIn('id', collect($products)->pluck('id'))->orderBy('id')->get()
    );

    $index = collect($matrix['attributes'])->search(fn (array $row): bool => (int) $row['id'] === (int) $attribute->id);

    return [
        'unit' => $matrix['attributes'][$index]['unit'],
        'labels' => collect($matrix['products'])->map(fn (array $column): ?string => $column['values'][$index]['label'])->all(),
    ];
}

it('compares values in the unit and format the product cards show', function (): void {
    ['attribute' => $attribute, 'product' => $product, 'horsepowerCategory' => $category] = compareUnitsFixture();

    // 10,6 кВт ≈ 14,41 л.с., 7,9 кВт ≈ 10,74 л.с.; у категории один знак после запятой.
    $row = compareMatrixRowFor($attribute, [
        $product('tractor-a-compare-units-test', 10.6, $category),
        $product('tractor-b-compare-units-test', 7.9, $category),
    ]);

    expect($row)->toBe([
        'unit' => 'л.с.',
        'labels' => ['14.4 л.с.', '10.7 л.с.'],
    ]);
});

it('keeps the attribute unit when compared categories show different units', function (): void {
    [
        'attribute' => $attribute,
        'product' => $product,
        'horsepowerCategory' => $horsepowerCategory,
        'kilowattCategory' => $kilowattCategory,
    ] = compareUnitsFixture();

    $row = compareMatrixRowFor($attribute, [
        $product('tractor-compare-units-test', 10.6, $horsepowerCategory),
        $product('generator-compare-units-test', 7.9, $kilowattCategory),
    ]);

    expect($row)->toBe([
        'unit' => 'кВт',
        'labels' => ['10.6 кВт', '7.9 кВт'],
    ]);
});
