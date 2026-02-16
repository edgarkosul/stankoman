<?php

use App\Models\Attribute;
use App\Models\AttributeOption;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttributeOption;
use App\Models\ProductAttributeValue;
use App\Models\Unit;
use App\Support\Products\CategoryFilterTemplateExportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

pest()->extend(TestCase::class);

beforeEach(function () {
    Schema::disableForeignKeyConstraints();

    foreach ([
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
});

it('exports category filter template with meta and reference sheets', function () {
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
        'input_type' => 'number',
        'unit_id' => $pa->id,
        'is_filterable' => true,
        'number_decimals' => 2,
        'number_rounding' => 'round',
    ]);

    $color = Attribute::query()->create([
        'name' => 'Цвет',
        'slug' => 'color',
        'data_type' => 'text',
        'input_type' => 'multiselect',
        'is_filterable' => true,
    ]);

    $autoStart = Attribute::query()->create([
        'name' => 'Автозапуск',
        'slug' => 'auto_start',
        'data_type' => 'boolean',
        'input_type' => 'boolean',
        'is_filterable' => true,
    ]);

    $pressure->units()->attach($pa->id, ['is_default' => true, 'sort_order' => 0]);
    $pressure->units()->attach($bar->id, ['is_default' => false, 'sort_order' => 1]);

    AttributeOption::query()->create(['attribute_id' => $color->id, 'value' => 'Красный', 'sort_order' => 1]);
    AttributeOption::query()->create(['attribute_id' => $color->id, 'value' => 'Синий', 'sort_order' => 2]);

    $category->attributeDefs()->attach($pressure->id, [
        'filter_order' => 1,
        'display_unit_id' => $bar->id,
        'number_decimals' => 1,
        'number_rounding' => 'round',
    ]);
    $category->attributeDefs()->attach($color->id, ['filter_order' => 2]);
    $category->attributeDefs()->attach($autoStart->id, ['filter_order' => 3]);

    $product = Product::query()->create([
        'name' => 'Компрессор A',
        'slug' => 'compressor-a',
        'sku' => 'CMP-A',
    ]);

    $product->categories()->attach($category->id, ['is_primary' => true]);

    ProductAttributeValue::query()->create([
        'product_id' => $product->id,
        'attribute_id' => $pressure->id,
        'value_number' => 500000,
        'value_si' => 500000,
    ]);

    ProductAttributeValue::query()->create([
        'product_id' => $product->id,
        'attribute_id' => $autoStart->id,
        'value_boolean' => true,
    ]);

    ProductAttributeOption::setForProductAttribute($product->id, $color->id, [
        AttributeOption::query()->where('attribute_id', $color->id)->where('value', 'Красный')->value('id'),
        AttributeOption::query()->where('attribute_id', $color->id)->where('value', 'Синий')->value('id'),
    ]);

    $result = app(CategoryFilterTemplateExportService::class)->export($category);

    expect($result['path'])->toBeFile();

    $spreadsheet = IOFactory::createReader('Xlsx')->load($result['path']);

    $productsSheet = $spreadsheet->getSheetByName('Товары');
    $referencesSheet = $spreadsheet->getSheetByName('Справочники');
    $metaSheet = $spreadsheet->getSheetByName('_meta');

    expect($productsSheet)->not->toBeNull();
    expect($referencesSheet)->not->toBeNull();
    expect($metaSheet)->not->toBeNull();

    $headers = $productsSheet->rangeToArray('A1:G1')[0];
    expect($headers)->toBe([
        'product_id',
        'name',
        'sku',
        'updated_at',
        'attr.pressure',
        'attr.color',
        'attr.auto_start',
    ]);

    expect((string) $productsSheet->getCell('E2')->getValue())->toContain('Давление [bar]');
    expect((string) $productsSheet->getCell('A3')->getValue())->toBe((string) $product->id);
    expect((float) $productsSheet->getCell('E3')->getValue())->toBe(5.0);
    expect((string) $productsSheet->getCell('F3')->getValue())->toBe('Красный;Синий');
    expect((string) $productsSheet->getCell('G3')->getValue())->toBe('Да');

    expect((string) $referencesSheet->getCell('A1')->getValue())->toBe('attr.color');
    expect((string) $referencesSheet->getCell('A2')->getValue())->toBe('Красный');
    expect((string) $referencesSheet->getCell('A3')->getValue())->toBe('Синий');

    $meta = $metaSheet->toArray(null, true, false, false);
    $metaMap = [];
    foreach (array_slice($meta, 1) as $row) {
        $key = trim((string) ($row[0] ?? ''));
        if ($key !== '') {
            $metaMap[$key] = trim((string) ($row[1] ?? ''));
        }
    }

    expect($metaMap['template_type'] ?? null)->toBe('category_filter_v1');
    expect($metaMap['category_id'] ?? null)->toBe((string) $category->id);
    expect($metaMap['schema_hash'] ?? null)->toBe($result['schema_hash']);

    $spreadsheet->disconnectWorksheets();
    unlink($result['path']);
});
