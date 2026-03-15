<?php

use App\Models\Attribute;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\Unit;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

pest()->extend(TestCase::class);

beforeEach(function () {
    Schema::disableForeignKeyConstraints();

    foreach ([
        'product_attribute_option',
        'product_attribute_values',
        'attribute_options',
        'category_attribute',
        'attributes',
        'units',
        'cart_items',
        'carts',
        'product_categories',
        'products',
        'categories',
        'menus',
    ] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::enableForeignKeyConstraints();

    Schema::create('menus', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('key')->unique();
        $table->timestamps();
    });

    Schema::create('categories', function (Blueprint $table): void {
        $table->id();
        $table->integer('parent_id')->default(-1);
        $table->string('name');
        $table->string('slug')->unique();
        $table->string('img')->nullable();
        $table->boolean('is_active')->default(true);
        $table->unsignedInteger('order')->default(0);
        $table->json('meta_json')->nullable();
        $table->timestamps();
    });

    Schema::create('products', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('name_normalized')->nullable()->index();
        $table->string('title')->nullable();
        $table->string('slug')->unique();
        $table->string('sku')->nullable()->index();
        $table->string('brand')->nullable()->index();
        $table->string('country')->nullable();
        $table->unsignedInteger('price_amount')->default(0);
        $table->unsignedInteger('discount_price')->nullable();
        $table->char('currency', 3)->default('RUB');
        $table->boolean('in_stock')->default(true)->index();
        $table->unsignedInteger('qty')->nullable();
        $table->unsignedInteger('popularity')->default(0)->index();
        $table->boolean('is_active')->default(true)->index();
        $table->boolean('is_in_yml_feed')->default(true)->index();
        $table->string('warranty')->nullable();
        $table->boolean('with_dns')->default(true);
        $table->text('short')->nullable();
        $table->longText('description')->nullable();
        $table->text('extra_description')->nullable();
        $table->longText('instructions')->nullable();
        $table->longText('video')->nullable();
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

    Schema::create('carts', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('user_id')->nullable();
        $table->string('token', 64)->unique();
        $table->timestamps();
    });

    Schema::create('cart_items', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('cart_id');
        $table->unsignedBigInteger('product_id');
        $table->unsignedInteger('quantity')->default(1);
        $table->decimal('price_snapshot', 12, 2)->nullable();
        $table->json('options')->nullable();
        $table->string('options_key', 40)->index();
        $table->timestamps();
    });

    Schema::create('attributes', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('slug')->unique();
        $table->string('data_type')->default('text');
        $table->string('value_source')->nullable();
        $table->string('filter_ui')->nullable();
        $table->string('input_type')->nullable();
        $table->unsignedBigInteger('unit_id')->nullable();
        $table->boolean('is_filterable')->default(false);
        $table->boolean('is_comparable')->default(false);
        $table->string('group')->nullable();
        $table->string('display_format')->nullable();
        $table->unsignedInteger('sort_order')->default(0);
        $table->unsignedInteger('number_decimals')->nullable();
        $table->float('number_step')->nullable();
        $table->string('number_rounding')->nullable();
        $table->timestamps();
    });

    Schema::create('units', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('symbol', 16)->nullable();
        $table->decimal('si_factor', 24, 12)->default(1);
        $table->decimal('si_offset', 20, 10)->default(0);
        $table->timestamps();
    });

    Schema::create('category_attribute', function (Blueprint $table): void {
        $table->unsignedBigInteger('category_id');
        $table->unsignedBigInteger('attribute_id');
        $table->unsignedBigInteger('display_unit_id')->nullable();
        $table->unsignedTinyInteger('number_decimals')->nullable();
        $table->float('number_step')->nullable();
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
        $table->float('value_number')->nullable();
        $table->float('value_si')->nullable();
        $table->float('value_min_si')->nullable();
        $table->float('value_max_si')->nullable();
        $table->float('value_min')->nullable();
        $table->float('value_max')->nullable();
        $table->boolean('value_boolean')->nullable();
        $table->timestamps();
    });

    Schema::create('product_attribute_option', function (Blueprint $table): void {
        $table->unsignedBigInteger('product_id');
        $table->unsignedBigInteger('attribute_id');
        $table->unsignedBigInteger('attribute_option_id');
        $table->timestamps();
    });
});

it('renders product tabs and includes specs, description, instructions and video content', function () {
    $product = Product::query()->create([
        'name' => 'Пылесос VT-9000',
        'slug' => 'pylesos-vt-9000',
        'is_active' => true,
        'price_amount' => 360000,
        'description' => '<p>Описание для вкладки</p>',
        'instructions' => '<p>Инструкция по запуску</p>',
        'video' => '<p>Видео по установке</p>',
        'specs' => [
            ['name' => 'Объем бака', 'value' => '60 л', 'source' => 'jsonld'],
            ['name' => 'Мощность', 'value' => '3600 Вт', 'source' => 'dom'],
            ['name' => 'Съемный бак', 'value' => false, 'source' => 'manual'],
            ['name' => 'Напряжение', 'value' => '220 Вольт'],
        ],
    ]);

    $this->get(route('product.show', ['product' => $product]))
        ->assertSuccessful()
        ->assertSee('Характеристики')
        ->assertSee('Описание')
        ->assertSee('Инструкции')
        ->assertSee('Видео')
        ->assertSee('Описание для вкладки')
        ->assertSee('Инструкция по запуску')
        ->assertSee('Видео по установке')
        ->assertSee('Объем бака')
        ->assertSee('60 л')
        ->assertSee('Съемный бак')
        ->assertSee('Нет')
        ->assertSee('lg:grid-cols-2', false);
});

it('hides empty product tabs', function () {
    $mappedSpecsProduct = Product::query()->create([
        'name' => 'Пылесос VT-9100',
        'slug' => 'pylesos-vt-9100',
        'is_active' => true,
        'price_amount' => 220000,
        'specs' => [
            'Диаметр' => '360 мм',
            'Вакуум' => '26 кПа',
        ],
    ]);

    $emptySpecsProduct = Product::query()->create([
        'name' => 'Пылесос VT-9200',
        'slug' => 'pylesos-vt-9200',
        'is_active' => true,
        'price_amount' => 180000,
        'specs' => null,
    ]);

    $this->get(route('product.show', ['product' => $mappedSpecsProduct]))
        ->assertSuccessful()
        ->assertSee('Характеристики')
        ->assertSee('Диаметр')
        ->assertSee('360 мм')
        ->assertSee('Вакуум')
        ->assertSee('26 кПа')
        ->assertDontSee('>Описание<', false)
        ->assertDontSee('>Инструкции<', false)
        ->assertDontSee('>Видео<', false);

    $this->get(route('product.show', ['product' => $emptySpecsProduct]))
        ->assertSuccessful()
        ->assertDontSee('data-testid="product-tabs"', false);
});

it('renders feature value in primary category display unit', function (): void {
    $category = Category::query()->create([
        'name' => 'Тестовая категория',
        'slug' => 'test-category-specs-units',
        'parent_id' => -1,
        'order' => 1,
        'is_active' => true,
    ]);

    $product = Product::query()->create([
        'name' => 'Товар с единицами',
        'slug' => 'product-with-display-unit',
        'is_active' => true,
        'price_amount' => 1000,
        'specs' => [],
    ]);

    $product->categories()->attach($category->id, ['is_primary' => true]);

    $meter = Unit::query()->create([
        'name' => 'Метр',
        'symbol' => 'м',
        'si_factor' => 1,
        'si_offset' => 0,
    ]);

    $centimeter = Unit::query()->create([
        'name' => 'Сантиметр',
        'symbol' => 'см',
        'si_factor' => 0.01,
        'si_offset' => 0,
    ]);

    $attribute = Attribute::query()->create([
        'name' => 'Длина',
        'slug' => 'length',
        'data_type' => 'number',
        'value_source' => 'free',
        'input_type' => 'number',
        'unit_id' => $meter->id,
        'number_decimals' => 0,
    ]);

    $category->attributeDefs()->attach($attribute->id, [
        'display_unit_id' => $centimeter->id,
        'visible_in_specs' => true,
    ]);

    ProductAttributeValue::query()->create([
        'product_id' => $product->id,
        'attribute_id' => $attribute->id,
        'value_number' => 2,
    ]);

    $this->get(route('product.show', ['product' => $product]))
        ->assertSuccessful()
        ->assertSee('Длина:')
        ->assertSee('200 см');
});

it('renders overflow-aware tooltip hooks for product spec names', function (): void {
    $product = Product::query()->create([
        'name' => 'Товар с длинной характеристикой',
        'slug' => 'product-spec-tooltip',
        'is_active' => true,
        'price_amount' => 1000,
        'specs' => [
            [
                'name' => 'Очень длинное название характеристики для всплывающей подсказки',
                'value' => '100',
            ],
        ],
    ]);

    $this->get(route('product.show', ['product' => $product]))
        ->assertSuccessful()
        ->assertSee('overflowTooltip(', false)
        ->assertSee('x-tooltip.theme-ks-light="tooltipContent"', false)
        ->assertSee('data-tooltip-max-width="360"', false)
        ->assertSeeText('Очень длинное название характеристики для всплывающей подсказки')
        ->assertSeeText('100');
});
