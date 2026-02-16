<?php

use App\Enums\FilterType;
use App\Models\Attribute;
use App\Models\AttributeOption;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttributeOption;
use App\Support\ProductFilterService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    Cache::flush();

    Schema::disableForeignKeyConstraints();

    foreach ([
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
});

it('always returns system filters first, then attribute filters', function (): void {
    $category = Category::query()->create([
        'name' => 'Компрессоры',
        'slug' => 'kompressory-system-order',
        'parent_id' => -1,
    ]);

    $driveMode = Attribute::query()->create([
        'name' => 'Режим привода',
        'slug' => 'drive_mode_system_order',
        'data_type' => 'text',
        'value_source' => 'options',
        'filter_ui' => 'dropdown',
        'input_type' => 'text',
        'is_filterable' => true,
    ]);

    $category->attributeDefs()->attach($driveMode->id, ['filter_order' => 10]);

    $beltDrive = AttributeOption::query()->create([
        'attribute_id' => $driveMode->id,
        'value' => 'Ременной',
        'sort_order' => 1,
    ]);
    $directDrive = AttributeOption::query()->create([
        'attribute_id' => $driveMode->id,
        'value' => 'Прямой',
        'sort_order' => 2,
    ]);

    $productA = Product::query()->create([
        'name' => 'Компрессор System A',
        'slug' => 'compressor-system-a',
        'brand' => 'Acme',
        'price_amount' => 100000,
        'is_active' => true,
    ]);
    $productB = Product::query()->create([
        'name' => 'Компрессор System B',
        'slug' => 'compressor-system-b',
        'brand' => 'Acme',
        'price_amount' => 100000,
        'is_active' => true,
    ]);

    $productA->categories()->attach($category->id, ['is_primary' => true]);
    $productB->categories()->attach($category->id, ['is_primary' => true]);

    ProductAttributeOption::setForProductAttribute($productA->id, $driveMode->id, [$beltDrive->id]);
    ProductAttributeOption::setForProductAttribute($productB->id, $driveMode->id, [$directDrive->id]);

    $filters = ProductFilterService::schemaForCategory($category)->values();
    $keys = $filters->pluck('key')->all();
    $brand = $filters->firstWhere('key', 'brand');
    $price = $filters->firstWhere('key', 'price');
    $discount = $filters->firstWhere('key', 'discount');

    expect($keys)->toBe([
        'brand',
        'price',
        'discount',
        'drive_mode_system_order',
    ])->and($brand)->not->toBeNull()
        ->and($price)->not->toBeNull()
        ->and($discount)->not->toBeNull()
        ->and($brand?->meta['options'] ?? [])->toBe([
            ['v' => 'Acme', 'l' => 'Acme'],
        ])
        ->and($price?->meta['min'] ?? null)->toBe(100000)
        ->and($price?->meta['max'] ?? null)->toBe(100001);
});

it('builds option filter types from value_source and filter_ui, and applies option filters without input_type dependency', function (): void {
    $category = Category::query()->create([
        'name' => 'Компрессоры',
        'slug' => 'kompressory',
        'parent_id' => -1,
    ]);

    $driveMode = Attribute::query()->create([
        'name' => 'Режим привода',
        'slug' => 'drive_mode',
        'data_type' => 'text',
        'value_source' => 'options',
        'filter_ui' => 'dropdown',
        'input_type' => 'text',
        'is_filterable' => true,
    ]);

    $voltage = Attribute::query()->create([
        'name' => 'Напряжение',
        'slug' => 'voltage',
        'data_type' => 'text',
        'value_source' => 'options',
        'filter_ui' => 'tiles',
        'input_type' => 'text',
        'is_filterable' => true,
    ]);

    $category->attributeDefs()->attach($driveMode->id, ['filter_order' => 1]);
    $category->attributeDefs()->attach($voltage->id, ['filter_order' => 2]);

    $beltDrive = AttributeOption::query()->create([
        'attribute_id' => $driveMode->id,
        'value' => 'Ременной',
        'sort_order' => 1,
    ]);
    $directDrive = AttributeOption::query()->create([
        'attribute_id' => $driveMode->id,
        'value' => 'Прямой',
        'sort_order' => 2,
    ]);

    $v220 = AttributeOption::query()->create([
        'attribute_id' => $voltage->id,
        'value' => '220 В',
        'sort_order' => 1,
    ]);
    $v380 = AttributeOption::query()->create([
        'attribute_id' => $voltage->id,
        'value' => '380 В',
        'sort_order' => 2,
    ]);

    $productA = Product::query()->create([
        'name' => 'Компрессор A',
        'slug' => 'compressor-a',
        'price_amount' => 100000,
        'is_active' => true,
    ]);
    $productB = Product::query()->create([
        'name' => 'Компрессор B',
        'slug' => 'compressor-b',
        'price_amount' => 120000,
        'is_active' => true,
    ]);

    $productA->categories()->attach($category->id, ['is_primary' => true]);
    $productB->categories()->attach($category->id, ['is_primary' => true]);

    ProductAttributeOption::setForProductAttribute($productA->id, $driveMode->id, [$beltDrive->id]);
    ProductAttributeOption::setForProductAttribute($productB->id, $driveMode->id, [$directDrive->id]);

    ProductAttributeOption::setForProductAttribute($productA->id, $voltage->id, [$v220->id]);
    ProductAttributeOption::setForProductAttribute($productB->id, $voltage->id, [$v220->id, $v380->id]);

    $filters = ProductFilterService::schemaForCategory($category)->keyBy('key');

    expect($filters->get('drive_mode'))->not->toBeNull()
        ->and($filters->get('drive_mode')?->type)->toBe(FilterType::SELECT)
        ->and($filters->get('voltage'))->not->toBeNull()
        ->and($filters->get('voltage')?->type)->toBe(FilterType::MULTISELECT);

    $selected = [
        'drive_mode' => [
            'type' => 'select',
            'value' => (string) $beltDrive->id,
        ],
    ];

    $matchedIds = ProductFilterService::apply(Product::query(), $selected, $category)
        ->pluck('products.id')
        ->map(fn ($id) => (int) $id)
        ->all();

    expect($matchedIds)->toBe([$productA->id]);
});
