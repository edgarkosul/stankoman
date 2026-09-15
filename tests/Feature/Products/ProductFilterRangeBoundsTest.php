<?php

use App\Models\Attribute;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\Unit;
use App\Support\ProductFilterService;

/**
 * Мощность хранится в кВт, а категория показывает её в л.с. с шагом 1.
 * 7,9 кВт ≈ 10,74 л.с., 17,21 кВт ≈ 23,40 л.с. — обе границы дробные.
 *
 * @return array{0: Category, 1: Attribute, 2: Product, 3: Product}
 */
function powerCategoryWithHorsepowerSlider(): array
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
        'slug' => 'power-range-bounds-test',
        'data_type' => 'number',
        'value_source' => 'free',
        'input_type' => 'number',
        'unit_id' => $kilowatt->id,
        'dimension' => 'power',
        'is_filterable' => true,
    ]);

    $category = Category::query()->create([
        'name' => 'Газонокосилки',
        'slug' => 'lawn-mowers-range-bounds-test',
        'parent_id' => Category::defaultParentKey(),
        'order' => 1,
        'is_active' => true,
    ]);

    $category->attributeDefs()->attach($attribute->id, [
        'display_unit_id' => $horsepower->id,
        'number_step' => 1,
        'number_decimals' => 0,
        'filter_order' => 1,
    ]);

    $products = [];

    foreach (['weak' => 7.9, 'strong' => 17.21] as $name => $kw) {
        $product = Product::query()->create([
            'name' => "Косилка {$name}",
            'slug' => "mower-{$name}-range-bounds-test",
            'price_amount' => 100000,
            'is_active' => true,
        ]);

        $product->categories()->attach($category->id, ['is_primary' => true]);

        ProductAttributeValue::query()->create([
            'product_id' => $product->id,
            'attribute_id' => $attribute->id,
            'value_number' => $kw,
        ]);

        $products[] = $product;
    }

    return [$category, $attribute, ...$products];
}

it('rounds numeric slider bounds outward to the category step', function (): void {
    [$category, $attribute] = powerCategoryWithHorsepowerSlider();

    $filter = ProductFilterService::schemaForCategory($category)->firstWhere('key', $attribute->slug);

    expect($filter)->not->toBeNull()
        ->and($filter->meta['step'])->toEqual(1.0)
        ->and($filter->meta['min'])->toEqual(10.0)
        ->and($filter->meta['max'])->toEqual(24.0);
});

it('keeps the extreme products inside the slider after app.js rounds its handles', function (): void {
    [$category, $attribute] = powerCategoryWithHorsepowerSlider();

    $filter = ProductFilterService::schemaForCategory($category)->firstWhere('key', $attribute->slug);
    $horsepower = Unit::query()->where('symbol', 'л.с.')->firstOrFail();

    // app.js округляет положение ручки до знаков шага — при шаге 1 до целого,
    // и нетронутая ручка уходит в запрос так же округлённой. Сам apply() здесь
    // не прогнать: Laravel отдаёт дробную границу в sqlite текстовой привязкой,
    // а sqlite, в отличие от MariaDB, число со строкой не сравнивает — запрос
    // пуст при любых данных. Поэтому сверяем границы в SI со значениями товаров.
    $minSi = $attribute->toSiWithUnit(round($filter->meta['min']), $horsepower);
    $maxSi = $attribute->toSiWithUnit(round($filter->meta['max']), $horsepower);

    expect($minSi)->toBeLessThanOrEqual(7.9 * 1000)
        ->and($maxSi)->toBeGreaterThanOrEqual(17.21 * 1000);
});
