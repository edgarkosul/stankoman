<?php

use App\Models\Attribute;
use App\Models\AttributeOption;
use App\Models\Product;
use App\Models\ProductAttributeOption;
use App\Models\ProductAttributeValue;
use App\Models\Unit;
use App\Support\Products\ProductSpecsAttributesSyncService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    rebuildProductSpecsAttributesSyncSchemas();
});

afterEach(function (): void {
    dropProductSpecsAttributesSyncSchemas();
});

it('syncs bound pav and pao values and uses the last duplicated spec value', function (): void {
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

    $powerAttribute = Attribute::query()->create([
        'name' => 'Мощность',
        'slug' => 'power',
        'data_type' => 'number',
        'input_type' => 'number',
        'unit_id' => $watt->id,
        'is_filterable' => true,
    ]);

    $powerAttribute->units()->sync([
        $watt->id => ['is_default' => true, 'sort_order' => 0],
        $kilowatt->id => ['is_default' => false, 'sort_order' => 1],
    ]);

    $colorAttribute = Attribute::query()->create([
        'name' => 'Цвет',
        'slug' => 'color',
        'data_type' => 'text',
        'input_type' => 'multiselect',
        'is_filterable' => true,
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
        'name' => 'Компрессор P',
        'slug' => 'compressor-p',
        'price_amount' => 250000,
        'specs' => [
            ['name' => 'Мощность', 'value' => '1 кВт', 'source' => 'dom'],
            ['name' => 'Цвет', 'value' => 'Синий', 'source' => 'dom'],
            ['name' => 'Мощность', 'value' => '2 кВт', 'source' => 'jsonld'],
        ],
    ]);

    ProductAttributeValue::query()->create([
        'product_id' => $product->id,
        'attribute_id' => $powerAttribute->id,
        'value_number' => 1000,
    ]);

    ProductAttributeOption::query()->insert([
        'product_id' => $product->id,
        'attribute_id' => $colorAttribute->id,
        'attribute_option_id' => $red->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $result = (new ProductSpecsAttributesSyncService)->sync($product, $product->specs);

    expect($result['updated_pav'])->toBe(1)
        ->and($result['updated_pao'])->toBe(1)
        ->and($result['skipped'])->toBe(0);

    $persistedPower = ProductAttributeValue::query()
        ->where('product_id', $product->id)
        ->where('attribute_id', $powerAttribute->id)
        ->value('value_number');

    expect((float) $persistedPower)->toBe(2000.0);

    $persistedOptionIds = DB::table('product_attribute_option')
        ->where('product_id', $product->id)
        ->where('attribute_id', $colorAttribute->id)
        ->pluck('attribute_option_id')
        ->map(fn ($id): int => (int) $id)
        ->all();

    expect($persistedOptionIds)->toBe([$blue->id]);
});

it('skips values that cannot be parsed for bound attributes', function (): void {
    $booleanAttribute = Attribute::query()->create([
        'name' => 'Пылеудаление',
        'slug' => 'dust-extraction',
        'data_type' => 'boolean',
        'input_type' => 'boolean',
        'is_filterable' => true,
    ]);

    $colorAttribute = Attribute::query()->create([
        'name' => 'Цвет',
        'slug' => 'color',
        'data_type' => 'text',
        'input_type' => 'select',
        'is_filterable' => true,
    ]);

    $red = AttributeOption::query()->create([
        'attribute_id' => $colorAttribute->id,
        'value' => 'Красный',
        'sort_order' => 1,
    ]);

    AttributeOption::query()->create([
        'attribute_id' => $colorAttribute->id,
        'value' => 'Синий',
        'sort_order' => 2,
    ]);

    $product = Product::query()->create([
        'name' => 'Станок T',
        'slug' => 'machine-t',
        'price_amount' => 88000,
        'specs' => [
            ['name' => 'Пылеудаление', 'value' => 'непонятно', 'source' => 'manual'],
            ['name' => 'Цвет', 'value' => 'Красный, Синий', 'source' => 'manual'],
        ],
    ]);

    ProductAttributeValue::query()->create([
        'product_id' => $product->id,
        'attribute_id' => $booleanAttribute->id,
        'value_boolean' => true,
    ]);

    ProductAttributeOption::query()->insert([
        'product_id' => $product->id,
        'attribute_id' => $colorAttribute->id,
        'attribute_option_id' => $red->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $result = (new ProductSpecsAttributesSyncService)->sync($product, $product->specs);

    expect($result['updated_pav'])->toBe(0)
        ->and($result['updated_pao'])->toBe(0)
        ->and($result['skipped'])->toBe(2);

    $persistedBoolean = ProductAttributeValue::query()
        ->where('product_id', $product->id)
        ->where('attribute_id', $booleanAttribute->id)
        ->value('value_boolean');

    $persistedOptionIds = DB::table('product_attribute_option')
        ->where('product_id', $product->id)
        ->where('attribute_id', $colorAttribute->id)
        ->pluck('attribute_option_id')
        ->map(fn ($id): int => (int) $id)
        ->all();

    expect((bool) $persistedBoolean)->toBeTrue()
        ->and($persistedOptionIds)->toBe([$red->id]);
});

function rebuildProductSpecsAttributesSyncSchemas(): void
{
    dropProductSpecsAttributesSyncSchemas();

    Schema::create('products', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('name_normalized')->nullable();
        $table->string('title')->nullable();
        $table->string('slug')->unique();
        $table->unsignedInteger('price_amount')->default(0);
        $table->unsignedInteger('discount_price')->nullable();
        $table->char('currency', 3)->default('RUB');
        $table->boolean('in_stock')->default(true);
        $table->unsignedInteger('popularity')->default(0);
        $table->boolean('is_active')->default(true);
        $table->boolean('is_in_yml_feed')->default(true);
        $table->boolean('with_dns')->default(true);
        $table->json('specs')->nullable();
        $table->timestamps();
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
        $table->string('value_source')->nullable();
        $table->string('input_type')->default('text');
        $table->unsignedBigInteger('unit_id')->nullable();
        $table->string('dimension')->nullable();
        $table->boolean('is_filterable')->default(false);
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
        $table->unique(['attribute_id', 'value']);
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
}

function dropProductSpecsAttributesSyncSchemas(): void
{
    Schema::dropIfExists('product_attribute_option');
    Schema::dropIfExists('product_attribute_values');
    Schema::dropIfExists('attribute_options');
    Schema::dropIfExists('attribute_unit');
    Schema::dropIfExists('attributes');
    Schema::dropIfExists('units');
    Schema::dropIfExists('products');

    DB::disconnect();
}
