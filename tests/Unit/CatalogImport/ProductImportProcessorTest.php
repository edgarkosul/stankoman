<?php

use App\Models\Category;
use App\Models\ImportRun;
use App\Models\Product;
use App\Models\ProductSupplierReference;
use App\Support\CatalogImport\DTO\ProductPayload;
use App\Support\CatalogImport\Processing\ProductImportProcessor;
use App\Support\CatalogImport\Processing\ProductPayloadNormalizer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    prepareProductImportProcessorTables();
});

it('normalizes payload and creates product with stable supplier reference', function (): void {
    $run = createImportRun('catalog_import_yml');

    $processor = new ProductImportProcessor(new ProductPayloadNormalizer);

    $summary = $processor->processBatch([
        new ProductPayload(
            externalId: '  offer-1  ',
            name: '  Тест&nbsp;&amp;&nbsp;товар  ',
            description: '  Описание&nbsp;&amp;&nbsp;текст  ',
            brand: '  Brand&nbsp;X  ',
            priceAmount: -150,
            currency: ' rur ',
            qty: -4,
            images: [
                ' https://example.test/a.jpg ',
                'https://example.test/a.jpg',
                'https://example.test/b.jpg',
                ' ',
            ],
        ),
    ], [
        'supplier' => 'Yandex Market',
        'run_id' => $run->id,
    ]);

    expect($summary['processed'])->toBe(1)
        ->and($summary['created'])->toBe(1)
        ->and($summary['updated'])->toBe(0)
        ->and($summary['skipped'])->toBe(0)
        ->and($summary['errors'])->toBe(0)
        ->and($summary['results'])->toHaveCount(1)
        ->and($summary['results'][0]->operation)->toBe('created');

    $product = Product::query()->first();

    expect($product)->toBeInstanceOf(Product::class)
        ->and($product?->name)->toBe('Тест & товар')
        ->and($product?->brand)->toBe('Brand X')
        ->and($product?->price_amount)->toBe(0)
        ->and($product?->currency)->toBe('RUB')
        ->and($product?->qty)->toBe(0)
        ->and($product?->in_stock)->toBeFalse()
        ->and($product?->gallery)->toBe([
            'https://example.test/a.jpg',
            'https://example.test/b.jpg',
        ]);

    $stagingCategoryId = Category::query()
        ->where('slug', Category::stagingSlug())
        ->value('id');

    expect($stagingCategoryId)->toBeInt();
    expect($product?->categories()->pluck('categories.id')->all())->toContain((int) $stagingCategoryId);

    $reference = ProductSupplierReference::query()->first();

    expect($reference)->toBeInstanceOf(ProductSupplierReference::class)
        ->and($reference?->supplier)->toBe('yandex_market')
        ->and($reference?->external_id)->toBe('offer-1')
        ->and($reference?->product_id)->toBe($product?->id)
        ->and($reference?->last_seen_run_id)->toBe($run->id);
});

it('updates existing product by supplier and external id without changing categories', function (): void {
    $firstRun = createImportRun('catalog_import_yml');
    $secondRun = createImportRun('catalog_import_yml');

    $existingCategory = Category::query()->create([
        'name' => 'Категория 1',
        'slug' => 'cat-1',
        'is_active' => true,
        'parent_id' => Category::defaultParentKey(),
        'order' => 1,
    ]);

    $product = Product::query()->create([
        'name' => 'Старое имя',
        'slug' => 'old-product',
        'price_amount' => 100,
        'currency' => 'RUB',
        'in_stock' => true,
        'is_active' => true,
        'is_in_yml_feed' => true,
        'with_dns' => true,
    ]);

    $product->categories()->sync([$existingCategory->id]);

    ProductSupplierReference::query()->create([
        'supplier' => 'supplier_a',
        'external_id' => 'A-1',
        'product_id' => $product->id,
        'first_seen_run_id' => $firstRun->id,
        'last_seen_run_id' => $firstRun->id,
        'last_seen_at' => now(),
    ]);

    $processor = new ProductImportProcessor(new ProductPayloadNormalizer);

    $summary = $processor->processBatch([
        new ProductPayload(
            externalId: 'A-1',
            name: 'Новое имя',
            brand: 'Новый бренд',
            priceAmount: 9900,
            currency: 'usd',
            inStock: true,
            qty: 8,
        ),
    ], [
        'supplier' => 'supplier_a',
        'run_id' => $secondRun->id,
        'update_existing' => true,
    ]);

    expect($summary['processed'])->toBe(1)
        ->and($summary['created'])->toBe(0)
        ->and($summary['updated'])->toBe(1)
        ->and($summary['skipped'])->toBe(0)
        ->and($summary['errors'])->toBe(0)
        ->and(Product::query()->count())->toBe(1);

    $product->refresh();

    expect($product->name)->toBe('Новое имя')
        ->and($product->brand)->toBe('Новый бренд')
        ->and($product->price_amount)->toBe(9900)
        ->and($product->currency)->toBe('USD')
        ->and($product->qty)->toBe(8)
        ->and($product->in_stock)->toBeTrue();

    $categoryIds = $product->categories()->pluck('categories.id')->all();

    expect($categoryIds)->toHaveCount(1)
        ->and($categoryIds)->toContain($existingCategory->id);

    $stagingCategoryId = Category::query()
        ->where('slug', Category::stagingSlug())
        ->value('id');

    if (is_numeric($stagingCategoryId)) {
        expect($categoryIds)->not->toContain((int) $stagingCategoryId);
    }

    $reference = ProductSupplierReference::query()
        ->where('supplier', 'supplier_a')
        ->where('external_id', 'A-1')
        ->first();

    expect($reference)->toBeInstanceOf(ProductSupplierReference::class)
        ->and($reference?->product_id)->toBe($product->id)
        ->and($reference?->last_seen_run_id)->toBe($secondRun->id);
});

it('processes payloads in batches', function (): void {
    $run = createImportRun('catalog_import_yml');

    $processor = new ProductImportProcessor(new ProductPayloadNormalizer);

    $summary = $processor->processBatch([
        new ProductPayload(externalId: 'B-1', name: 'Товар 1'),
        new ProductPayload(externalId: 'B-2', name: 'Товар 2'),
        new ProductPayload(externalId: 'B-3', name: 'Товар 3'),
    ], [
        'supplier' => 'batch_feed',
        'run_id' => $run->id,
        'batch_size' => 2,
    ]);

    expect($summary['processed'])->toBe(3)
        ->and($summary['created'])->toBe(3)
        ->and($summary['updated'])->toBe(0)
        ->and($summary['skipped'])->toBe(0)
        ->and($summary['errors'])->toBe(0)
        ->and(ProductSupplierReference::query()->where('supplier', 'batch_feed')->count())->toBe(3)
        ->and(Product::query()->count())->toBe(3);
});

it('finalizes missing only in full sync authoritative mode', function (): void {
    $firstRun = createImportRun('catalog_import_yml');
    $secondRun = createImportRun('catalog_import_yml');

    $processor = new ProductImportProcessor(new ProductPayloadNormalizer);

    $summary = $processor->processBatch([
        new ProductPayload(externalId: 'SYNC-1', name: 'Синхронизированный товар', inStock: true, qty: 10),
        new ProductPayload(externalId: 'SYNC-2', name: 'Исчезающий товар', inStock: true, qty: 4),
    ], [
        'supplier' => 'sync_feed',
        'run_id' => $firstRun->id,
    ]);

    expect($summary['created'])->toBe(2);

    $processor->processBatch([
        new ProductPayload(externalId: 'SYNC-1', name: 'Синхронизированный товар', inStock: true, qty: 7),
    ], [
        'supplier' => 'sync_feed',
        'run_id' => $secondRun->id,
    ]);

    $partialFinalize = $processor->finalizeMissing('sync_feed', $secondRun->id, [
        'mode' => 'partial_import',
    ]);

    expect($partialFinalize['skipped'])->toBeTrue()
        ->and($partialFinalize['deactivated'])->toBe(0);

    $missingProductId = ProductSupplierReference::query()
        ->where('supplier', 'sync_feed')
        ->where('external_id', 'SYNC-2')
        ->value('product_id');

    expect(Product::query()->whereKey((int) $missingProductId)->value('is_active'))->toBeTrue();

    $fullFinalize = $processor->finalizeMissing('sync_feed', $secondRun->id, [
        'mode' => 'full_sync_authoritative',
        'finalize_missing' => true,
    ]);

    expect($fullFinalize['skipped'])->toBeFalse()
        ->and($fullFinalize['checked'])->toBe(1)
        ->and($fullFinalize['deactivated'])->toBe(1);

    $missingProduct = Product::query()->find((int) $missingProductId);

    expect($missingProduct)->toBeInstanceOf(Product::class)
        ->and($missingProduct?->is_active)->toBeFalse()
        ->and($missingProduct?->in_stock)->toBeFalse()
        ->and($missingProduct?->qty)->toBe(0);

    $seenProductId = ProductSupplierReference::query()
        ->where('supplier', 'sync_feed')
        ->where('external_id', 'SYNC-1')
        ->value('product_id');

    expect(Product::query()->whereKey((int) $seenProductId)->value('is_active'))->toBeTrue();
});

it('finalizes missing only inside selected source category when scoped category is provided', function (): void {
    $firstRun = createImportRun('catalog_import_yml');
    $secondRun = createImportRun('catalog_import_yml');

    $processor = new ProductImportProcessor(new ProductPayloadNormalizer);

    $processor->processBatch([
        new ProductPayload(externalId: 'SC-22-1', name: 'Категория 22 / 1', inStock: true, qty: 10, source: ['category_id' => 22]),
        new ProductPayload(externalId: 'SC-22-2', name: 'Категория 22 / 2', inStock: true, qty: 4, source: ['category_id' => 22]),
        new ProductPayload(externalId: 'SC-31-1', name: 'Категория 31 / 1', inStock: true, qty: 2, source: ['category_id' => 31]),
    ], [
        'supplier' => 'sync_feed',
        'run_id' => $firstRun->id,
    ]);

    $processor->processBatch([
        new ProductPayload(externalId: 'SC-22-1', name: 'Категория 22 / 1', inStock: true, qty: 9, source: ['category_id' => 22]),
    ], [
        'supplier' => 'sync_feed',
        'run_id' => $secondRun->id,
    ]);

    $scopedFinalize = $processor->finalizeMissing('sync_feed', $secondRun->id, [
        'mode' => 'full_sync_authoritative',
        'finalize_missing' => true,
        'source_category_id' => 22,
    ]);

    expect($scopedFinalize['skipped'])->toBeFalse()
        ->and($scopedFinalize['checked'])->toBe(1)
        ->and($scopedFinalize['deactivated'])->toBe(1);

    $missingInScopedCategoryId = ProductSupplierReference::query()
        ->where('supplier', 'sync_feed')
        ->where('external_id', 'SC-22-2')
        ->value('product_id');

    $missingOutsideScopedCategoryId = ProductSupplierReference::query()
        ->where('supplier', 'sync_feed')
        ->where('external_id', 'SC-31-1')
        ->value('product_id');

    expect(Product::query()->whereKey((int) $missingInScopedCategoryId)->value('is_active'))->toBeFalse();
    expect(Product::query()->whereKey((int) $missingOutsideScopedCategoryId)->value('is_active'))->toBeTrue();
});

function createImportRun(string $type): ImportRun
{
    return ImportRun::query()->create([
        'type' => $type,
        'status' => 'running',
        'columns' => [],
        'totals' => [],
        'started_at' => now(),
    ]);
}

function prepareProductImportProcessorTables(): void
{
    Schema::dropIfExists('product_supplier_references');
    Schema::dropIfExists('product_categories');
    Schema::dropIfExists('products');
    Schema::dropIfExists('categories');
    Schema::dropIfExists('import_runs');

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

    Schema::create('categories', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('slug');
        $table->string('img')->nullable();
        $table->boolean('is_active')->default(true);
        $table->integer('parent_id')->default(-1)->index();
        $table->integer('order')->default(0)->index();
        $table->json('meta_json')->nullable();
        $table->timestamps();

        $table->unique(['parent_id', 'slug']);
        $table->unique(['parent_id', 'order']);
    });

    Schema::create('products', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('name_normalized');
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
        $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        $table->foreign('category_id')->references('id')->on('categories')->cascadeOnDelete();
    });

    Schema::create('product_supplier_references', function (Blueprint $table): void {
        $table->id();
        $table->string('supplier', 120);
        $table->string('external_id');
        $table->unsignedInteger('source_category_id')->nullable();
        $table->unsignedBigInteger('product_id');
        $table->unsignedBigInteger('first_seen_run_id')->nullable();
        $table->unsignedBigInteger('last_seen_run_id')->nullable();
        $table->timestamp('last_seen_at')->nullable();
        $table->timestamps();

        $table->unique(['supplier', 'external_id']);
        $table->index(['supplier', 'product_id']);
        $table->index(['supplier', 'last_seen_run_id']);
        $table->index(['supplier', 'source_category_id']);

        $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        $table->foreign('first_seen_run_id')->references('id')->on('import_runs')->nullOnDelete();
        $table->foreign('last_seen_run_id')->references('id')->on('import_runs')->nullOnDelete();
    });
}
