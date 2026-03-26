<?php

use App\Jobs\DownloadProductImportMediaJob;
use App\Models\Category;
use App\Models\ImportRun;
use App\Models\ImportRunEvent;
use App\Models\Product;
use App\Models\ProductImportMedia;
use App\Models\ProductSupplierReference;
use App\Support\CatalogImport\DTO\ProductPayload;
use App\Support\CatalogImport\Processing\ExistingProductUpdateSelection;
use App\Support\CatalogImport\Processing\ProductImportProcessor;
use App\Support\CatalogImport\Processing\ProductPayloadNormalizer;
use App\Support\CatalogImport\Runs\DatabaseImportRunEventLogger;
use App\Support\Products\ProductSearchSync;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
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
            video: productImportRutubeVideoBlock('c6a86f440e1437f9a65dd893d50aaabd'),
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
        ->and($product?->video)->toBe(productImportRutubeVideoBlock('c6a86f440e1437f9a65dd893d50aaabd'))
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
        'video' => productImportRutubeVideoBlock('old-video-id'),
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
            video: productImportRutubeVideoBlock('new-video-id'),
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
        ->and($product->in_stock)->toBeTrue()
        ->and($product->video)->toBe(productImportRutubeVideoBlock('new-video-id'));

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

it('clears existing video on update when payload video is empty', function (): void {
    $firstRun = createImportRun('catalog_import_yml');
    $secondRun = createImportRun('catalog_import_yml');

    $product = Product::query()->create([
        'name' => 'Товар с видео',
        'slug' => 'product-with-video-to-clear',
        'price_amount' => 100,
        'currency' => 'RUB',
        'in_stock' => true,
        'is_active' => true,
        'is_in_yml_feed' => true,
        'with_dns' => true,
        'video' => productImportRutubeVideoBlock('video-to-clear'),
    ]);

    ProductSupplierReference::query()->create([
        'supplier' => 'supplier_video_clear',
        'external_id' => 'VID-1',
        'product_id' => $product->id,
        'first_seen_run_id' => $firstRun->id,
        'last_seen_run_id' => $firstRun->id,
        'last_seen_at' => now(),
    ]);

    $processor = new ProductImportProcessor(new ProductPayloadNormalizer);

    $summary = $processor->processBatch([
        new ProductPayload(
            externalId: 'VID-1',
            name: 'Товар с видео',
            priceAmount: 100,
            video: null,
        ),
    ], [
        'supplier' => 'supplier_video_clear',
        'run_id' => $secondRun->id,
        'update_existing' => true,
        'update_existing_mode' => ExistingProductUpdateSelection::MODE_SELECTED,
        'update_existing_fields' => [ExistingProductUpdateSelection::FIELD_VIDEO],
    ]);

    expect($summary['processed'])->toBe(1)
        ->and($summary['updated'])->toBe(1)
        ->and($summary['errors'])->toBe(0);

    $product->refresh();

    expect($product->video)->toBeNull();
});

it('updates only selected price field for existing products', function (): void {
    $firstRun = createImportRun('catalog_import_yml');
    $secondRun = createImportRun('catalog_import_yml');

    $product = Product::query()->create([
        'name' => 'Исходный товар',
        'slug' => 'selective-price-product',
        'price_amount' => 100,
        'discount_price' => 90,
        'currency' => 'USD',
        'in_stock' => false,
        'qty' => 5,
        'is_active' => true,
        'is_in_yml_feed' => true,
        'with_dns' => true,
        'video' => productImportRutubeVideoBlock('old-price-video'),
    ]);

    ProductSupplierReference::query()->create([
        'supplier' => 'selective_price_supplier',
        'external_id' => 'PRICE-1',
        'product_id' => $product->id,
        'first_seen_run_id' => $firstRun->id,
        'last_seen_run_id' => $firstRun->id,
        'last_seen_at' => now(),
    ]);

    $processor = new ProductImportProcessor(new ProductPayloadNormalizer);

    $summary = $processor->processBatch([
        new ProductPayload(
            externalId: 'PRICE-1',
            name: 'Новое имя',
            priceAmount: 350,
            discountPrice: 320,
            currency: 'EUR',
            inStock: true,
            qty: 12,
            video: productImportRutubeVideoBlock('new-price-video'),
        ),
    ], [
        'supplier' => 'selective_price_supplier',
        'run_id' => $secondRun->id,
        'update_existing' => true,
        'update_existing_mode' => ExistingProductUpdateSelection::MODE_SELECTED,
        'update_existing_fields' => [ExistingProductUpdateSelection::FIELD_PRICE],
    ]);

    expect($summary['processed'])->toBe(1)
        ->and($summary['updated'])->toBe(1)
        ->and($summary['errors'])->toBe(0);

    $product->refresh();

    expect($product->name)->toBe('Исходный товар')
        ->and($product->price_amount)->toBe(350)
        ->and($product->discount_price)->toBe(90)
        ->and($product->currency)->toBe('USD')
        ->and($product->in_stock)->toBeFalse()
        ->and($product->qty)->toBe(5)
        ->and($product->video)->toBe(productImportRutubeVideoBlock('old-price-video'));
});

it('updates only selected availability field for existing products', function (): void {
    $firstRun = createImportRun('catalog_import_yml');
    $secondRun = createImportRun('catalog_import_yml');

    $product = Product::query()->create([
        'name' => 'Исходный товар',
        'slug' => 'selective-availability-product',
        'price_amount' => 100,
        'currency' => 'RUB',
        'in_stock' => false,
        'qty' => 7,
        'is_active' => true,
        'is_in_yml_feed' => true,
        'with_dns' => true,
    ]);

    ProductSupplierReference::query()->create([
        'supplier' => 'selective_availability_supplier',
        'external_id' => 'STOCK-1',
        'product_id' => $product->id,
        'first_seen_run_id' => $firstRun->id,
        'last_seen_run_id' => $firstRun->id,
        'last_seen_at' => now(),
    ]);

    $processor = new ProductImportProcessor(new ProductPayloadNormalizer);

    $summary = $processor->processBatch([
        new ProductPayload(
            externalId: 'STOCK-1',
            name: 'Новое имя',
            priceAmount: 250,
            inStock: true,
            qty: 0,
        ),
    ], [
        'supplier' => 'selective_availability_supplier',
        'run_id' => $secondRun->id,
        'update_existing' => true,
        'update_existing_mode' => ExistingProductUpdateSelection::MODE_SELECTED,
        'update_existing_fields' => [ExistingProductUpdateSelection::FIELD_AVAILABILITY],
    ]);

    expect($summary['processed'])->toBe(1)
        ->and($summary['updated'])->toBe(1)
        ->and($summary['errors'])->toBe(0);

    $product->refresh();

    expect($product->name)->toBe('Исходный товар')
        ->and($product->price_amount)->toBe(100)
        ->and($product->in_stock)->toBeTrue()
        ->and($product->qty)->toBe(7);
});

it('preserves existing pricing on update when payload price is missing and option is enabled', function (): void {
    $firstRun = createImportRun('catalog_import_metaltec');
    $secondRun = createImportRun('catalog_import_metaltec');

    $product = Product::query()->create([
        'name' => 'Товар с ценой',
        'slug' => 'product-with-price',
        'price_amount' => 150000,
        'discount_price' => 140000,
        'currency' => 'RUB',
        'in_stock' => true,
        'qty' => 5,
        'is_active' => true,
        'is_in_yml_feed' => true,
        'with_dns' => true,
    ]);

    ProductSupplierReference::query()->create([
        'supplier' => 'metaltec',
        'external_id' => '469',
        'product_id' => $product->id,
        'first_seen_run_id' => $firstRun->id,
        'last_seen_run_id' => $firstRun->id,
        'last_seen_at' => now(),
    ]);

    $processor = new ProductImportProcessor(new ProductPayloadNormalizer);

    $summary = $processor->processBatch([
        new ProductPayload(
            externalId: '469',
            name: 'Товар с обновленным названием',
            inStock: false,
            qty: 0,
        ),
    ], [
        'supplier' => 'metaltec',
        'run_id' => $secondRun->id,
        'update_existing' => true,
        'preserve_missing_price_on_update' => true,
    ]);

    expect($summary['processed'])->toBe(1)
        ->and($summary['updated'])->toBe(1)
        ->and($summary['errors'])->toBe(0);

    $product->refresh();

    expect($product->name)->toBe('Товар с обновленным названием')
        ->and($product->price_amount)->toBe(150000)
        ->and($product->discount_price)->toBe(140000)
        ->and($product->currency)->toBe('RUB')
        ->and($product->in_stock)->toBeFalse()
        ->and($product->qty)->toBe(0);
});

it('does not change is_active for existing product during update', function (): void {
    $firstRun = createImportRun('catalog_import_yml');
    $secondRun = createImportRun('catalog_import_yml');

    $product = Product::query()->create([
        'name' => 'Товар до обновления',
        'slug' => 'product-with-fixed-active-state',
        'price_amount' => 100,
        'currency' => 'RUB',
        'in_stock' => true,
        'is_active' => true,
        'is_in_yml_feed' => true,
        'with_dns' => true,
    ]);

    ProductSupplierReference::query()->create([
        'supplier' => 'supplier_fixed_active',
        'external_id' => 'FIX-1',
        'product_id' => $product->id,
        'first_seen_run_id' => $firstRun->id,
        'last_seen_run_id' => $firstRun->id,
        'last_seen_at' => now(),
    ]);

    $processor = new ProductImportProcessor(new ProductPayloadNormalizer);

    $summary = $processor->processBatch([
        new ProductPayload(
            externalId: 'FIX-1',
            name: 'Товар после обновления',
            priceAmount: 150,
        ),
    ], [
        'supplier' => 'supplier_fixed_active',
        'run_id' => $secondRun->id,
        'update_existing' => true,
        'publish_updated' => false,
    ]);

    expect($summary['processed'])->toBe(1)
        ->and($summary['updated'])->toBe(1);

    $product->refresh();

    expect($product->name)->toBe('Товар после обновления')
        ->and($product->price_amount)->toBe(150)
        ->and($product->is_active)->toBeTrue();
});

it('marks existing product as unchanged when payload has no effective changes', function (): void {
    $firstRun = createImportRun('catalog_import_yml');
    $secondRun = createImportRun('catalog_import_yml');

    $product = Product::query()->create([
        'name' => 'Без изменений',
        'slug' => 'unchanged-product',
        'price_amount' => 0,
        'currency' => 'RUB',
        'in_stock' => false,
        'is_active' => true,
        'is_in_yml_feed' => true,
        'with_dns' => true,
        'description' => '<p></p>',
        'extra_description' => '<p></p>',
        'meta_title' => 'Без изменений',
    ]);

    $originalUpdatedAt = optional($product->updated_at)->toDateTimeString();

    ProductSupplierReference::query()->create([
        'supplier' => 'supplier_same',
        'external_id' => 'SAME-1',
        'product_id' => $product->id,
        'first_seen_run_id' => $firstRun->id,
        'last_seen_run_id' => $firstRun->id,
        'last_seen_at' => now(),
    ]);

    $processor = new ProductImportProcessor(new ProductPayloadNormalizer);
    $logger = new DatabaseImportRunEventLogger(batchSize: 1);

    $summary = $processor->processBatch([
        new ProductPayload(
            externalId: 'SAME-1',
            name: 'Без изменений',
        ),
    ], [
        'supplier' => 'supplier_same',
        'run_id' => $secondRun->id,
        'update_existing' => true,
        'event_logger' => $logger,
        'source_ref' => 'offer:SAME-1',
    ]);

    $logger->flush();
    $product->refresh();

    expect($summary['processed'])->toBe(1)
        ->and($summary['created'])->toBe(0)
        ->and($summary['updated'])->toBe(0)
        ->and($summary['skipped'])->toBe(1)
        ->and($summary['results'])->toHaveCount(1)
        ->and($summary['results'][0]->operation)->toBe('unchanged');

    $event = ImportRunEvent::query()->where('run_id', $secondRun->id)->latest('id')->first();

    expect($event)->not->toBeNull()
        ->and($event?->stage)->toBe('processing')
        ->and($event?->result)->toBe('unchanged')
        ->and($event?->code)->toBe('unchanged');

    expect(optional($product->updated_at)->toDateTimeString())->toBe($originalUpdatedAt);
    expect(
        ProductSupplierReference::query()
            ->where('supplier', 'supplier_same')
            ->where('external_id', 'SAME-1')
            ->value('last_seen_run_id')
    )->toBe($secondRun->id);
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

it('writes detailed run events when event logger is provided', function (): void {
    $run = createImportRun('catalog_import_yml');

    $processor = new ProductImportProcessor(new ProductPayloadNormalizer);
    $logger = new DatabaseImportRunEventLogger(batchSize: 1);

    $summary = $processor->processBatch([
        new ProductPayload(externalId: 'LOG-1', name: 'Логируемый товар'),
    ], [
        'supplier' => 'event_feed',
        'run_id' => $run->id,
        'event_logger' => $logger,
        'source_ref' => 'offer:LOG-1',
    ]);

    $logger->flush();

    expect($summary['created'])->toBe(1);
    expect(ImportRunEvent::query()->count())->toBe(1);

    $event = ImportRunEvent::query()->first();

    expect($event)->not->toBeNull()
        ->and($event?->stage)->toBe('processing')
        ->and($event?->result)->toBe('created')
        ->and($event?->external_id)->toBe('LOG-1')
        ->and($event?->source_ref)->toBe('offer:LOG-1')
        ->and((int) $event?->run_id)->toBe($run->id)
        ->and($event?->context)->toBeArray()
        ->and($event?->context['created']['name'] ?? null)->toBe('Логируемый товар')
        ->and($event?->context['media']['queued'] ?? null)->toBe(0)
        ->and($event?->context['media']['reused'] ?? null)->toBe(0)
        ->and($event?->context['media']['deduplicated'] ?? null)->toBe(0);
});

it('writes changed fields to event context for updated products', function (): void {
    $firstRun = createImportRun('catalog_import_yml');
    $secondRun = createImportRun('catalog_import_yml');

    $product = Product::query()->create([
        'name' => 'Старое имя',
        'slug' => 'event-context-update-product',
        'price_amount' => 100,
        'currency' => 'RUB',
        'in_stock' => false,
        'is_active' => true,
        'is_in_yml_feed' => true,
        'with_dns' => true,
    ]);

    ProductSupplierReference::query()->create([
        'supplier' => 'context_feed',
        'external_id' => 'CTX-1',
        'product_id' => $product->id,
        'first_seen_run_id' => $firstRun->id,
        'last_seen_run_id' => $firstRun->id,
        'last_seen_at' => now(),
    ]);

    $processor = new ProductImportProcessor(new ProductPayloadNormalizer);
    $logger = new DatabaseImportRunEventLogger(batchSize: 1);

    $processor->processBatch([
        new ProductPayload(
            externalId: 'CTX-1',
            name: 'Новое имя',
            priceAmount: 150,
            inStock: true,
        ),
    ], [
        'supplier' => 'context_feed',
        'run_id' => $secondRun->id,
        'update_existing' => true,
        'event_logger' => $logger,
    ]);

    $logger->flush();

    $event = ImportRunEvent::query()
        ->where('run_id', $secondRun->id)
        ->where('result', 'updated')
        ->latest('id')
        ->first();

    expect($event)->not->toBeNull()
        ->and($event?->context)->toBeArray()
        ->and($event?->context['changes']['name']['before'] ?? null)->toBe('Старое имя')
        ->and($event?->context['changes']['name']['after'] ?? null)->toBe('Новое имя')
        ->and($event?->context['changes']['price_amount']['before'] ?? null)->toBe(100)
        ->and($event?->context['changes']['price_amount']['after'] ?? null)->toBe(150)
        ->and($event?->context['changes']['in_stock']['before'] ?? null)->toBeFalse()
        ->and($event?->context['changes']['in_stock']['after'] ?? null)->toBeTrue();
});

it('writes gallery changes to event context as arrays, not json strings', function (): void {
    $firstRun = createImportRun('catalog_import_yml');
    $secondRun = createImportRun('catalog_import_yml');

    $product = Product::query()->create([
        'name' => 'Товар с галереей',
        'slug' => 'gallery-context-product',
        'price_amount' => 100,
        'currency' => 'RUB',
        'in_stock' => true,
        'is_active' => true,
        'is_in_yml_feed' => true,
        'with_dns' => true,
        'image' => '/storage/184/LB22M70.jpg',
        'thumb' => '/storage/184/LB22M70.jpg',
        'gallery' => ['/storage/184/LB22M70.jpg', '/storage/185/kit.jpg'],
    ]);

    ProductSupplierReference::query()->create([
        'supplier' => 'gallery_feed',
        'external_id' => 'GALLERY-1',
        'product_id' => $product->id,
        'first_seen_run_id' => $firstRun->id,
        'last_seen_run_id' => $firstRun->id,
        'last_seen_at' => now(),
    ]);

    $processor = new ProductImportProcessor(new ProductPayloadNormalizer);
    $logger = new DatabaseImportRunEventLogger(batchSize: 1);

    $processor->processBatch([
        new ProductPayload(
            externalId: 'GALLERY-1',
            name: 'Товар с галереей',
            images: [
                'https://vactool.ru/storage/184/LB22M70.jpg',
                'https://vactool.ru/storage/185/kit.jpg',
            ],
        ),
    ], [
        'supplier' => 'gallery_feed',
        'run_id' => $secondRun->id,
        'update_existing' => true,
        'event_logger' => $logger,
    ]);

    $logger->flush();

    $event = ImportRunEvent::query()
        ->where('run_id', $secondRun->id)
        ->where('result', 'updated')
        ->latest('id')
        ->first();

    expect($event)->not->toBeNull()
        ->and($event?->context)->toBeArray()
        ->and($event?->context['changes']['gallery']['before'] ?? null)->toBe([
            '/storage/184/LB22M70.jpg',
            '/storage/185/kit.jpg',
        ])
        ->and($event?->context['changes']['gallery']['after'] ?? null)->toBe([
            'https://vactool.ru/storage/184/LB22M70.jpg',
            'https://vactool.ru/storage/185/kit.jpg',
        ]);
});

it('does not mark product as updated when only media fields differ and media download is enabled', function (): void {
    Queue::fake();

    $firstRun = createImportRun('catalog_import_yml');
    $secondRun = createImportRun('catalog_import_yml');

    $product = Product::query()->create([
        'name' => 'Товар с изображением',
        'slug' => 'deferred-image-context-product',
        'price_amount' => 100,
        'currency' => 'RUB',
        'in_stock' => true,
        'is_active' => true,
        'is_in_yml_feed' => true,
        'with_dns' => true,
        'description' => '<p></p>',
        'extra_description' => '<p></p>',
        'meta_title' => 'Товар с изображением',
        'image' => '/storage/281/SP502.jpg',
        'thumb' => '/storage/281/SP502.jpg',
        'gallery' => ['/storage/281/SP502.jpg'],
    ]);

    ProductSupplierReference::query()->create([
        'supplier' => 'deferred_media_feed',
        'external_id' => 'DEFERRED-1',
        'product_id' => $product->id,
        'first_seen_run_id' => $firstRun->id,
        'last_seen_run_id' => $firstRun->id,
        'last_seen_at' => now(),
    ]);

    $processor = new ProductImportProcessor(new ProductPayloadNormalizer);
    $logger = new DatabaseImportRunEventLogger(batchSize: 1);

    $processor->processBatch([
        new ProductPayload(
            externalId: 'DEFERRED-1',
            name: 'Товар с изображением',
            priceAmount: 100,
            inStock: true,
            images: ['https://vactool.ru/storage/281/SP502.jpg'],
        ),
    ], [
        'supplier' => 'deferred_media_feed',
        'run_id' => $secondRun->id,
        'update_existing' => true,
        'download_media' => true,
        'update_existing_mode' => ExistingProductUpdateSelection::MODE_SELECTED,
        'update_existing_fields' => [ExistingProductUpdateSelection::FIELD_IMAGES],
        'event_logger' => $logger,
    ]);

    $logger->flush();

    $event = ImportRunEvent::query()
        ->where('run_id', $secondRun->id)
        ->where('result', 'unchanged')
        ->latest('id')
        ->first();

    expect($event)->not->toBeNull()
        ->and($event?->context)->toBeArray()
        ->and($event?->context['media']['queued'] ?? null)->toBe(1)
        ->and($event?->context['media']['reused'] ?? null)->toBe(0)
        ->and($event?->context['changes']['image'] ?? null)->toBeNull()
        ->and($event?->context['changes']['thumb'] ?? null)->toBeNull()
        ->and($event?->context['changes']['gallery'] ?? null)->toBeNull()
        ->and($event?->context['deferred_changes'] ?? null)->toBeNull();
});

it('does not queue media for existing products when images are not selected', function (): void {
    Queue::fake();

    $firstRun = createImportRun('catalog_import_yml');
    $secondRun = createImportRun('catalog_import_yml');

    $product = Product::query()->create([
        'name' => 'Товар без обновления картинок',
        'slug' => 'selective-media-skip-product',
        'price_amount' => 100,
        'currency' => 'RUB',
        'in_stock' => true,
        'is_active' => true,
        'is_in_yml_feed' => true,
        'with_dns' => true,
        'image' => '/storage/281/SP502.jpg',
        'thumb' => '/storage/281/SP502.jpg',
        'gallery' => ['/storage/281/SP502.jpg'],
    ]);

    ProductSupplierReference::query()->create([
        'supplier' => 'selective_media_skip_feed',
        'external_id' => 'MEDIA-1',
        'product_id' => $product->id,
        'first_seen_run_id' => $firstRun->id,
        'last_seen_run_id' => $firstRun->id,
        'last_seen_at' => now(),
    ]);

    $processor = new ProductImportProcessor(new ProductPayloadNormalizer);

    $summary = $processor->processBatch([
        new ProductPayload(
            externalId: 'MEDIA-1',
            name: 'Товар без обновления картинок',
            priceAmount: 250,
            images: ['https://vactool.ru/storage/281/SP502.jpg'],
        ),
    ], [
        'supplier' => 'selective_media_skip_feed',
        'run_id' => $secondRun->id,
        'update_existing' => true,
        'download_media' => true,
        'update_existing_mode' => ExistingProductUpdateSelection::MODE_SELECTED,
        'update_existing_fields' => [ExistingProductUpdateSelection::FIELD_PRICE],
    ]);

    expect($summary['processed'])->toBe(1)
        ->and($summary['updated'])->toBe(1)
        ->and($summary['results'][0]->meta['media_queued'] ?? null)->toBe(0)
        ->and(ProductImportMedia::query()->count())->toBe(0);

    Queue::assertNotPushed(DownloadProductImportMediaJob::class);

    $product->refresh();

    expect($product->price_amount)->toBe(250)
        ->and($product->image)->toBe('/storage/281/SP502.jpg')
        ->and($product->gallery)->toBe(['/storage/281/SP502.jpg']);
});

it('creates new products with full payload when selective existing update mode is configured', function (): void {
    $run = createImportRun('catalog_import_yml');

    $processor = new ProductImportProcessor(new ProductPayloadNormalizer);

    $summary = $processor->processBatch([
        new ProductPayload(
            externalId: 'NEW-SELECTIVE-1',
            name: 'Новый товар',
            brand: 'Новый бренд',
            priceAmount: 12345,
            currency: 'EUR',
            inStock: true,
            qty: 3,
            video: productImportRutubeVideoBlock('new-product-video'),
            images: [
                'https://example.test/new-1.jpg',
                'https://example.test/new-2.jpg',
            ],
        ),
    ], [
        'supplier' => 'new_selective_supplier',
        'run_id' => $run->id,
        'create_missing' => true,
        'update_existing' => true,
        'update_existing_mode' => ExistingProductUpdateSelection::MODE_SELECTED,
        'update_existing_fields' => [ExistingProductUpdateSelection::FIELD_PRICE],
    ]);

    expect($summary['processed'])->toBe(1)
        ->and($summary['created'])->toBe(1)
        ->and($summary['errors'])->toBe(0);

    $product = Product::query()->where('name', 'Новый товар')->first();

    expect($product)->toBeInstanceOf(Product::class)
        ->and($product?->brand)->toBe('Новый бренд')
        ->and($product?->price_amount)->toBe(12345)
        ->and($product?->currency)->toBe('EUR')
        ->and($product?->in_stock)->toBeTrue()
        ->and($product?->qty)->toBe(3)
        ->and($product?->video)->toBe(productImportRutubeVideoBlock('new-product-video'))
        ->and($product?->gallery)->toBe([
            'https://example.test/new-1.jpg',
            'https://example.test/new-2.jpg',
        ]);
});

it('marks product as unchanged when media is fully reused on repeated import', function (): void {
    Storage::fake('public');
    config()->set('catalog-import.media.disk', 'public');
    Queue::fake();

    $firstRun = createImportRun('catalog_import_yml');
    $secondRun = createImportRun('catalog_import_yml');

    $sourceUrl = 'https://vactool.ru/storage/281/SP502.jpg';
    $sourceUrlHash = hash('sha256', $sourceUrl);

    $product = Product::query()->create([
        'name' => 'Товар с переиспользуемой картинкой',
        'slug' => 'reused-image-context-product',
        'price_amount' => 100,
        'currency' => 'RUB',
        'in_stock' => true,
        'is_active' => true,
        'is_in_yml_feed' => true,
        'with_dns' => true,
        'description' => '<p></p>',
        'extra_description' => '<p></p>',
        'meta_title' => 'Товар с переиспользуемой картинкой',
        'image' => 'pics/import/reused/source.jpg',
        'thumb' => 'pics/import/reused/source.jpg',
        'gallery' => ['pics/import/reused/source.jpg'],
    ]);

    ProductSupplierReference::query()->create([
        'supplier' => 'reused_media_feed',
        'external_id' => 'REUSED-1',
        'product_id' => $product->id,
        'first_seen_run_id' => $firstRun->id,
        'last_seen_run_id' => $firstRun->id,
        'last_seen_at' => now(),
    ]);

    ProductImportMedia::query()->create([
        'run_id' => $firstRun->id,
        'product_id' => $product->id,
        'source_url' => $sourceUrl,
        'source_url_hash' => $sourceUrlHash,
        'source_kind' => 'image',
        'status' => 'completed',
        'mime_type' => 'image/jpeg',
        'bytes' => 1024,
        'content_hash' => hash('sha256', 'fake-content'),
        'local_path' => 'pics/import/reused/source.jpg',
        'attempts' => 1,
        'processed_at' => now(),
    ]);

    Storage::disk('public')->put('pics/import/reused/source.jpg', 'existing-image-bytes');

    $processor = new ProductImportProcessor(new ProductPayloadNormalizer);
    $logger = new DatabaseImportRunEventLogger(batchSize: 1);

    $summary = $processor->processBatch([
        new ProductPayload(
            externalId: 'REUSED-1',
            name: 'Товар с переиспользуемой картинкой',
            priceAmount: 100,
            inStock: true,
            images: [$sourceUrl],
        ),
    ], [
        'supplier' => 'reused_media_feed',
        'run_id' => $secondRun->id,
        'update_existing' => true,
        'download_media' => true,
        'event_logger' => $logger,
    ]);

    $logger->flush();

    expect($summary['updated'])->toBe(0)
        ->and($summary['skipped'])->toBe(1)
        ->and($summary['results'][0]->operation ?? null)->toBe('unchanged')
        ->and($summary['results'][0]->meta['media_queued'] ?? null)->toBe(0)
        ->and($summary['results'][0]->meta['media_reused'] ?? null)->toBe(1);

    $event = ImportRunEvent::query()
        ->where('run_id', $secondRun->id)
        ->where('result', 'unchanged')
        ->latest('id')
        ->first();

    expect($event)->not->toBeNull()
        ->and($event?->context)->toBeArray()
        ->and($event?->context['media']['queued'] ?? null)->toBe(0)
        ->and($event?->context['media']['reused'] ?? null)->toBe(1)
        ->and($event?->context['deferred_changes'] ?? null)->toBeNull();
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

it('removes deactivated products from search index during finalize missing', function (): void {
    $firstRun = createImportRun('catalog_import_yml');
    $secondRun = createImportRun('catalog_import_yml');
    $searchSync = Mockery::mock(ProductSearchSync::class);
    $searchSync->shouldReceive('removeIds')
        ->once()
        ->withArgs(function (array $ids): bool {
            expect($ids)->toHaveCount(1);

            return true;
        })
        ->andReturn(1);

    $processor = new ProductImportProcessor(
        normalizer: new ProductPayloadNormalizer,
        searchSync: $searchSync,
    );

    $processor->processBatch([
        new ProductPayload(externalId: 'SYNC-KEEP', name: 'Остается в поиске', inStock: true, qty: 5),
        new ProductPayload(externalId: 'SYNC-REMOVE', name: 'Пропадает из поиска', inStock: true, qty: 2),
    ], [
        'supplier' => 'search_finalize_feed',
        'run_id' => $firstRun->id,
    ]);

    $processor->processBatch([
        new ProductPayload(externalId: 'SYNC-KEEP', name: 'Остается в поиске', inStock: true, qty: 4),
    ], [
        'supplier' => 'search_finalize_feed',
        'run_id' => $secondRun->id,
    ]);

    $result = $processor->finalizeMissing('search_finalize_feed', $secondRun->id, [
        'mode' => 'full_sync_authoritative',
        'finalize_missing' => true,
    ]);

    expect($result['deactivated'])->toBe(1);
});

it('logs finalize deactivation events when finalize missing is applied', function (): void {
    $firstRun = createImportRun('catalog_import_yml');
    $secondRun = createImportRun('catalog_import_yml');

    $processor = new ProductImportProcessor(new ProductPayloadNormalizer);
    $logger = new DatabaseImportRunEventLogger(batchSize: 1);

    $processor->processBatch([
        new ProductPayload(externalId: 'F-1', name: 'Остается', inStock: true, qty: 10),
        new ProductPayload(externalId: 'F-2', name: 'Исчезает', inStock: true, qty: 1),
    ], [
        'supplier' => 'finalize_feed',
        'run_id' => $firstRun->id,
        'event_logger' => $logger,
    ]);

    $processor->processBatch([
        new ProductPayload(externalId: 'F-1', name: 'Остается', inStock: true, qty: 8),
    ], [
        'supplier' => 'finalize_feed',
        'run_id' => $secondRun->id,
        'event_logger' => $logger,
    ]);

    $processor->finalizeMissing('finalize_feed', $secondRun->id, [
        'mode' => 'full_sync_authoritative',
        'finalize_missing' => true,
        'event_logger' => $logger,
    ]);

    $logger->flush();

    expect(
        ImportRunEvent::query()
            ->where('run_id', $secondRun->id)
            ->where('stage', 'finalize')
            ->where('result', 'deactivated')
            ->where('code', 'missing_in_feed')
            ->exists()
    )->toBeTrue();
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
    Schema::dropIfExists('import_run_events');
    Schema::dropIfExists('product_supplier_references');
    Schema::dropIfExists('product_categories');
    Schema::dropIfExists('product_import_media');
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

    Schema::create('import_run_events', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('run_id');
        $table->string('supplier', 120)->nullable();
        $table->string('stage', 32);
        $table->string('result', 32);
        $table->string('source_ref', 2048)->nullable();
        $table->string('external_id')->nullable();
        $table->unsignedBigInteger('product_id')->nullable();
        $table->unsignedInteger('source_category_id')->nullable();
        $table->integer('row_index')->nullable();
        $table->string('code', 64)->nullable();
        $table->text('message')->nullable();
        $table->json('context')->nullable();
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

    Schema::create('product_import_media', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('run_id')->nullable();
        $table->unsignedBigInteger('product_id');
        $table->text('source_url');
        $table->char('source_url_hash', 64);
        $table->string('source_kind', 24)->default('image');
        $table->string('status', 16)->default('pending');
        $table->string('mime_type', 191)->nullable();
        $table->unsignedBigInteger('bytes')->nullable();
        $table->char('content_hash', 64)->nullable();
        $table->string('local_path')->nullable();
        $table->unsignedInteger('attempts')->default(0);
        $table->text('last_error')->nullable();
        $table->timestamp('processed_at')->nullable();
        $table->json('meta')->nullable();
        $table->timestamps();

        $table->unique(['product_id', 'source_url_hash']);
        $table->index(['status', 'created_at']);
        $table->index('source_url_hash');
        $table->index('content_hash');

        $table->foreign('run_id')->references('id')->on('import_runs')->nullOnDelete();
        $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
    });
}

function productImportRutubeVideoBlock(string $rutubeId): string
{
    return '<div data-type="customBlock" data-config="{&quot;rutube_id&quot;:&quot;'
        .$rutubeId
        .'&quot;,&quot;width&quot;:640,&quot;alignment&quot;:&quot;center&quot;}" data-id="rutube-video"></div>';
}
