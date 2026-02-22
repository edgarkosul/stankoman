<?php

use App\Models\Attribute;
use App\Models\AttributeOption;
use App\Models\Category;
use App\Models\ImportRun;
use App\Models\Product;
use App\Models\ProductAttributeOption;
use App\Models\ProductAttributeValue;
use App\Models\Unit;
use App\Support\NameNormalizer;
use App\Support\Products\SpecsMatchService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    rebuildSpecsMatchServiceSchemas();
});

afterEach(function () {
    dropSpecsMatchServiceSchemas();
});

it('matches specs into pav and pao with option auto-create in apply mode', function () {
    $watt = Unit::query()->create([
        'name' => 'Ватт',
        'symbol' => 'Вт',
        'dimension' => 'power',
        'base_symbol' => 'W',
        'si_factor' => 1,
        'si_offset' => 0,
    ]);

    $kilowatt = Unit::query()->create([
        'name' => 'Киловатт',
        'symbol' => 'кВт',
        'dimension' => 'power',
        'base_symbol' => 'W',
        'si_factor' => 1000,
        'si_offset' => 0,
    ]);

    $millimeter = Unit::query()->create([
        'name' => 'Миллиметр',
        'symbol' => 'мм',
        'dimension' => 'length',
        'base_symbol' => 'm',
        'si_factor' => 0.001,
        'si_offset' => 0,
    ]);

    $centimeter = Unit::query()->create([
        'name' => 'Сантиметр',
        'symbol' => 'см',
        'dimension' => 'length',
        'base_symbol' => 'm',
        'si_factor' => 0.01,
        'si_offset' => 0,
    ]);

    $targetCategory = Category::query()->create([
        'name' => 'Компрессоры',
        'slug' => 'kompressory',
        'parent_id' => -1,
        'order' => 10,
        'is_active' => true,
    ]);

    $stagingCategory = Category::query()->create([
        'name' => 'Staging',
        'slug' => 'staging',
        'parent_id' => -1,
        'order' => 11,
        'is_active' => true,
    ]);

    $powerAttribute = Attribute::query()->create([
        'name' => 'Мощность',
        'slug' => 'power',
        'data_type' => 'number',
        'input_type' => 'number',
        'unit_id' => $watt->id,
        'is_filterable' => true,
    ]);

    $lengthAttribute = Attribute::query()->create([
        'name' => 'Длина',
        'slug' => 'length',
        'data_type' => 'range',
        'input_type' => 'range',
        'unit_id' => $millimeter->id,
        'is_filterable' => true,
    ]);

    $colorAttribute = Attribute::query()->create([
        'name' => 'Цвет',
        'slug' => 'color',
        'data_type' => 'text',
        'input_type' => 'select',
        'is_filterable' => true,
    ]);

    $commentAttribute = Attribute::query()->create([
        'name' => 'Комментарий',
        'slug' => 'comment',
        'data_type' => 'text',
        'input_type' => 'text',
        'is_filterable' => true,
    ]);

    $powerAttribute->units()->sync([
        $watt->id => ['is_default' => true, 'sort_order' => 0],
        $kilowatt->id => ['is_default' => false, 'sort_order' => 1],
    ]);

    $lengthAttribute->units()->sync([
        $millimeter->id => ['is_default' => true, 'sort_order' => 0],
        $centimeter->id => ['is_default' => false, 'sort_order' => 1],
    ]);

    AttributeOption::query()->create([
        'attribute_id' => $colorAttribute->id,
        'value' => 'Красный',
        'sort_order' => 1,
    ]);

    $targetCategory->attributeDefs()->attach([
        $powerAttribute->id,
        $lengthAttribute->id,
        $colorAttribute->id,
        $commentAttribute->id,
    ]);

    $product = Product::query()->create([
        'name' => 'Компрессор X',
        'slug' => 'compressor-x',
        'price_amount' => 150000,
        'specs' => [
            ['name' => 'Мощность', 'value' => '1,5 кВт', 'source' => 'jsonld'],
            ['name' => 'Длина', 'value' => '10-20 см', 'source' => 'dom'],
            ['name' => 'Цвет', 'value' => 'Синий', 'source' => 'jsonld'],
            ['name' => 'Комментарий', 'value' => 'Новое значение', 'source' => 'dom'],
            ['name' => 'Неизвестный параметр', 'value' => 'abc', 'source' => 'dom'],
        ],
    ]);

    $product->categories()->attach($stagingCategory->id, ['is_primary' => true]);

    ProductAttributeValue::query()->create([
        'product_id' => $product->id,
        'attribute_id' => $commentAttribute->id,
        'value_text' => 'Уже заполнено',
    ]);

    $run = ImportRun::query()->create([
        'type' => 'specs_match',
        'status' => 'pending',
    ]);

    $service = new SpecsMatchService;

    $result = $service->run($run, [$product->id], [
        'target_category_id' => $targetCategory->id,
        'dry_run' => false,
        'only_empty_attributes' => true,
        'overwrite_existing' => false,
        'auto_create_options' => true,
        'detach_staging_after_success' => true,
    ]);

    expect($result['processed'])->toBe(1)
        ->and($result['matched_pav'])->toBe(2)
        ->and($result['matched_pao'])->toBe(1)
        ->and($result['skipped'])->toBe(2);

    $powerValue = ProductAttributeValue::query()
        ->where('product_id', $product->id)
        ->where('attribute_id', $powerAttribute->id)
        ->first();

    expect($powerValue)->not->toBeNull()
        ->and((float) $powerValue->value_number)->toBe(1500.0);

    $lengthValue = ProductAttributeValue::query()
        ->where('product_id', $product->id)
        ->where('attribute_id', $lengthAttribute->id)
        ->first();

    expect($lengthValue)->not->toBeNull()
        ->and((float) $lengthValue->value_min)->toBe(100.0)
        ->and((float) $lengthValue->value_max)->toBe(200.0);

    $blueOption = AttributeOption::query()
        ->where('attribute_id', $colorAttribute->id)
        ->where('value', 'Синий')
        ->first();

    expect($blueOption)->not->toBeNull();

    expect(
        ProductAttributeOption::query()
            ->where('product_id', $product->id)
            ->where('attribute_id', $colorAttribute->id)
            ->where('attribute_option_id', $blueOption?->id)
            ->exists()
    )->toBeTrue();

    $product->refresh();

    expect(
        $product->categories()
            ->where('categories.id', $targetCategory->id)
            ->wherePivot('is_primary', true)
            ->exists()
    )->toBeTrue();

    expect(
        $product->categories()
            ->where('categories.id', $stagingCategory->id)
            ->exists()
    )->toBeFalse();

    $issueCodes = $run->issues()->pluck('code')->all();

    expect($issueCodes)->toContain('option_auto_created')
        ->toContain('spec_name_unmatched')
        ->toContain('skipped_existing_value');
});

it('sets target category as primary for every product in apply mode', function () {
    $sourceCategory = Category::query()->create([
        'name' => 'Исходная',
        'slug' => 'source',
        'parent_id' => -1,
        'order' => 15,
        'is_active' => true,
    ]);

    $targetCategory = Category::query()->create([
        'name' => 'Целевая',
        'slug' => 'target',
        'parent_id' => -1,
        'order' => 16,
        'is_active' => true,
    ]);

    $product = Product::query()->create([
        'name' => 'Станок P',
        'slug' => 'stanok-p',
        'price_amount' => 109900,
        'specs' => [],
    ]);

    $product->categories()->attach($sourceCategory->id, ['is_primary' => true]);

    $run = ImportRun::query()->create([
        'type' => 'specs_match',
        'status' => 'pending',
    ]);

    $service = new SpecsMatchService;
    $result = $service->run($run, [$product->id], [
        'target_category_id' => $targetCategory->id,
        'dry_run' => false,
    ]);

    expect($result['processed'])->toBe(1);

    $product->refresh();

    expect(
        $product->categories()
            ->where('categories.id', $targetCategory->id)
            ->wherePivot('is_primary', true)
            ->exists()
    )->toBeTrue();

    expect(
        $product->categories()
            ->where('categories.id', $sourceCategory->id)
            ->wherePivot('is_primary', false)
            ->exists()
    )->toBeTrue();
});

it('skips existing values when only empty attributes mode is enabled even with overwrite flag', function () {
    $targetCategory = Category::query()->create([
        'name' => 'Двигатели',
        'slug' => 'engines',
        'parent_id' => -1,
        'order' => 17,
        'is_active' => true,
    ]);

    $commentAttribute = Attribute::query()->create([
        'name' => 'Комментарий',
        'slug' => 'comment',
        'data_type' => 'text',
        'input_type' => 'text',
        'is_filterable' => true,
    ]);

    $targetCategory->attributeDefs()->attach($commentAttribute->id);

    $product = Product::query()->create([
        'name' => 'Двигатель Y',
        'slug' => 'engine-y',
        'price_amount' => 78000,
        'specs' => [
            ['name' => 'Комментарий', 'value' => 'Новое значение', 'source' => 'dom'],
        ],
    ]);

    ProductAttributeValue::query()->create([
        'product_id' => $product->id,
        'attribute_id' => $commentAttribute->id,
        'value_text' => 'Уже заполнено',
    ]);

    $run = ImportRun::query()->create([
        'type' => 'specs_match',
        'status' => 'pending',
    ]);

    $service = new SpecsMatchService;

    $result = $service->run($run, [$product->id], [
        'target_category_id' => $targetCategory->id,
        'dry_run' => false,
        'only_empty_attributes' => true,
        'overwrite_existing' => true,
        'auto_create_options' => false,
        'detach_staging_after_success' => false,
    ]);

    expect($result['matched_pav'])->toBe(0)
        ->and($result['matched_pao'])->toBe(0)
        ->and($result['skipped'])->toBe(1);

    $persistedValue = ProductAttributeValue::query()
        ->where('product_id', $product->id)
        ->where('attribute_id', $commentAttribute->id)
        ->value('value_text');

    expect($persistedValue)->toBe('Уже заполнено');
    expect($run->issues()->pluck('code')->all())->toContain('skipped_existing_value');
});

it('does not write values in dry-run mode', function () {
    $targetCategory = Category::query()->create([
        'name' => 'Станки',
        'slug' => 'stanki',
        'parent_id' => -1,
        'order' => 20,
        'is_active' => true,
    ]);

    $attribute = Attribute::query()->create([
        'name' => 'Материал корпуса',
        'slug' => 'material',
        'data_type' => 'text',
        'input_type' => 'text',
        'is_filterable' => true,
    ]);

    $targetCategory->attributeDefs()->attach($attribute->id);

    $product = Product::query()->create([
        'name' => 'Станок Z',
        'slug' => 'stanok-z',
        'price_amount' => 99900,
        'specs' => [
            ['name' => 'Материал корпуса', 'value' => 'Чугун', 'source' => 'jsonld'],
        ],
    ]);

    $run = ImportRun::query()->create([
        'type' => 'specs_match',
        'status' => 'pending',
    ]);

    $service = new SpecsMatchService;

    $result = $service->run($run, [$product->id], [
        'target_category_id' => $targetCategory->id,
        'dry_run' => true,
    ]);

    expect($result['matched_pav'])->toBe(1)
        ->and($result['matched_pao'])->toBe(0);

    expect(
        ProductAttributeValue::query()
            ->where('product_id', $product->id)
            ->where('attribute_id', $attribute->id)
            ->exists()
    )->toBeFalse();
});

it('matches superscript unit token with configured numeric unit', function () {
    $flowUnit = Unit::query()->create([
        'name' => 'Метр кубически в час',
        'symbol' => 'м3/ч',
        'dimension' => 'flow',
        'base_symbol' => 'm³/s',
        'si_factor' => 0.000277777778,
        'si_offset' => 0,
    ]);

    $targetCategory = Category::query()->create([
        'name' => 'Вентиляторы',
        'slug' => 'fans',
        'parent_id' => -1,
        'order' => 25,
        'is_active' => true,
    ]);

    $flowAttribute = Attribute::query()->create([
        'name' => 'Объем воздушного потока',
        'slug' => 'air-flow',
        'data_type' => 'number',
        'input_type' => 'number',
        'unit_id' => $flowUnit->id,
        'dimension' => 'flow',
        'is_filterable' => true,
    ]);

    $flowAttribute->units()->sync([
        $flowUnit->id => ['is_default' => true, 'sort_order' => 0],
    ]);

    $targetCategory->attributeDefs()->attach($flowAttribute->id);

    $product = Product::query()->create([
        'name' => 'Вентилятор F',
        'slug' => 'fan-f',
        'price_amount' => 8900,
        'specs' => [
            ['name' => 'Объем воздушного потока', 'value' => '120 м³/ч', 'source' => 'dom'],
        ],
    ]);

    $product->categories()->attach($targetCategory->id, ['is_primary' => true]);

    $run = ImportRun::query()->create([
        'type' => 'specs_match',
        'status' => 'pending',
    ]);

    $service = new SpecsMatchService;

    $result = $service->run($run, [$product->id], [
        'target_category_id' => $targetCategory->id,
        'dry_run' => false,
        'only_empty_attributes' => true,
        'overwrite_existing' => false,
        'auto_create_options' => false,
        'detach_staging_after_success' => false,
    ]);

    expect($result['matched_pav'])->toBe(1)
        ->and($result['skipped'])->toBe(0);

    $value = ProductAttributeValue::query()
        ->where('product_id', $product->id)
        ->where('attribute_id', $flowAttribute->id)
        ->first();

    expect($value)->not->toBeNull()
        ->and((float) $value->value_number)->toBe(120.0);

    expect($run->issues()->pluck('code')->all())->not->toContain('unit_ambiguous');
});

it('suggests linking to existing global attribute by normalized spec name', function () {
    $watt = Unit::query()->create([
        'name' => 'Ватт',
        'symbol' => 'Вт',
        'dimension' => 'power',
        'base_symbol' => 'W',
        'si_factor' => 1,
        'si_offset' => 0,
    ]);

    $targetCategory = Category::query()->create([
        'name' => 'Пылесосы',
        'slug' => 'vacuums',
        'parent_id' => -1,
        'order' => 26,
        'is_active' => true,
    ]);

    $existingAttribute = Attribute::query()->create([
        'name' => 'Мощность',
        'slug' => 'power-existing',
        'data_type' => 'number',
        'input_type' => 'number',
        'unit_id' => $watt->id,
        'is_filterable' => true,
    ]);

    $product = Product::query()->create([
        'name' => 'Пылесос V',
        'slug' => 'vacuum-v',
        'price_amount' => 15600,
        'specs' => [
            ['name' => 'Мощность', 'value' => '1200 Вт', 'source' => 'dom'],
        ],
    ]);

    $service = new SpecsMatchService;
    $suggestions = collect($service->buildAttributeCreationSuggestions(
        [$product->id],
        $targetCategory->id,
    ))->keyBy('spec_name');

    $powerSuggestion = $suggestions->get('Мощность');

    expect($powerSuggestion)->not->toBeNull()
        ->and((int) ($powerSuggestion['existing_attribute_id'] ?? 0))->toBe($existingAttribute->id)
        ->and((string) ($powerSuggestion['existing_attribute_name'] ?? ''))->toBe('Мощность')
        ->and((string) ($powerSuggestion['existing_attribute_data_type'] ?? ''))->toBe('number')
        ->and((string) ($powerSuggestion['existing_attribute_input_type'] ?? ''))->toBe('number')
        ->and((string) ($powerSuggestion['existing_attribute_unit_label'] ?? ''))->toBe('Ватт (Вт) — power')
        ->and((string) ($powerSuggestion['suggested_decision'] ?? ''))->toBe('link_existing');
});

it('includes specs already linked to target category in suggestions', function () {
    $volt = Unit::query()->create([
        'name' => 'Вольт',
        'symbol' => 'В',
        'dimension' => 'electric_potential',
        'base_symbol' => 'V',
        'si_factor' => 1,
        'si_offset' => 0,
    ]);

    $targetCategory = Category::query()->create([
        'name' => 'Промышленные пылесосы',
        'slug' => 'industrial-vacuums-suggestions',
        'parent_id' => -1,
        'order' => 27,
        'is_active' => true,
    ]);

    $voltageAttribute = Attribute::query()->create([
        'name' => 'Напряжение',
        'slug' => 'voltage-existing-in-target',
        'data_type' => 'number',
        'input_type' => 'number',
        'unit_id' => $volt->id,
        'is_filterable' => true,
    ]);

    $targetCategory->attributeDefs()->attach($voltageAttribute->id);

    $product = Product::query()->create([
        'name' => 'Пылесос X',
        'slug' => 'vacuum-x',
        'price_amount' => 19800,
        'specs' => [
            ['name' => 'Напряжение', 'value' => '220 В', 'source' => 'dom'],
        ],
    ]);

    $service = new SpecsMatchService;
    $suggestions = collect($service->buildAttributeCreationSuggestions(
        [$product->id],
        $targetCategory->id,
    ))->keyBy('spec_name');

    $voltageSuggestion = $suggestions->get('Напряжение');

    expect($voltageSuggestion)->not->toBeNull()
        ->and((bool) ($voltageSuggestion['matched_in_target_category'] ?? false))->toBeTrue()
        ->and((int) ($voltageSuggestion['existing_attribute_id'] ?? 0))->toBe($voltageAttribute->id)
        ->and((string) ($voltageSuggestion['existing_attribute_data_type'] ?? ''))->toBe('number')
        ->and((string) ($voltageSuggestion['existing_attribute_input_type'] ?? ''))->toBe('number')
        ->and((string) ($voltageSuggestion['existing_attribute_unit_label'] ?? ''))->toBe('Вольт (В) — electric_potential');
});

it('builds suggestions for unmatched spec names with inferred types', function () {
    $kilopascal = Unit::query()->create([
        'name' => 'Килопаскаль',
        'symbol' => 'кПа',
        'dimension' => 'pressure',
        'base_symbol' => 'Pa',
        'si_factor' => 1000,
        'si_offset' => 0,
    ]);

    $flowUnit = Unit::query()->create([
        'name' => 'Метр кубически в час',
        'symbol' => 'м3/ч',
        'dimension' => 'flow',
        'base_symbol' => 'm³/s',
        'si_factor' => 0.000277777778,
        'si_offset' => 0,
    ]);

    $targetCategory = Category::query()->create([
        'name' => 'Генераторы',
        'slug' => 'generators',
        'parent_id' => -1,
        'order' => 30,
        'is_active' => true,
    ]);

    $existingAttribute = Attribute::query()->create([
        'name' => 'Мощность',
        'slug' => 'power',
        'data_type' => 'number',
        'input_type' => 'number',
        'is_filterable' => true,
    ]);

    $targetCategory->attributeDefs()->attach($existingAttribute->id);

    $firstProduct = Product::query()->create([
        'name' => 'Генератор A',
        'slug' => 'generator-a',
        'price_amount' => 120000,
        'specs' => [
            ['name' => 'Режим работы', 'value' => 'Авто', 'source' => 'jsonld'],
            ['name' => 'Диапазон температуры', 'value' => '10-20 C', 'source' => 'dom'],
            ['name' => 'Есть подсветка', 'value' => 'Да', 'source' => 'dom'],
            ['name' => 'Вакуум', 'value' => '22 кПа', 'source' => 'dom'],
            ['name' => 'Объем воздушного потока', 'value' => '350 м³/ч', 'source' => 'dom'],
        ],
    ]);

    $secondProduct = Product::query()->create([
        'name' => 'Генератор B',
        'slug' => 'generator-b',
        'price_amount' => 115000,
        'specs' => [
            ['name' => 'Режим работы', 'value' => 'Ручной', 'source' => 'dom'],
            ['name' => 'Есть подсветка', 'value' => 'Нет', 'source' => 'dom'],
            ['name' => 'Вакуум', 'value' => '25 кПа', 'source' => 'jsonld'],
            ['name' => 'Объем воздушного потока', 'value' => '400 м³/ч', 'source' => 'jsonld'],
        ],
    ]);

    $service = new SpecsMatchService;
    $suggestions = collect($service->buildAttributeCreationSuggestions(
        [$firstProduct->id, $secondProduct->id],
        $targetCategory->id,
    ))->keyBy('spec_name');

    $modeSuggestion = $suggestions->get('Режим работы');
    $rangeSuggestion = $suggestions->get('Диапазон температуры');
    $booleanSuggestion = $suggestions->get('Есть подсветка');
    $vacuumSuggestion = $suggestions->get('Вакуум');
    $airFlowSuggestion = $suggestions->get('Объем воздушного потока');

    expect($modeSuggestion)->not->toBeNull()
        ->and($modeSuggestion['frequency'])->toBe(2)
        ->and($modeSuggestion['suggested_data_type'])->toBe('text')
        ->and($modeSuggestion['suggested_input_type'])->toBe('select');

    expect($rangeSuggestion)->not->toBeNull()
        ->and($rangeSuggestion['suggested_data_type'])->toBe('range')
        ->and($rangeSuggestion['suggested_input_type'])->toBe('range');

    expect($booleanSuggestion)->not->toBeNull()
        ->and($booleanSuggestion['suggested_data_type'])->toBe('boolean')
        ->and($booleanSuggestion['suggested_input_type'])->toBe('boolean')
        ->and($booleanSuggestion['confidence'])->toBe('high');

    expect($vacuumSuggestion)->not->toBeNull()
        ->and($vacuumSuggestion['suggested_data_type'])->toBe('number')
        ->and($vacuumSuggestion['suggested_input_type'])->toBe('number')
        ->and((int) ($vacuumSuggestion['suggested_unit_id'] ?? 0))->toBe($kilopascal->id)
        ->and($vacuumSuggestion['suggested_unit_confidence'])->toBe('high');

    expect($airFlowSuggestion)->not->toBeNull()
        ->and($airFlowSuggestion['suggested_data_type'])->toBe('number')
        ->and($airFlowSuggestion['suggested_input_type'])->toBe('number')
        ->and((int) ($airFlowSuggestion['suggested_unit_id'] ?? 0))->toBe($flowUnit->id)
        ->and($airFlowSuggestion['suggested_unit_confidence'])->toBe('high');
});

it('strips unit from spec name in suggestions and falls back to unit from spec name', function () {
    $millimeter = Unit::query()->create([
        'name' => 'Миллиметр',
        'symbol' => 'мм',
        'dimension' => 'length',
        'base_symbol' => 'm',
        'si_factor' => 0.001,
        'si_offset' => 0,
    ]);

    $targetCategory = Category::query()->create([
        'name' => 'Листогибы',
        'slug' => 'sheet-benders',
        'parent_id' => -1,
        'order' => 35,
        'is_active' => true,
    ]);

    $existingAttribute = Attribute::query()->create([
        'name' => 'Толщина металла',
        'slug' => 'metal-thickness',
        'data_type' => 'number',
        'input_type' => 'number',
        'is_filterable' => true,
    ]);

    $product = Product::query()->create([
        'name' => 'Листогиб A',
        'slug' => 'sheet-bender-a',
        'price_amount' => 210000,
        'specs' => [
            ['name' => 'Толщина металла, мм', 'value' => '0,8', 'source' => 'dom'],
        ],
    ]);

    $service = new SpecsMatchService;
    $suggestions = collect($service->buildAttributeCreationSuggestions(
        [$product->id],
        $targetCategory->id,
    ))->keyBy('spec_name');

    $suggestion = $suggestions->get('Толщина металла');

    expect($suggestion)->not->toBeNull()
        ->and($suggestions->has('Толщина металла, мм'))->toBeFalse()
        ->and($suggestion['suggested_data_type'])->toBe('number')
        ->and((int) ($suggestion['suggested_unit_id'] ?? 0))->toBe($millimeter->id)
        ->and((int) ($suggestion['existing_attribute_id'] ?? 0))->toBe($existingAttribute->id)
        ->and((string) ($suggestion['suggested_decision'] ?? ''))->toBe('link_existing');
});

it('matches numeric value when unit is only in spec name', function () {
    $millimeter = Unit::query()->create([
        'name' => 'Миллиметр',
        'symbol' => 'мм',
        'dimension' => 'length',
        'base_symbol' => 'm',
        'si_factor' => 0.001,
        'si_offset' => 0,
    ]);

    $targetCategory = Category::query()->create([
        'name' => 'Гибочные станки',
        'slug' => 'bending-machines',
        'parent_id' => -1,
        'order' => 36,
        'is_active' => true,
    ]);

    $attribute = Attribute::query()->create([
        'name' => 'Толщина металла',
        'slug' => 'metal-thickness-attribute',
        'data_type' => 'number',
        'input_type' => 'number',
        'unit_id' => $millimeter->id,
        'dimension' => 'length',
        'is_filterable' => true,
    ]);

    $attribute->units()->sync([
        $millimeter->id => ['is_default' => true, 'sort_order' => 0],
    ]);

    $targetCategory->attributeDefs()->attach($attribute->id);

    $product = Product::query()->create([
        'name' => 'Листогиб B',
        'slug' => 'sheet-bender-b',
        'price_amount' => 220000,
        'specs' => [
            ['name' => 'Толщина металла, мм', 'value' => '0,8', 'source' => 'dom'],
        ],
    ]);

    $run = ImportRun::query()->create([
        'type' => 'specs_match',
        'status' => 'pending',
    ]);

    $service = new SpecsMatchService;
    $result = $service->run($run, [$product->id], [
        'target_category_id' => $targetCategory->id,
        'dry_run' => false,
        'only_empty_attributes' => true,
        'overwrite_existing' => false,
        'auto_create_options' => false,
        'detach_staging_after_success' => false,
    ]);

    expect($result['matched_pav'])->toBe(1)
        ->and($result['skipped'])->toBe(0);

    $persistedValue = ProductAttributeValue::query()
        ->where('product_id', $product->id)
        ->where('attribute_id', $attribute->id)
        ->first();

    expect($persistedValue)->not->toBeNull()
        ->and((float) $persistedValue->value_number)->toBe(0.8);
});

it('matches mapped spec name when raw spec has chained trailing units', function () {
    $millimeter = Unit::query()->create([
        'name' => 'Миллиметр',
        'symbol' => 'мм',
        'dimension' => 'length',
        'base_symbol' => 'm',
        'si_factor' => 0.001,
        'si_offset' => 0,
    ]);

    Unit::query()->create([
        'name' => 'Мегапаскаль',
        'symbol' => 'МПа',
        'dimension' => 'pressure',
        'base_symbol' => 'Pa',
        'si_factor' => 1000000,
        'si_offset' => 0,
    ]);

    $targetCategory = Category::query()->create([
        'name' => 'Листогибы',
        'slug' => 'sheet-benders-chained-units',
        'parent_id' => -1,
        'order' => 37,
        'is_active' => true,
    ]);

    $service = new SpecsMatchService;
    $decisionResolution = $service->resolveAttributeDecisions(
        targetCategoryId: $targetCategory->id,
        decisionRows: [[
            'spec_name' => 'Макс. толщина металла сталь, σв<320 МПа',
            'decision' => 'create_attribute',
            'create_attribute_name' => 'Толщина металла',
            'create_data_type' => 'number',
            'create_input_type' => 'number',
            'create_unit_id' => $millimeter->id,
        ]],
        applyChanges: true,
    );

    $mappedKey = NameNormalizer::normalize('Макс. толщина металла сталь, σв<320');
    $attributeId = (int) ($decisionResolution['name_map'][$mappedKey] ?? 0);

    expect($attributeId)->toBeGreaterThan(0);

    $product = Product::query()->create([
        'name' => 'Листогиб C',
        'slug' => 'sheet-bender-c',
        'price_amount' => 230000,
        'specs' => [
            ['name' => 'Макс. толщина металла сталь, σв<320 МПа, мм', 'value' => '0,9', 'source' => 'dom'],
        ],
    ]);

    $run = ImportRun::query()->create([
        'type' => 'specs_match',
        'status' => 'pending',
    ]);

    $result = $service->run($run, [$product->id], [
        'target_category_id' => $targetCategory->id,
        'dry_run' => false,
        'only_empty_attributes' => true,
        'overwrite_existing' => false,
        'auto_create_options' => false,
        'detach_staging_after_success' => false,
        'attribute_name_map' => $decisionResolution['name_map'],
    ]);

    expect($result['matched_pav'])->toBe(1)
        ->and($run->issues()->pluck('code')->all())->not->toContain('spec_name_unmatched');

    $persistedValue = ProductAttributeValue::query()
        ->where('product_id', $product->id)
        ->where('attribute_id', $attributeId)
        ->first();

    expect($persistedValue)->not->toBeNull()
        ->and((float) $persistedValue->value_number)->toBe(0.9);
});

it('uses explicit spec input unit overrides when matching linked numeric attributes', function () {
    $litersPerMinute = Unit::query()->create([
        'name' => 'Литров в минуту',
        'symbol' => 'л/мин',
        'dimension' => 'volume_flow_rate',
        'base_symbol' => 'm3/s',
        'si_factor' => 0.000016666667,
        'si_offset' => 0,
    ]);

    $cubicMetersPerMinute = Unit::query()->create([
        'name' => 'Кубических метров в минуту',
        'symbol' => 'м3/мин',
        'dimension' => 'volume_flow_rate',
        'base_symbol' => 'm3/s',
        'si_factor' => 0.016666666667,
        'si_offset' => 0,
    ]);

    $targetCategory = Category::query()->create([
        'name' => 'Компрессоры',
        'slug' => 'compressors-flow-rate',
        'parent_id' => -1,
        'order' => 39,
        'is_active' => true,
    ]);

    $airFlowAttribute = Attribute::query()->create([
        'name' => 'Производительность',
        'slug' => 'air-flow',
        'data_type' => 'number',
        'input_type' => 'number',
        'unit_id' => $litersPerMinute->id,
        'is_filterable' => true,
    ]);

    $airFlowAttribute->units()->sync([
        $litersPerMinute->id => ['is_default' => true, 'sort_order' => 0],
        $cubicMetersPerMinute->id => ['is_default' => false, 'sort_order' => 1],
    ]);

    $targetCategory->attributeDefs()->attach($airFlowAttribute->id);

    $product = Product::query()->create([
        'name' => 'Компрессор P',
        'slug' => 'compressor-p',
        'price_amount' => 20500,
        'specs' => [
            ['name' => 'Производительность', 'value' => '0,42 420', 'source' => 'dom'],
        ],
    ]);

    $product->categories()->attach($targetCategory->id, ['is_primary' => true]);

    $run = ImportRun::query()->create([
        'type' => 'specs_match',
        'status' => 'pending',
    ]);

    $service = new SpecsMatchService;
    $result = $service->run($run, [$product->id], [
        'target_category_id' => $targetCategory->id,
        'dry_run' => false,
        'only_empty_attributes' => true,
        'overwrite_existing' => false,
        'auto_create_options' => false,
        'detach_staging_after_success' => false,
        'attribute_name_map' => [
            'Производительность' => $airFlowAttribute->id,
        ],
        'spec_input_unit_map' => [
            'Производительность' => $cubicMetersPerMinute->id,
        ],
    ]);

    expect($result['matched_pav'])->toBe(1)
        ->and($result['skipped'])->toBe(0);

    $persistedValue = ProductAttributeValue::query()
        ->where('product_id', $product->id)
        ->where('attribute_id', $airFlowAttribute->id)
        ->first();

    expect($persistedValue)->not->toBeNull()
        ->and((float) $persistedValue->value_number)->toBeGreaterThan(419.9)
        ->and((float) $persistedValue->value_number)->toBeLessThan(420.1);

    expect($run->issues()->pluck('code')->all())->not->toContain('spec_input_unit_invalid');
});

it('resolves attribute decisions and creates or links attributes in apply mode', function () {
    $pascal = Unit::query()->create([
        'name' => 'Паскаль',
        'symbol' => 'Па',
        'dimension' => 'pressure',
        'base_symbol' => 'Pa',
        'si_factor' => 1,
        'si_offset' => 0,
    ]);

    $kilopascal = Unit::query()->create([
        'name' => 'Килопаскаль',
        'symbol' => 'кПа',
        'dimension' => 'pressure',
        'base_symbol' => 'Pa',
        'si_factor' => 1000,
        'si_offset' => 0,
    ]);

    $targetCategory = Category::query()->create([
        'name' => 'Компрессоры',
        'slug' => 'compressors',
        'parent_id' => -1,
        'order' => 40,
        'is_active' => true,
    ]);

    $existingAttribute = Attribute::query()->create([
        'name' => 'Напряжение',
        'slug' => 'voltage',
        'data_type' => 'text',
        'input_type' => 'select',
        'is_filterable' => true,
    ]);

    $service = new SpecsMatchService;
    $result = $service->resolveAttributeDecisions(
        targetCategoryId: $targetCategory->id,
        decisionRows: [
            [
                'spec_name' => 'Давление',
                'decision' => 'create_attribute',
                'create_data_type' => 'number',
                'create_input_type' => 'number',
                'create_unit_id' => $kilopascal->id,
                'create_additional_unit_ids' => [$pascal->id],
            ],
            [
                'spec_name' => 'Напряжение',
                'decision' => 'link_existing',
                'link_attribute_id' => $existingAttribute->id,
            ],
            [
                'spec_name' => 'Дополнительно',
                'decision' => 'ignore',
            ],
        ],
        applyChanges: true,
    );

    $createdKey = NameNormalizer::normalize('Давление');
    $linkedKey = NameNormalizer::normalize('Напряжение');
    $createdAttributeId = (int) ($result['name_map'][$createdKey] ?? 0);

    expect($createdAttributeId)->toBeGreaterThan(0)
        ->and((int) ($result['name_map'][$linkedKey] ?? 0))->toBe($existingAttribute->id)
        ->and($result['ignored_spec_names'])->toContain('Дополнительно');

    $createdAttribute = Attribute::query()->find($createdAttributeId);

    expect($createdAttribute)->not->toBeNull()
        ->and($createdAttribute->data_type)->toBe('number')
        ->and($createdAttribute->input_type)->toBe('number')
        ->and((int) $createdAttribute->unit_id)->toBe($kilopascal->id)
        ->and($createdAttribute->dimension)->toBe('pressure');

    expect(
        DB::table('category_attribute')
            ->where('category_id', $targetCategory->id)
            ->where('attribute_id', $createdAttributeId)
            ->where('is_required', false)
            ->where('visible_in_specs', true)
            ->exists()
    )->toBeTrue();

    expect(
        DB::table('category_attribute')
            ->where('category_id', $targetCategory->id)
            ->where('attribute_id', $existingAttribute->id)
            ->exists()
    )->toBeTrue();

    expect(
        DB::table('attribute_unit')
            ->where('attribute_id', $createdAttributeId)
            ->where('unit_id', $kilopascal->id)
            ->where('is_default', true)
            ->exists()
    )->toBeTrue();

    expect(
        DB::table('attribute_unit')
            ->where('attribute_id', $createdAttributeId)
            ->where('unit_id', $pascal->id)
            ->where('is_default', false)
            ->exists()
    )->toBeTrue();

    $issueCodes = collect($result['issues'])->pluck('code')->all();

    expect($issueCodes)->toContain('attribute_created_from_spec')
        ->toContain('attribute_creation_skipped');
});

it('groups create decisions by custom target attribute name', function () {
    $millimeter = Unit::query()->create([
        'name' => 'Миллиметр',
        'symbol' => 'мм',
        'dimension' => 'length',
        'base_symbol' => 'm',
        'si_factor' => 0.001,
        'si_offset' => 0,
    ]);

    $targetCategory = Category::query()->create([
        'name' => 'Гильотины',
        'slug' => 'guillotines',
        'parent_id' => -1,
        'order' => 43,
        'is_active' => true,
    ]);

    $service = new SpecsMatchService;
    $result = $service->resolveAttributeDecisions(
        targetCategoryId: $targetCategory->id,
        decisionRows: [
            [
                'spec_name' => 'Макс. толщина металла сталь',
                'decision' => 'create_attribute',
                'create_attribute_name' => 'Толщина металла',
                'create_data_type' => 'number',
                'create_input_type' => 'number',
                'create_unit_id' => $millimeter->id,
            ],
            [
                'spec_name' => 'Макс. толщина металла нерж.',
                'decision' => 'create_attribute',
                'create_attribute_name' => 'Толщина металла',
                'create_data_type' => 'number',
                'create_input_type' => 'number',
                'create_unit_id' => $millimeter->id,
            ],
        ],
        applyChanges: true,
    );

    $firstKey = NameNormalizer::normalize('Макс. толщина металла сталь');
    $secondKey = NameNormalizer::normalize('Макс. толщина металла нерж.');
    $firstAttributeId = (int) ($result['name_map'][$firstKey] ?? 0);
    $secondAttributeId = (int) ($result['name_map'][$secondKey] ?? 0);

    expect($firstAttributeId)->toBeGreaterThan(0)
        ->and($secondAttributeId)->toBe($firstAttributeId);

    expect(
        Attribute::query()
            ->where('name', 'Толщина металла')
            ->count()
    )->toBe(1);

    expect(
        DB::table('category_attribute')
            ->where('category_id', $targetCategory->id)
            ->where('attribute_id', $firstAttributeId)
            ->exists()
    )->toBeTrue();
});

it('skips grouped create decisions when group configuration differs', function () {
    $millimeter = Unit::query()->create([
        'name' => 'Миллиметр',
        'symbol' => 'мм',
        'dimension' => 'length',
        'base_symbol' => 'm',
        'si_factor' => 0.001,
        'si_offset' => 0,
    ]);

    $centimeter = Unit::query()->create([
        'name' => 'Сантиметр',
        'symbol' => 'см',
        'dimension' => 'length',
        'base_symbol' => 'm',
        'si_factor' => 0.01,
        'si_offset' => 0,
    ]);

    $targetCategory = Category::query()->create([
        'name' => 'Листогибы',
        'slug' => 'sheet-benders-conflict',
        'parent_id' => -1,
        'order' => 44,
        'is_active' => true,
    ]);

    $service = new SpecsMatchService;
    $result = $service->resolveAttributeDecisions(
        targetCategoryId: $targetCategory->id,
        decisionRows: [
            [
                'spec_name' => 'Толщина стали',
                'decision' => 'create_attribute',
                'create_attribute_name' => 'Толщина металла',
                'create_data_type' => 'number',
                'create_input_type' => 'number',
                'create_unit_id' => $millimeter->id,
            ],
            [
                'spec_name' => 'Толщина нержавейки',
                'decision' => 'create_attribute',
                'create_attribute_name' => 'Толщина металла',
                'create_data_type' => 'number',
                'create_input_type' => 'number',
                'create_unit_id' => $centimeter->id,
            ],
        ],
        applyChanges: true,
    );

    expect($result['name_map'])->toBeEmpty();

    expect(
        collect($result['issues'])
            ->where('row_snapshot.reason', 'group_configuration_conflict')
            ->count()
    )->toBe(2);

    expect(Attribute::query()->where('name', 'Толщина металла')->exists())->toBeFalse();
});

it('normalizes legacy text input type to multiselect when creating text attributes', function () {
    $targetCategory = Category::query()->create([
        'name' => 'Фильтры',
        'slug' => 'filters',
        'parent_id' => -1,
        'order' => 44,
        'is_active' => true,
    ]);

    $service = new SpecsMatchService;
    $result = $service->resolveAttributeDecisions(
        targetCategoryId: $targetCategory->id,
        decisionRows: [[
            'spec_name' => 'Материал корпуса',
            'decision' => 'create_attribute',
            'create_data_type' => 'text',
            'create_input_type' => 'text',
        ]],
        applyChanges: true,
    );

    $createdAttributeId = (int) ($result['name_map'][NameNormalizer::normalize('Материал корпуса')] ?? 0);
    $createdAttribute = Attribute::query()->find($createdAttributeId);

    expect($createdAttribute)->not->toBeNull()
        ->and($createdAttribute->data_type)->toBe('text')
        ->and($createdAttribute->input_type)->toBe('multiselect');
});

it('skips numeric attribute creation when unit is missing', function () {
    $targetCategory = Category::query()->create([
        'name' => 'Насосы',
        'slug' => 'pumps',
        'parent_id' => -1,
        'order' => 45,
        'is_active' => true,
    ]);

    $service = new SpecsMatchService;
    $result = $service->resolveAttributeDecisions(
        targetCategoryId: $targetCategory->id,
        decisionRows: [[
            'spec_name' => 'Расход',
            'decision' => 'create_attribute',
            'create_data_type' => 'number',
            'create_input_type' => 'number',
        ]],
        applyChanges: true,
    );

    expect($result['name_map'])->toBeEmpty();

    expect(
        collect($result['issues'])
            ->firstWhere('row_snapshot.reason', 'missing_unit_for_numeric_attribute')
    )->not->toBeNull();

    expect(Attribute::query()->where('name', 'Расход')->exists())->toBeFalse();
});

it('does not create spec_name_unmatched issue for explicitly ignored specs', function () {
    $targetCategory = Category::query()->create([
        'name' => 'Очистители',
        'slug' => 'cleaners',
        'parent_id' => -1,
        'order' => 46,
        'is_active' => true,
    ]);

    $product = Product::query()->create([
        'name' => 'Очиститель C',
        'slug' => 'cleaner-c',
        'price_amount' => 17700,
        'specs' => [
            ['name' => 'Новый параметр', 'value' => 'abc', 'source' => 'dom'],
        ],
    ]);

    $product->categories()->attach($targetCategory->id, ['is_primary' => true]);

    $run = ImportRun::query()->create([
        'type' => 'specs_match',
        'status' => 'pending',
    ]);

    $service = new SpecsMatchService;
    $result = $service->run($run, [$product->id], [
        'target_category_id' => $targetCategory->id,
        'dry_run' => false,
        'ignored_spec_names' => ['Новый параметр'],
    ]);

    expect($result['skipped'])->toBe(1);
    expect($run->issues()->pluck('code')->all())->not->toContain('spec_name_unmatched');
});

it('uses attribute name map during run even when attribute is outside target category', function () {
    $targetCategory = Category::query()->create([
        'name' => 'Дрели',
        'slug' => 'drills',
        'parent_id' => -1,
        'order' => 50,
        'is_active' => true,
    ]);

    $externalAttribute = Attribute::query()->create([
        'name' => 'Материал корпуса',
        'slug' => 'body-material',
        'data_type' => 'text',
        'input_type' => 'text',
        'is_filterable' => true,
    ]);

    $product = Product::query()->create([
        'name' => 'Дрель X',
        'slug' => 'drill-x',
        'price_amount' => 45900,
        'specs' => [
            ['name' => 'Материал корпуса', 'value' => 'Сталь', 'source' => 'dom'],
        ],
    ]);

    $run = ImportRun::query()->create([
        'type' => 'specs_match',
        'status' => 'pending',
    ]);

    $service = new SpecsMatchService;
    $result = $service->run($run, [$product->id], [
        'target_category_id' => $targetCategory->id,
        'dry_run' => true,
        'attribute_name_map' => [
            'Материал корпуса' => $externalAttribute->id,
        ],
    ]);

    expect($result['matched_pav'])->toBe(1)
        ->and($result['issues'])->toBeGreaterThan(0);

    expect(
        ProductAttributeValue::query()
            ->where('product_id', $product->id)
            ->where('attribute_id', $externalAttribute->id)
            ->exists()
    )->toBeFalse();

    expect($run->issues()->pluck('code')->all())->toContain('attribute_not_in_target_category');
});

it('uses configured strategy when several number specs map to one attribute', function () {
    $targetCategory = Category::query()->create([
        'name' => 'Прессы',
        'slug' => 'presses',
        'parent_id' => -1,
        'order' => 60,
        'is_active' => true,
    ]);

    $thicknessAttribute = Attribute::query()->create([
        'name' => 'Толщина металла',
        'slug' => 'metal-thickness-number',
        'data_type' => 'number',
        'input_type' => 'number',
        'is_filterable' => true,
    ]);

    $targetCategory->attributeDefs()->attach($thicknessAttribute->id);

    $productMax = Product::query()->create([
        'name' => 'Пресс MAX',
        'slug' => 'press-max',
        'price_amount' => 100000,
        'specs' => [
            ['name' => 'Макс. толщина металла, сталь', 'value' => '2.0', 'source' => 'dom'],
            ['name' => 'Макс. толщина металла, нерж.', 'value' => '3.5', 'source' => 'dom'],
        ],
    ]);

    $productMin = Product::query()->create([
        'name' => 'Пресс MIN',
        'slug' => 'press-min',
        'price_amount' => 101000,
        'specs' => [
            ['name' => 'Макс. толщина металла, сталь', 'value' => '2.0', 'source' => 'dom'],
            ['name' => 'Макс. толщина металла, нерж.', 'value' => '3.5', 'source' => 'dom'],
        ],
    ]);

    $service = new SpecsMatchService;

    $runMax = ImportRun::query()->create([
        'type' => 'specs_match',
        'status' => 'pending',
    ]);

    $service->run($runMax, [$productMax->id], [
        'target_category_id' => $targetCategory->id,
        'dry_run' => false,
        'number_conflict_strategy' => 'max',
        'attribute_name_map' => [
            'Макс. толщина металла, сталь' => $thicknessAttribute->id,
            'Макс. толщина металла, нерж.' => $thicknessAttribute->id,
        ],
    ]);

    $runMin = ImportRun::query()->create([
        'type' => 'specs_match',
        'status' => 'pending',
    ]);

    $service->run($runMin, [$productMin->id], [
        'target_category_id' => $targetCategory->id,
        'dry_run' => false,
        'number_conflict_strategy' => 'min',
        'attribute_name_map' => [
            'Макс. толщина металла, сталь' => $thicknessAttribute->id,
            'Макс. толщина металла, нерж.' => $thicknessAttribute->id,
        ],
    ]);

    $maxValue = ProductAttributeValue::query()
        ->where('product_id', $productMax->id)
        ->where('attribute_id', $thicknessAttribute->id)
        ->value('value_number');

    $minValue = ProductAttributeValue::query()
        ->where('product_id', $productMin->id)
        ->where('attribute_id', $thicknessAttribute->id)
        ->value('value_number');

    expect((float) $maxValue)->toBe(3.5)
        ->and((float) $minValue)->toBe(2.0);
});

it('merges several range specs into one combined range for mapped attribute', function () {
    $targetCategory = Category::query()->create([
        'name' => 'Станки',
        'slug' => 'machines',
        'parent_id' => -1,
        'order' => 61,
        'is_active' => true,
    ]);

    $rangeAttribute = Attribute::query()->create([
        'name' => 'Диапазон толщины',
        'slug' => 'thickness-range',
        'data_type' => 'range',
        'input_type' => 'range',
        'is_filterable' => true,
    ]);

    $targetCategory->attributeDefs()->attach($rangeAttribute->id);

    $product = Product::query()->create([
        'name' => 'Станок RANGE',
        'slug' => 'machine-range',
        'price_amount' => 120000,
        'specs' => [
            ['name' => 'Толщина стали', 'value' => '1.5 - 2.5', 'source' => 'dom'],
            ['name' => 'Толщина нержавейки', 'value' => '2.0 - 4.0', 'source' => 'dom'],
        ],
    ]);

    $run = ImportRun::query()->create([
        'type' => 'specs_match',
        'status' => 'pending',
    ]);

    $service = new SpecsMatchService;
    $service->run($run, [$product->id], [
        'target_category_id' => $targetCategory->id,
        'dry_run' => false,
        'attribute_name_map' => [
            'Толщина стали' => $rangeAttribute->id,
            'Толщина нержавейки' => $rangeAttribute->id,
        ],
    ]);

    $persisted = ProductAttributeValue::query()
        ->where('product_id', $product->id)
        ->where('attribute_id', $rangeAttribute->id)
        ->first();

    expect($persisted)->not->toBeNull()
        ->and((float) $persisted->value_min)->toBe(1.5)
        ->and((float) $persisted->value_max)->toBe(4.0);
});

it('unions values for multiselect and keeps first value for select when specs map to one attribute', function () {
    $targetCategory = Category::query()->create([
        'name' => 'Листогибы',
        'slug' => 'sheet-benders-union',
        'parent_id' => -1,
        'order' => 62,
        'is_active' => true,
    ]);

    $materialAttribute = Attribute::query()->create([
        'name' => 'Материал',
        'slug' => 'material-multiselect',
        'data_type' => 'text',
        'input_type' => 'multiselect',
        'is_filterable' => true,
    ]);

    $colorAttribute = Attribute::query()->create([
        'name' => 'Цвет',
        'slug' => 'color-select',
        'data_type' => 'text',
        'input_type' => 'select',
        'is_filterable' => true,
    ]);

    $targetCategory->attributeDefs()->attach($materialAttribute->id);
    $targetCategory->attributeDefs()->attach($colorAttribute->id);

    $steel = AttributeOption::query()->create([
        'attribute_id' => $materialAttribute->id,
        'value' => 'Сталь',
        'sort_order' => 1,
    ]);
    $inox = AttributeOption::query()->create([
        'attribute_id' => $materialAttribute->id,
        'value' => 'Нержавейка',
        'sort_order' => 2,
    ]);
    $red = AttributeOption::query()->create([
        'attribute_id' => $colorAttribute->id,
        'value' => 'Красный',
        'sort_order' => 1,
    ]);
    $blue = AttributeOption::query()->create([
        'attribute_id' => $colorAttribute->id,
        'value' => 'Синий',
        'sort_order' => 2,
    ]);

    $product = Product::query()->create([
        'name' => 'Листогиб UNION',
        'slug' => 'sheet-bender-union',
        'price_amount' => 130000,
        'specs' => [
            ['name' => 'Материал 1', 'value' => 'Сталь', 'source' => 'dom'],
            ['name' => 'Материал 2', 'value' => 'Нержавейка', 'source' => 'dom'],
            ['name' => 'Цвет 1', 'value' => 'Красный', 'source' => 'dom'],
            ['name' => 'Цвет 2', 'value' => 'Синий', 'source' => 'dom'],
        ],
    ]);

    $run = ImportRun::query()->create([
        'type' => 'specs_match',
        'status' => 'pending',
    ]);

    $service = new SpecsMatchService;
    $service->run($run, [$product->id], [
        'target_category_id' => $targetCategory->id,
        'dry_run' => false,
        'attribute_name_map' => [
            'Материал 1' => $materialAttribute->id,
            'Материал 2' => $materialAttribute->id,
            'Цвет 1' => $colorAttribute->id,
            'Цвет 2' => $colorAttribute->id,
        ],
    ]);

    $materialOptionIds = ProductAttributeOption::query()
        ->where('product_id', $product->id)
        ->where('attribute_id', $materialAttribute->id)
        ->pluck('attribute_option_id')
        ->map(fn ($id): int => (int) $id)
        ->sort()
        ->values()
        ->all();

    $colorOptionIds = ProductAttributeOption::query()
        ->where('product_id', $product->id)
        ->where('attribute_id', $colorAttribute->id)
        ->pluck('attribute_option_id')
        ->map(fn ($id): int => (int) $id)
        ->sort()
        ->values()
        ->all();

    expect($materialOptionIds)->toBe([(int) $steel->id, (int) $inox->id])
        ->and($colorOptionIds)->toBe([(int) $red->id]);

    expect($run->issues()->pluck('code')->all())->toContain('select_conflict_kept_first')
        ->and($run->issues()->pluck('code')->all())->not->toContain('option_not_found');

    expect((int) $blue->id)->not->toBe((int) $red->id);
});

function rebuildSpecsMatchServiceSchemas(): void
{
    dropSpecsMatchServiceSchemas();

    Schema::create('categories', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('slug');
        $table->string('img')->nullable();
        $table->boolean('is_active')->default(true);
        $table->integer('parent_id')->default(-1);
        $table->integer('order')->default(0);
        $table->json('meta_json')->nullable();
        $table->timestamps();
        $table->unique(['parent_id', 'slug']);
        $table->unique(['parent_id', 'order']);
    });

    Schema::create('products', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('name_normalized')->nullable();
        $table->string('title')->nullable();
        $table->string('slug')->unique();
        $table->string('sku')->nullable();
        $table->string('brand')->nullable();
        $table->string('country')->nullable();
        $table->unsignedInteger('price_amount')->default(0);
        $table->unsignedInteger('discount_price')->nullable();
        $table->char('currency', 3)->default('RUB');
        $table->boolean('in_stock')->default(true);
        $table->unsignedInteger('qty')->nullable();
        $table->unsignedInteger('popularity')->default(0);
        $table->boolean('is_active')->default(true);
        $table->boolean('is_in_yml_feed')->default(true);
        $table->string('warranty')->nullable();
        $table->boolean('with_dns')->default(true);
        $table->text('short')->nullable();
        $table->longText('description')->nullable();
        $table->text('extra_description')->nullable();
        $table->json('specs')->nullable();
        $table->string('promo_info')->nullable();
        $table->string('image')->nullable();
        $table->string('thumb')->nullable();
        $table->json('gallery')->nullable();
        $table->string('meta_title')->nullable();
        $table->text('meta_description')->nullable();
        $table->timestamps();
    });

    Schema::create('product_categories', function (Blueprint $table): void {
        $table->unsignedBigInteger('product_id');
        $table->unsignedBigInteger('category_id');
        $table->boolean('is_primary')->default(false);
        $table->primary(['product_id', 'category_id']);
    });

    Schema::create('units', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('symbol', 16);
        $table->string('dimension')->nullable();
        $table->string('base_symbol')->nullable();
        $table->decimal('si_factor', 24, 12)->default(1);
        $table->decimal('si_offset', 20, 10)->default(0);
        $table->timestamps();
    });

    Schema::create('attributes', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('slug')->unique();
        $table->string('data_type');
        $table->string('input_type')->default('text');
        $table->unsignedBigInteger('unit_id')->nullable();
        $table->boolean('is_filterable')->default(false);
        $table->boolean('is_visible')->default(true);
        $table->boolean('is_comparable')->default(true);
        $table->string('group')->nullable();
        $table->string('display_format')->nullable();
        $table->unsignedInteger('sort_order')->default(0);
        $table->unsignedTinyInteger('number_decimals')->nullable();
        $table->decimal('number_step', 10, 6)->nullable();
        $table->string('number_rounding')->nullable();
        $table->string('dimension')->nullable();
        $table->timestamps();
    });

    Schema::create('attribute_unit', function (Blueprint $table): void {
        $table->unsignedBigInteger('attribute_id');
        $table->unsignedBigInteger('unit_id');
        $table->boolean('is_default')->default(false);
        $table->unsignedInteger('sort_order')->default(0);
        $table->timestamps();
        $table->primary(['attribute_id', 'unit_id']);
    });

    Schema::create('attribute_options', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('attribute_id');
        $table->string('value');
        $table->unsignedInteger('sort_order')->default(0);
        $table->timestamps();
        $table->unique(['attribute_id', 'value']);
    });

    Schema::create('category_attribute', function (Blueprint $table): void {
        $table->unsignedBigInteger('category_id');
        $table->unsignedBigInteger('attribute_id');
        $table->unsignedBigInteger('display_unit_id')->nullable();
        $table->unsignedTinyInteger('number_decimals')->nullable();
        $table->decimal('number_step', 10, 6)->nullable();
        $table->string('number_rounding')->nullable();
        $table->boolean('is_required')->default(false);
        $table->unsignedInteger('filter_order')->default(0);
        $table->unsignedInteger('compare_order')->default(0);
        $table->boolean('visible_in_specs')->default(true);
        $table->boolean('visible_in_compare')->default(true);
        $table->string('group_override')->nullable();
        $table->timestamps();
        $table->primary(['category_id', 'attribute_id']);
    });

    Schema::create('product_attribute_values', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('product_id');
        $table->unsignedBigInteger('attribute_id');
        $table->string('value_text')->nullable();
        $table->decimal('value_number', 20, 6)->nullable();
        $table->decimal('value_si', 28, 10)->nullable();
        $table->decimal('value_min_si', 28, 10)->nullable();
        $table->decimal('value_max_si', 28, 10)->nullable();
        $table->decimal('value_min', 18, 6)->nullable();
        $table->decimal('value_max', 18, 6)->nullable();
        $table->boolean('value_boolean')->nullable();
        $table->unsignedBigInteger('attribute_option_id')->nullable();
        $table->timestamps();
        $table->unique(['product_id', 'attribute_id']);
    });

    Schema::create('product_attribute_option', function (Blueprint $table): void {
        $table->unsignedBigInteger('product_id');
        $table->unsignedBigInteger('attribute_id');
        $table->unsignedBigInteger('attribute_option_id');
        $table->timestamps();
        $table->unique(['product_id', 'attribute_option_id'], 'pao_unique_product_option');
        $table->unique(['product_id', 'attribute_id', 'attribute_option_id'], 'pao_unique_triplet');
    });

    Schema::create('import_runs', function (Blueprint $table): void {
        $table->id();
        $table->string('type')->default('products');
        $table->string('status')->default('pending');
        $table->json('columns')->nullable();
        $table->json('totals')->nullable();
        $table->string('source_filename')->nullable();
        $table->string('stored_path')->nullable();
        $table->unsignedBigInteger('user_id')->nullable();
        $table->timestamp('started_at')->nullable();
        $table->timestamp('finished_at')->nullable();
        $table->timestamps();
    });

    Schema::create('import_issues', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('run_id');
        $table->integer('row_index')->nullable();
        $table->string('code', 64);
        $table->string('severity', 16)->default('error');
        $table->text('message')->nullable();
        $table->json('row_snapshot')->nullable();
        $table->timestamps();
    });
}

function dropSpecsMatchServiceSchemas(): void
{
    Schema::dropIfExists('import_issues');
    Schema::dropIfExists('import_runs');
    Schema::dropIfExists('product_attribute_option');
    Schema::dropIfExists('product_attribute_values');
    Schema::dropIfExists('category_attribute');
    Schema::dropIfExists('attribute_options');
    Schema::dropIfExists('attribute_unit');
    Schema::dropIfExists('attributes');
    Schema::dropIfExists('units');
    Schema::dropIfExists('product_categories');
    Schema::dropIfExists('products');
    Schema::dropIfExists('categories');

    DB::disconnect();
}
