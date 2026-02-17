<?php

use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\Tables\ProductsTable;
use App\Jobs\RunSpecsMatchJob;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\ImportRun;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Support\NameNormalizer;
use Filament\Forms\Components\Toggle;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    rebuildProductsTableSpecsMatchSchemas();
});

afterEach(function () {
    dropProductsTableSpecsMatchSchemas();
});

it('queues specs match bulk action and creates pending import run', function () {
    Queue::fake();

    $user = User::factory()->create();
    $this->actingAs($user);

    $targetCategory = Category::query()->create([
        'name' => 'Целевая категория',
        'slug' => 'target-category',
        'parent_id' => -1,
        'order' => 100,
        'is_active' => true,
    ]);

    $stagingCategory = Category::query()->create([
        'name' => 'Staging',
        'slug' => 'staging',
        'parent_id' => -1,
        'order' => 101,
        'is_active' => true,
    ]);

    $product = Product::query()->create([
        'name' => 'Товар из staging',
        'slug' => 'staging-product',
        'price_amount' => 10000,
        'specs' => [
            ['name' => 'Мощность', 'value' => '1 кВт', 'source' => 'jsonld'],
        ],
    ]);

    $product->categories()->attach($stagingCategory->id, ['is_primary' => true]);

    Livewire::test(ListProducts::class)
        ->assertCanSeeTableRecords([$product])
        ->callTableBulkAction('massEdit', [$product], [
            'mode' => 'specs_match',
            'target_category_id' => $targetCategory->id,
            'dry_run' => true,
            'only_empty_attributes' => true,
            'overwrite_existing' => false,
            'auto_create_options' => false,
            'detach_staging_after_success' => false,
        ]);

    $run = ImportRun::query()->latest('id')->first();

    expect($run)->not->toBeNull()
        ->and($run->type)->toBe('specs_match')
        ->and($run->status)->toBe('pending')
        ->and((int) data_get($run->columns, 'options.target_category_id'))->toBe($targetCategory->id)
        ->and((bool) data_get($run->columns, 'options.dry_run'))->toBeTrue()
        ->and((bool) data_get($run->totals, '_meta.is_running'))->toBeTrue()
        ->and((int) data_get($run->totals, '_meta.selected_products'))->toBe(1);

    Queue::assertPushed(RunSpecsMatchJob::class, function (RunSpecsMatchJob $job) use ($run, $product, $targetCategory): bool {
        return $job->runId === $run->id
            && $job->productIds === [$product->id]
            && (int) ($job->options['target_category_id'] ?? 0) === $targetCategory->id
            && (bool) ($job->options['dry_run'] ?? false) === true;
    });
});

it('applies attribute decisions from specs match confirmation wizard', function () {
    Queue::fake();

    $user = User::factory()->create();
    $this->actingAs($user);

    $targetCategory = Category::query()->create([
        'name' => 'Компрессоры',
        'slug' => 'compressors',
        'parent_id' => -1,
        'order' => 120,
        'is_active' => true,
    ]);

    $existingAttribute = Attribute::query()->create([
        'name' => 'Напряжение',
        'slug' => 'voltage',
        'data_type' => 'text',
        'input_type' => 'select',
        'is_filterable' => true,
    ]);

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

    $product = Product::query()->create([
        'name' => 'Товар с новыми specs',
        'slug' => 'new-specs-product',
        'price_amount' => 13000,
        'specs' => [
            ['name' => 'Материал корпуса', 'value' => 'Сталь', 'source' => 'jsonld'],
            ['name' => 'Напряжение', 'value' => '220 В', 'source' => 'dom'],
            ['name' => 'Лишний параметр', 'value' => 'abc', 'source' => 'dom'],
        ],
    ]);

    Livewire::test(ListProducts::class)
        ->assertCanSeeTableRecords([$product])
        ->callTableBulkAction('massEdit', [$product], [
            'mode' => 'specs_match',
            'target_category_id' => $targetCategory->id,
            'dry_run' => false,
            'only_empty_attributes' => true,
            'overwrite_existing' => false,
            'auto_create_options' => false,
            'detach_staging_after_success' => false,
            'attribute_proposals' => [
                [
                    'spec_name' => 'Материал корпуса',
                    'decision' => 'create_attribute',
                    'create_data_type' => 'number',
                    'create_input_type' => 'number',
                    'create_unit_id' => $kilopascal->id,
                    'create_additional_unit_ids' => [$pascal->id],
                ],
                [
                    'spec_name' => 'Напряжение',
                    'decision' => 'link_existing',
                    'existing_attribute_id' => $existingAttribute->id,
                ],
                [
                    'spec_name' => 'Лишний параметр',
                    'decision' => 'ignore',
                ],
            ],
        ]);

    $run = ImportRun::query()->latest('id')->first();

    expect($run)->not->toBeNull()
        ->and((bool) data_get($run->columns, 'options.dry_run'))->toBeFalse()
        ->and((int) data_get($run->totals, '_meta.attribute_decisions'))->toBe(3);

    $createdAttribute = Attribute::query()->where('name', 'Материал корпуса')->first();

    expect($createdAttribute)->not->toBeNull()
        ->and($createdAttribute->input_type)->toBe('number')
        ->and((int) $createdAttribute->unit_id)->toBe($kilopascal->id);

    expect(
        DB::table('category_attribute')
            ->where('category_id', $targetCategory->id)
            ->where('attribute_id', $createdAttribute->id)
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
            ->where('attribute_id', $createdAttribute->id)
            ->where('unit_id', $kilopascal->id)
            ->where('is_default', true)
            ->exists()
    )->toBeTrue();

    expect(
        DB::table('attribute_unit')
            ->where('attribute_id', $createdAttribute->id)
            ->where('unit_id', $pascal->id)
            ->where('is_default', false)
            ->exists()
    )->toBeTrue();

    expect((int) data_get(
        $run->columns,
        'options.attribute_name_map.'.NameNormalizer::normalize('Материал корпуса'),
    ))->toBe($createdAttribute->id);

    expect((int) data_get(
        $run->columns,
        'options.attribute_name_map.'.NameNormalizer::normalize('Напряжение'),
    ))->toBe($existingAttribute->id);

    expect((array) data_get(
        $run->columns,
        'options.ignored_spec_names',
        [],
    ))->toContain('Лишний параметр');

    $preflightCodes = collect(data_get($run->columns, 'options.preflight_issues', []))
        ->pluck('code')
        ->all();

    expect($preflightCodes)->toContain('attribute_created_from_spec')
        ->toContain('attribute_creation_skipped');

    Queue::assertPushed(RunSpecsMatchJob::class, function (RunSpecsMatchJob $job) use ($run, $product): bool {
        return $job->runId === $run->id
            && $job->productIds === [$product->id]
            && (bool) ($job->options['dry_run'] ?? true) === false
            && count((array) ($job->options['attribute_name_map'] ?? [])) === 2;
    });
});

it('updates discount price for selected products in fields mass edit mode', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $firstProduct = Product::query()->create([
        'name' => 'Товар 1',
        'slug' => 'bulk-discount-product-1',
        'price_amount' => 1000,
    ]);

    $secondProduct = Product::query()->create([
        'name' => 'Товар 2',
        'slug' => 'bulk-discount-product-2',
        'price_amount' => 3333,
    ]);

    Livewire::test(ListProducts::class)
        ->assertCanSeeTableRecords([$firstProduct, $secondProduct])
        ->callTableBulkAction('massEdit', [$firstProduct, $secondProduct], [
            'mode' => 'fields',
            'field' => 'discount_price',
            'discount_price_percent' => 10,
        ]);

    $firstProduct->refresh();
    $secondProduct->refresh();

    expect($firstProduct->discount_price)->toBe(900)
        ->and($secondProduct->discount_price)->toBe(3000);
});

it('sets selected category as primary in categories mass edit mode', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $oldCategory = Category::query()->create([
        'name' => 'Старая категория',
        'slug' => 'old-category',
        'parent_id' => -1,
        'order' => 201,
        'is_active' => true,
    ]);

    $newCategory = Category::query()->create([
        'name' => 'Новая категория',
        'slug' => 'new-category',
        'parent_id' => -1,
        'order' => 202,
        'is_active' => true,
    ]);

    $product = Product::query()->create([
        'name' => 'Товар для смены категории',
        'slug' => 'primary-category-switch-product',
        'price_amount' => 1500,
    ]);

    $product->categories()->attach($oldCategory->id, ['is_primary' => true]);
    $product->categories()->attach($newCategory->id, ['is_primary' => false]);

    Livewire::test(ListProducts::class)
        ->assertCanSeeTableRecords([$product])
        ->callTableBulkAction('massEdit', [$product], [
            'mode' => 'categories',
            'cat_op' => 'set_primary',
            'primary_category_id' => $newCategory->id,
        ]);

    $product->refresh();

    expect($product->primaryCategory()?->id)->toBe($newCategory->id);

    expect(
        DB::table('product_categories')
            ->where('product_id', $product->id)
            ->where('category_id', $newCategory->id)
            ->value('is_primary')
    )->toBe(1);

    expect(
        DB::table('product_categories')
            ->where('product_id', $product->id)
            ->where('category_id', $oldCategory->id)
            ->value('is_primary')
    )->toBe(0);
});

it('configures dry-run toggle as live for immediate staging checkbox visibility update', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $product = Product::query()->create([
        'name' => 'Тестовый товар',
        'slug' => 'test-product',
        'price_amount' => 9900,
        'specs' => [],
    ]);

    Livewire::test(ListProducts::class)
        ->assertCanSeeTableRecords([$product])
        ->mountTableBulkAction('massEdit', [$product])
        ->setTableBulkActionData([
            'mode' => 'specs_match',
            'dry_run' => true,
        ])
        ->assertFormFieldExists('dry_run', fn (Toggle $field): bool => $field->isLive())
        ->assertFormFieldHidden('detach_staging_after_success')
        ->setTableBulkActionData([
            'mode' => 'specs_match',
            'dry_run' => false,
        ])
        ->assertFormFieldVisible('detach_staging_after_success');
});

it('disables overwrite toggle when only empty attributes mode is enabled', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $product = Product::query()->create([
        'name' => 'Товар для проверки переключателей',
        'slug' => 'toggle-check-product',
        'price_amount' => 9900,
        'specs' => [],
    ]);

    Livewire::test(ListProducts::class)
        ->assertCanSeeTableRecords([$product])
        ->mountTableBulkAction('massEdit', [$product])
        ->setTableBulkActionData([
            'mode' => 'specs_match',
            'only_empty_attributes' => true,
        ])
        ->assertFormFieldDisabled('overwrite_existing')
        ->setTableBulkActionData([
            'mode' => 'specs_match',
            'only_empty_attributes' => false,
        ])
        ->assertFormFieldEnabled('overwrite_existing');
});

it('uses multiselect as default input type for text and excludes text option in wizard', function () {
    $optionsMethod = new ReflectionMethod(ProductsTable::class, 'inputTypesForDataType');
    $defaultMethod = new ReflectionMethod(ProductsTable::class, 'defaultInputTypeForDataType');

    $optionsMethod->setAccessible(true);
    $defaultMethod->setAccessible(true);

    $textInputOptions = $optionsMethod->invoke(null, 'text');

    expect($textInputOptions)->toBe([
        'multiselect' => 'multiselect',
        'select' => 'select',
    ])->and($defaultMethod->invoke(null, 'text'))->toBe('multiselect');
});

function rebuildProductsTableSpecsMatchSchemas(): void
{
    dropProductsTableSpecsMatchSchemas();

    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->timestamp('email_verified_at')->nullable();
        $table->string('password');
        $table->rememberToken();
        $table->timestamps();
    });

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

    Schema::create('attribute_unit', function (Blueprint $table): void {
        $table->unsignedBigInteger('attribute_id');
        $table->unsignedBigInteger('unit_id');
        $table->boolean('is_default')->default(false);
        $table->unsignedInteger('sort_order')->default(0);
        $table->timestamps();
        $table->primary(['attribute_id', 'unit_id']);
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

function dropProductsTableSpecsMatchSchemas(): void
{
    Schema::dropIfExists('import_issues');
    Schema::dropIfExists('import_runs');
    Schema::dropIfExists('category_attribute');
    Schema::dropIfExists('attribute_unit');
    Schema::dropIfExists('units');
    Schema::dropIfExists('attributes');
    Schema::dropIfExists('product_categories');
    Schema::dropIfExists('products');
    Schema::dropIfExists('categories');
    Schema::dropIfExists('users');

    DB::disconnect();
}
