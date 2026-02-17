<?php

use App\Models\Attribute;
use App\Models\AttributeOption;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttributeOption;
use App\Models\ProductAttributeValue;
use App\Support\FilterSchemaCache;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    Cache::flush();

    Schema::disableForeignKeyConstraints();

    foreach ([
        'product_attribute_values',
        'product_attribute_option',
        'category_attribute',
        'attribute_options',
        'attributes',
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
        $table->boolean('is_active')->default(true);
        $table->unsignedInteger('order')->default(0);
        $table->json('meta_json')->nullable();
        $table->timestamps();
    });

    Schema::create('products', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('name_normalized')->nullable();
        $table->string('slug')->unique();
        $table->string('brand')->nullable();
        $table->unsignedInteger('price_amount')->default(0);
        $table->unsignedInteger('discount_price')->nullable();
        $table->boolean('is_active')->default(true);
        $table->timestamps();
    });

    Schema::create('product_categories', function (Blueprint $table): void {
        $table->unsignedBigInteger('product_id');
        $table->unsignedBigInteger('category_id');
        $table->boolean('is_primary')->default(true);
        $table->timestamps();
        $table->primary(['product_id', 'category_id']);
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

    Schema::create('product_attribute_option', function (Blueprint $table): void {
        $table->unsignedBigInteger('product_id');
        $table->unsignedBigInteger('attribute_id');
        $table->unsignedBigInteger('attribute_option_id');
        $table->timestamps();
        $table->unique(['product_id', 'attribute_id', 'attribute_option_id'], 'pao_unique_triplet');
    });

    Schema::create('product_attribute_values', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('product_id');
        $table->unsignedBigInteger('attribute_id');
        $table->text('value_text')->nullable();
        $table->boolean('value_boolean')->nullable();
        $table->decimal('value_number', 18, 6)->nullable();
        $table->decimal('value_si', 18, 6)->nullable();
        $table->decimal('value_min', 18, 6)->nullable();
        $table->decimal('value_max', 18, 6)->nullable();
        $table->decimal('value_min_si', 18, 6)->nullable();
        $table->decimal('value_max_si', 18, 6)->nullable();
        $table->timestamps();
    });
});

it('forgets only categories affected by product attribute', function (): void {
    $categoryA = Category::query()->create([
        'name' => 'Category A',
        'slug' => 'category-a',
        'parent_id' => -1,
    ]);
    $categoryB = Category::query()->create([
        'name' => 'Category B',
        'slug' => 'category-b',
        'parent_id' => -1,
    ]);

    $attribute = Attribute::query()->create([
        'name' => 'Drive mode',
        'slug' => 'drive-mode',
        'data_type' => 'text',
        'value_source' => 'options',
        'filter_ui' => 'tiles',
        'input_type' => 'multiselect',
        'is_filterable' => true,
    ]);

    $categoryA->attributeDefs()->attach($attribute->id, ['filter_order' => 1]);
    $categoryB->attributeDefs()->attach($attribute->id, ['filter_order' => 1]);

    $product = Product::query()->create([
        'name' => 'Compressor A',
        'slug' => 'compressor-a',
        'price_amount' => 1000,
        'is_active' => true,
    ]);

    $product->categories()->attach($categoryA->id, ['is_primary' => true]);

    $keyA = FilterSchemaCache::key((int) $categoryA->id);
    $keyB = FilterSchemaCache::key((int) $categoryB->id);

    Cache::put($keyA, 'A', now()->addMinutes(10));
    Cache::put($keyB, 'B', now()->addMinutes(10));

    FilterSchemaCache::forgetByProductAttribute((int) $product->id, (int) $attribute->id);

    expect(Cache::has($keyA))->toBeFalse()
        ->and(Cache::has($keyB))->toBeTrue();
});

it('invalidates cache when product attribute options are changed', function (): void {
    $category = Category::query()->create([
        'name' => 'Category C',
        'slug' => 'category-c',
        'parent_id' => -1,
    ]);

    $attribute = Attribute::query()->create([
        'name' => 'Voltage',
        'slug' => 'voltage',
        'data_type' => 'text',
        'value_source' => 'options',
        'filter_ui' => 'tiles',
        'input_type' => 'multiselect',
        'is_filterable' => true,
    ]);

    $option = AttributeOption::query()->create([
        'attribute_id' => (int) $attribute->id,
        'value' => '220 V',
        'sort_order' => 1,
    ]);

    $category->attributeDefs()->attach($attribute->id, ['filter_order' => 1]);

    $product = Product::query()->create([
        'name' => 'Compressor B',
        'slug' => 'compressor-b',
        'price_amount' => 1000,
        'is_active' => true,
    ]);
    $product->categories()->attach($category->id, ['is_primary' => true]);

    $key = FilterSchemaCache::key((int) $category->id);
    Cache::put($key, 'cached', now()->addMinutes(10));

    ProductAttributeOption::setForProductAttribute((int) $product->id, (int) $attribute->id, [(int) $option->id]);

    expect(Cache::has($key))->toBeFalse();
});

it('invalidates cache when product attribute values are changed', function (): void {
    $category = Category::query()->create([
        'name' => 'Category D',
        'slug' => 'category-d',
        'parent_id' => -1,
    ]);

    $attribute = Attribute::query()->create([
        'name' => 'Pressure',
        'slug' => 'pressure',
        'data_type' => 'text',
        'value_source' => 'free',
        'input_type' => 'text',
        'is_filterable' => true,
    ]);

    $category->attributeDefs()->attach($attribute->id, ['filter_order' => 1]);

    $product = Product::query()->create([
        'name' => 'Compressor C',
        'slug' => 'compressor-c',
        'price_amount' => 1000,
        'is_active' => true,
    ]);
    $product->categories()->attach($category->id, ['is_primary' => true]);

    $key = FilterSchemaCache::key((int) $category->id);
    Cache::put($key, 'cached', now()->addMinutes(10));

    ProductAttributeValue::query()->create([
        'product_id' => (int) $product->id,
        'attribute_id' => (int) $attribute->id,
        'value_text' => '8 bar',
    ]);

    expect(Cache::has($key))->toBeFalse();
});
