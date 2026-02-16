<?php

use App\Models\Attribute;
use App\Models\AttributeOption;
use App\Models\Category;
use App\Models\ImportRun;
use App\Models\Product;
use App\Models\ProductAttributeOption;
use App\Models\ProductAttributeValue;
use App\Models\Unit;
use App\Support\Products\CategoryFilterImportService;
use App\Support\Products\CategoryFilterTemplateExportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

pest()->extend(TestCase::class);

beforeEach(function () {
    Schema::disableForeignKeyConstraints();

    foreach ([
        'import_issues',
        'import_runs',
        'product_attribute_option',
        'product_attribute_values',
        'category_attribute',
        'attribute_options',
        'attribute_unit',
        'attributes',
        'units',
        'product_categories',
        'products',
        'categories',
    ] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::enableForeignKeyConstraints();

    Schema::create('categories', function (Blueprint $table): void {
        $table->id();
        $table->integer('parent_id')->default(-1);
        $table->string('name');
        $table->string('slug')->unique();
        $table->string('img')->nullable();
        $table->boolean('is_active')->default(true);
        $table->unsignedInteger('order')->default(0);
        $table->string('meta_description')->nullable();
        $table->json('meta_json')->nullable();
        $table->timestamps();
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
        $table->boolean('is_primary')->default(true);
        $table->timestamps();
        $table->primary(['product_id', 'category_id']);
    });

    Schema::create('units', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('symbol', 16);
        $table->string('dimension')->nullable();
        $table->string('base_symbol')->nullable();
        $table->decimal('si_factor', 24, 12)->default(1);
        $table->decimal('si_offset', 24, 12)->default(0);
        $table->timestamps();
    });

    Schema::create('attributes', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('slug')->unique();
        $table->enum('data_type', ['text', 'number', 'boolean', 'range']);
        $table->enum('value_source', ['free', 'options'])->nullable();
        $table->enum('filter_ui', ['tiles', 'dropdown'])->nullable();
        $table->enum('input_type', ['text', 'number', 'boolean', 'select', 'multiselect', 'range'])->default('text');
        $table->unsignedBigInteger('unit_id')->nullable();
        $table->string('dimension')->nullable();
        $table->boolean('is_filterable')->default(true);
        $table->boolean('is_comparable')->default(true);
        $table->string('group')->nullable();
        $table->string('display_format')->nullable();
        $table->unsignedInteger('sort_order')->default(0);
        $table->unsignedTinyInteger('number_decimals')->nullable();
        $table->decimal('number_step', 10, 6)->nullable();
        $table->string('number_rounding')->nullable();
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
    });

    Schema::create('category_attribute', function (Blueprint $table): void {
        $table->unsignedBigInteger('category_id');
        $table->unsignedBigInteger('attribute_id');
        $table->unsignedBigInteger('display_unit_id')->nullable();
        $table->boolean('is_required')->default(false);
        $table->unsignedInteger('filter_order')->default(0);
        $table->unsignedInteger('compare_order')->default(0);
        $table->boolean('visible_in_specs')->default(true);
        $table->boolean('visible_in_compare')->default(true);
        $table->string('group_override')->nullable();
        $table->unsignedTinyInteger('number_decimals')->nullable();
        $table->decimal('number_step', 10, 6)->nullable();
        $table->string('number_rounding')->nullable();
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
        $table->decimal('value_min', 20, 6)->nullable();
        $table->decimal('value_max', 20, 6)->nullable();
        $table->boolean('value_boolean')->nullable();
        $table->timestamps();
        $table->unique(['product_id', 'attribute_id']);
    });

    Schema::create('product_attribute_option', function (Blueprint $table): void {
        $table->unsignedBigInteger('product_id');
        $table->unsignedBigInteger('attribute_id');
        $table->unsignedBigInteger('attribute_option_id');
        $table->timestamps();
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
});

function buildCategoryFixture(): array
{
    $category = Category::query()->create([
        'name' => 'Компрессоры',
        'slug' => 'kompressory',
        'parent_id' => -1,
    ]);

    $pa = Unit::query()->create([
        'name' => 'Паскаль',
        'symbol' => 'Pa',
        'dimension' => 'pressure',
        'base_symbol' => 'Pa',
        'si_factor' => 1,
        'si_offset' => 0,
    ]);

    $bar = Unit::query()->create([
        'name' => 'Бар',
        'symbol' => 'bar',
        'dimension' => 'pressure',
        'base_symbol' => 'Pa',
        'si_factor' => 100000,
        'si_offset' => 0,
    ]);

    $pressure = Attribute::query()->create([
        'name' => 'Давление',
        'slug' => 'pressure',
        'data_type' => 'number',
        'value_source' => 'free',
        'input_type' => 'number',
        'unit_id' => $pa->id,
        'is_filterable' => true,
    ]);

    $color = Attribute::query()->create([
        'name' => 'Цвет',
        'slug' => 'color',
        'data_type' => 'text',
        'value_source' => 'options',
        'filter_ui' => 'tiles',
        'input_type' => 'multiselect',
        'is_filterable' => true,
    ]);

    $autoStart = Attribute::query()->create([
        'name' => 'Автозапуск',
        'slug' => 'auto_start',
        'data_type' => 'boolean',
        'value_source' => 'free',
        'input_type' => 'boolean',
        'is_filterable' => true,
    ]);

    $pressure->units()->attach($pa->id, ['is_default' => true, 'sort_order' => 0]);
    $pressure->units()->attach($bar->id, ['is_default' => false, 'sort_order' => 1]);

    $red = AttributeOption::query()->create(['attribute_id' => $color->id, 'value' => 'Красный', 'sort_order' => 1]);
    $blue = AttributeOption::query()->create(['attribute_id' => $color->id, 'value' => 'Синий', 'sort_order' => 2]);

    $category->attributeDefs()->attach($pressure->id, [
        'filter_order' => 1,
        'display_unit_id' => $bar->id,
        'number_decimals' => 1,
        'number_rounding' => 'round',
    ]);
    $category->attributeDefs()->attach($color->id, ['filter_order' => 2]);
    $category->attributeDefs()->attach($autoStart->id, ['filter_order' => 3]);

    return [
        'category' => $category,
        'attributes' => [
            'pressure' => $pressure,
            'color' => $color,
            'auto_start' => $autoStart,
        ],
        'options' => [
            'red' => $red,
            'blue' => $blue,
        ],
    ];
}

function setTemplateCellByHeader(Worksheet $sheet, int $row, string $header, mixed $value): void
{
    $headerRow = $sheet->rangeToArray('A1:Z1')[0];
    $index = array_search($header, $headerRow, true);

    if ($index === false) {
        throw new RuntimeException("Header {$header} not found");
    }

    $columnLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
    $sheet->setCellValue($columnLetter.$row, $value);
}

it('imports valid rows and keeps invalid row errors isolated', function () {
    $fixture = buildCategoryFixture();
    $category = $fixture['category'];
    $pressure = $fixture['attributes']['pressure'];
    $color = $fixture['attributes']['color'];
    $autoStart = $fixture['attributes']['auto_start'];

    $productA = Product::query()->create([
        'name' => 'Компрессор A',
        'slug' => 'compressor-a',
        'sku' => 'CMP-A',
    ]);
    $productB = Product::query()->create([
        'name' => 'Компрессор B',
        'slug' => 'compressor-b',
        'sku' => 'CMP-B',
    ]);

    $productA->categories()->attach($category->id, ['is_primary' => true]);
    $productB->categories()->attach($category->id, ['is_primary' => true]);

    ProductAttributeValue::query()->create([
        'product_id' => $productA->id,
        'attribute_id' => $pressure->id,
        'value_number' => 500000,
        'value_si' => 500000,
    ]);
    ProductAttributeValue::query()->create([
        'product_id' => $productA->id,
        'attribute_id' => $autoStart->id,
        'value_boolean' => false,
    ]);
    ProductAttributeOption::setForProductAttribute($productA->id, $color->id, [$fixture['options']['red']->id]);

    ProductAttributeValue::query()->create([
        'product_id' => $productB->id,
        'attribute_id' => $pressure->id,
        'value_number' => 700000,
        'value_si' => 700000,
    ]);
    ProductAttributeOption::setForProductAttribute($productB->id, $color->id, [$fixture['options']['blue']->id]);

    $export = app(CategoryFilterTemplateExportService::class)->export($category);

    $spreadsheet = IOFactory::createReader('Xlsx')->load($export['path']);
    $sheet = $spreadsheet->getSheetByName('Товары');

    setTemplateCellByHeader($sheet, 3, 'attr.pressure', '6,2');
    setTemplateCellByHeader($sheet, 3, 'attr.color', 'Красный;Синий');
    setTemplateCellByHeader($sheet, 3, 'attr.auto_start', 'Да');

    setTemplateCellByHeader($sheet, 4, 'attr.color', 'Фиолетовый');

    (new Xlsx($spreadsheet))->save($export['path']);
    $spreadsheet->disconnectWorksheets();

    $run = ImportRun::query()->create([
        'type' => 'category_filters',
        'status' => 'pending',
        'source_filename' => basename($export['path']),
        'stored_path' => $export['path'],
        'started_at' => now(),
    ]);

    $totals = app(CategoryFilterImportService::class)
        ->importFromXlsx($run, $category, $export['path'], true);

    expect($totals['updated'] ?? null)->toBe(1);
    expect($totals['error'] ?? null)->toBe(1);
    expect($totals['scanned'] ?? null)->toBe(2);

    $productA->refresh();
    $productB->refresh();

    $aPressure = ProductAttributeValue::query()
        ->where('product_id', $productA->id)
        ->where('attribute_id', $pressure->id)
        ->first();

    expect((float) $aPressure->value_number)->toBe(620000.0);

    $aAutoStart = ProductAttributeValue::query()
        ->where('product_id', $productA->id)
        ->where('attribute_id', $autoStart->id)
        ->first();

    expect((bool) $aAutoStart->value_boolean)->toBeTrue();

    $aOptionIds = ProductAttributeOption::query()
        ->where('product_id', $productA->id)
        ->where('attribute_id', $color->id)
        ->pluck('attribute_option_id')
        ->sort()
        ->values()
        ->all();

    expect($aOptionIds)->toBe([
        $fixture['options']['red']->id,
        $fixture['options']['blue']->id,
    ]);

    $bOptionIds = ProductAttributeOption::query()
        ->where('product_id', $productB->id)
        ->where('attribute_id', $color->id)
        ->pluck('attribute_option_id')
        ->values()
        ->all();

    expect($bOptionIds)->toBe([$fixture['options']['blue']->id]);

    expect($run->fresh()->status)->toBe('applied');
    expect($run->fresh()->issues()->count())->toBe(1);
    expect($run->fresh()->issues()->first()->code)->toBe('unknown_option');
    expect($run->fresh()->issues()->first()->row_index)->toBe(4);

    unlink($export['path']);
});

it('clears attribute values with !clear marker while keeping blank cells unchanged', function () {
    $fixture = buildCategoryFixture();
    $category = $fixture['category'];
    $pressure = $fixture['attributes']['pressure'];
    $color = $fixture['attributes']['color'];

    $product = Product::query()->create([
        'name' => 'Компрессор C',
        'slug' => 'compressor-c',
        'sku' => 'CMP-C',
    ]);

    $product->categories()->attach($category->id, ['is_primary' => true]);

    ProductAttributeValue::query()->create([
        'product_id' => $product->id,
        'attribute_id' => $pressure->id,
        'value_number' => 500000,
        'value_si' => 500000,
    ]);

    ProductAttributeOption::setForProductAttribute($product->id, $color->id, [
        $fixture['options']['red']->id,
        $fixture['options']['blue']->id,
    ]);

    $export = app(CategoryFilterTemplateExportService::class)->export($category);

    $spreadsheet = IOFactory::createReader('Xlsx')->load($export['path']);
    $sheet = $spreadsheet->getSheetByName('Товары');

    setTemplateCellByHeader($sheet, 3, 'attr.pressure', '');
    setTemplateCellByHeader($sheet, 3, 'attr.color', '!clear');

    (new Xlsx($spreadsheet))->save($export['path']);
    $spreadsheet->disconnectWorksheets();

    $run = ImportRun::query()->create([
        'type' => 'category_filters',
        'status' => 'pending',
        'source_filename' => basename($export['path']),
        'stored_path' => $export['path'],
        'started_at' => now(),
    ]);

    $totals = app(CategoryFilterImportService::class)
        ->importFromXlsx($run, $category, $export['path'], true);

    expect($totals['updated'] ?? null)->toBe(1);
    expect($totals['error'] ?? null)->toBe(0);

    $pressureRow = ProductAttributeValue::query()
        ->where('product_id', $product->id)
        ->where('attribute_id', $pressure->id)
        ->first();

    expect((float) $pressureRow->value_number)->toBe(500000.0);

    $optionCount = ProductAttributeOption::query()
        ->where('product_id', $product->id)
        ->where('attribute_id', $color->id)
        ->count();

    expect($optionCount)->toBe(0);

    unlink($export['path']);
});

it('fails import when category binding in meta does not match current category', function () {
    $fixture = buildCategoryFixture();
    $category = $fixture['category'];

    $product = Product::query()->create([
        'name' => 'Компрессор D',
        'slug' => 'compressor-d',
        'sku' => 'CMP-D',
    ]);

    $product->categories()->attach($category->id, ['is_primary' => true]);

    $export = app(CategoryFilterTemplateExportService::class)->export($category);

    $spreadsheet = IOFactory::createReader('Xlsx')->load($export['path']);
    $metaSheet = $spreadsheet->getSheetByName('_meta');

    $meta = $metaSheet->toArray(null, true, false, false);
    foreach ($meta as $rowIndex => $row) {
        if (($row[0] ?? null) === 'category_id') {
            $metaSheet->setCellValue('B'.($rowIndex + 1), (string) ($category->id + 100));
        }
    }

    (new Xlsx($spreadsheet))->save($export['path']);
    $spreadsheet->disconnectWorksheets();

    $run = ImportRun::query()->create([
        'type' => 'category_filters',
        'status' => 'pending',
        'source_filename' => basename($export['path']),
        'stored_path' => $export['path'],
        'started_at' => now(),
    ]);

    $totals = app(CategoryFilterImportService::class)
        ->importFromXlsx($run, $category, $export['path'], true);

    expect($totals['error'] ?? null)->toBe(1);
    expect($run->fresh()->status)->toBe('failed');
    expect($run->fresh()->issues()->first()->code)->toBe('category_mismatch');

    unlink($export['path']);
});
