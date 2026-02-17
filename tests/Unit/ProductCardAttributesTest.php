<?php

use App\Models\Attribute;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    Schema::disableForeignKeyConstraints();

    foreach ([
        'product_attribute_option',
        'product_attribute_values',
        'attribute_options',
        'category_attribute',
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
        $table->string('slug')->unique();
        $table->string('sku')->nullable();
        $table->unsignedInteger('price_amount')->default(0);
        $table->unsignedInteger('discount_price')->nullable();
        $table->boolean('is_active')->default(true);
        $table->boolean('in_stock')->default(true);
        $table->unsignedInteger('popularity')->default(0);
        $table->json('gallery')->nullable();
        $table->string('image')->nullable();
        $table->string('thumb')->nullable();
        $table->timestamps();
    });

    Schema::create('product_categories', function (Blueprint $table): void {
        $table->unsignedBigInteger('product_id');
        $table->unsignedBigInteger('category_id');
        $table->boolean('is_primary')->default(true);
        $table->primary(['product_id', 'category_id']);
    });

    Schema::create('units', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('symbol')->nullable();
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
        $table->boolean('is_filterable')->default(false);
        $table->boolean('is_comparable')->default(false);
        $table->unsignedInteger('sort_order')->default(0);
        $table->unsignedTinyInteger('number_decimals')->nullable();
        $table->decimal('number_step', 10, 6)->nullable();
        $table->string('number_rounding')->nullable();
        $table->timestamps();
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

    Schema::create('attribute_options', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('attribute_id');
        $table->string('value');
        $table->unsignedInteger('sort_order')->default(0);
        $table->timestamps();
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
        $table->unsignedBigInteger('attribute_option_id')->nullable();
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

it('renders first five filled and visible attributes in category filter order on product card', function (): void {
    $category = Category::query()->create([
        'name' => 'Test Category',
        'slug' => 'test-category',
        'parent_id' => -1,
        'order' => 1,
        'is_active' => true,
    ]);

    $product = Product::query()->create([
        'name' => 'Test Product',
        'slug' => 'test-product',
        'price_amount' => 120000,
        'is_active' => true,
    ]);

    $product->categories()->attach($category->getKey(), ['is_primary' => true]);

    $attributes = [
        ['name' => 'A0-empty', 'slug' => 'a0-empty', 'order' => 5, 'value' => null, 'visible_in_specs' => true],
        ['name' => 'A1', 'slug' => 'a1', 'order' => 40, 'value' => 'VAL-1', 'visible_in_specs' => true],
        ['name' => 'A2', 'slug' => 'a2', 'order' => 10, 'value' => 'VAL-2', 'visible_in_specs' => true],
        ['name' => 'A3-hidden', 'slug' => 'a3-hidden', 'order' => 30, 'value' => 'VAL-3', 'visible_in_specs' => false],
        ['name' => 'A4', 'slug' => 'a4', 'order' => 20, 'value' => 'VAL-4', 'visible_in_specs' => true],
        ['name' => 'A5', 'slug' => 'a5', 'order' => 50, 'value' => 'VAL-5', 'visible_in_specs' => true],
        ['name' => 'A6', 'slug' => 'a6', 'order' => 60, 'value' => 'VAL-6', 'visible_in_specs' => true],
    ];

    foreach ($attributes as $item) {
        $attribute = Attribute::query()->create([
            'name' => $item['name'],
            'slug' => $item['slug'],
            'data_type' => 'text',
            'value_source' => 'free',
            'input_type' => 'text',
        ]);

        $category->attributeDefs()->attach($attribute->getKey(), [
            'filter_order' => $item['order'],
            'visible_in_specs' => $item['visible_in_specs'],
        ]);

        if ($item['value'] !== null) {
            ProductAttributeValue::query()->create([
                'product_id' => $product->getKey(),
                'attribute_id' => $attribute->getKey(),
                'value_text' => $item['value'],
            ]);
        }
    }

    $html = view('components.product.card', [
        'product' => $product->fresh(),
        'category' => $category->fresh(),
    ])->render();

    $positions = [
        'A2' => strpos($html, 'A2:'),
        'A4' => strpos($html, 'A4:'),
        'A1' => strpos($html, 'A1:'),
        'A5' => strpos($html, 'A5:'),
        'A6' => strpos($html, 'A6:'),
    ];

    expect($positions['A2'])->not->toBeFalse()
        ->and($positions['A4'])->not->toBeFalse()
        ->and($positions['A1'])->not->toBeFalse()
        ->and($positions['A5'])->not->toBeFalse()
        ->and($positions['A6'])->not->toBeFalse()
        ->and($positions['A2'])->toBeLessThan($positions['A4'])
        ->and($positions['A4'])->toBeLessThan($positions['A1'])
        ->and($positions['A1'])->toBeLessThan($positions['A5'])
        ->and($positions['A5'])->toBeLessThan($positions['A6'])
        ->and($html)->not->toContain('A0-empty:')
        ->and($html)->not->toContain('A3-hidden:')
        ->and($html)->not->toContain('VAL-3')
        ->and($html)->toContain('mt-auto');
});
