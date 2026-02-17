<?php

use App\Models\Product;
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
        'attributes',
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

it('renders specs table from product specs field', function () {
    $product = Product::query()->create([
        'name' => 'Пылесос VT-9000',
        'slug' => 'pylesos-vt-9000',
        'is_active' => true,
        'price_amount' => 360000,
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
        ->assertSee('Объем бака')
        ->assertSee('60 л')
        ->assertSee('Съемный бак')
        ->assertSee('Нет')
        ->assertSee('lg:grid-cols-2', false);
});

it('renders specs from associative payload and shows placeholder when specs are empty', function () {
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
        ->assertSee('Диаметр')
        ->assertSee('360 мм')
        ->assertSee('Вакуум')
        ->assertSee('26 кПа');

    $this->get(route('product.show', ['product' => $emptySpecsProduct]))
        ->assertSuccessful()
        ->assertSee('Характеристики пока не заполнены.');
});
