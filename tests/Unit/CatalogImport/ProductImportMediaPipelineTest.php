<?php

use App\Jobs\DownloadProductImportMediaJob;
use App\Jobs\GenerateImageDerivativesJob;
use App\Models\ImportMediaIssue;
use App\Models\ImportRun;
use App\Models\Product;
use App\Models\ProductImportMedia;
use App\Models\ProductSupplierReference;
use App\Support\CatalogImport\DTO\ProductPayload;
use App\Support\CatalogImport\Media\ProductImportMediaService;
use App\Support\CatalogImport\Processing\ProductImportProcessor;
use App\Support\CatalogImport\Processing\ProductPayloadNormalizer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    prepareProductImportMediaPipelineSchemas();
});

it('queues media downloads without blocking product upsert', function (): void {
    Queue::fake();

    $run = createProductImportMediaRun('catalog_import_yml');

    $processor = new ProductImportProcessor(new ProductPayloadNormalizer, new ProductImportMediaService);

    $summary = $processor->processBatch([
        new ProductPayload(
            externalId: 'M-1',
            name: 'Товар с медиа',
            images: [
                'https://cdn.example.test/media/a.jpg',
                'https://cdn.example.test/media/a.jpg',
                'https://cdn.example.test/media/b.jpg',
            ],
        ),
    ], [
        'supplier' => 'media_feed',
        'run_id' => $run->id,
        'download_media' => true,
    ]);

    expect($summary['created'])->toBe(1)
        ->and($summary['errors'])->toBe(0)
        ->and(ProductImportMedia::query()->count())->toBe(2)
        ->and(ProductImportMedia::query()->where('status', ProductImportMedia::STATUS_PENDING)->count())->toBe(2)
        ->and(ImportMediaIssue::query()->count())->toBe(0);

    Queue::assertPushed(DownloadProductImportMediaJob::class, 2);
});

it('deduplicates media queue by url for same product across repeated imports', function (): void {
    Queue::fake();

    $runOne = createProductImportMediaRun('catalog_import_yml');
    $runTwo = createProductImportMediaRun('catalog_import_yml');

    $processor = new ProductImportProcessor(new ProductPayloadNormalizer, new ProductImportMediaService);

    $processor->processBatch([
        new ProductPayload(
            externalId: 'M-2',
            name: 'Повторяющийся медиа-товар',
            images: [
                'https://cdn.example.test/media/one.jpg',
                'https://cdn.example.test/media/one.jpg',
                'https://cdn.example.test/media/two.jpg',
            ],
        ),
    ], [
        'supplier' => 'media_feed_repeat',
        'run_id' => $runOne->id,
        'download_media' => true,
    ]);

    $processor->processBatch([
        new ProductPayload(
            externalId: 'M-2',
            name: 'Повторяющийся медиа-товар',
            images: [
                'https://cdn.example.test/media/one.jpg',
                'https://cdn.example.test/media/two.jpg',
                'https://cdn.example.test/media/three.jpg',
            ],
        ),
    ], [
        'supplier' => 'media_feed_repeat',
        'run_id' => $runTwo->id,
        'download_media' => true,
    ]);

    expect(ProductImportMedia::query()->count())->toBe(3)
        ->and(ProductImportMedia::query()->where('status', ProductImportMedia::STATUS_PENDING)->count())->toBe(3);

    Queue::assertPushed(DownloadProductImportMediaJob::class, 3);
});

it('restores local product image when media already completed for same source url', function (): void {
    Storage::fake('public');
    config()->set('catalog-import.media.disk', 'public');
    Queue::fake();

    $runOne = createProductImportMediaRun('catalog_import_yml');
    $runTwo = createProductImportMediaRun('catalog_import_yml');

    $product = Product::query()->create([
        'name' => 'Товар с ранее скачанным изображением',
        'slug' => 'product-with-completed-media',
        'price_amount' => 100,
        'currency' => 'RUB',
        'in_stock' => true,
        'is_active' => true,
        'is_in_yml_feed' => true,
        'with_dns' => true,
        'image' => 'pics/import/existing-local.jpg',
        'thumb' => 'pics/import/existing-local.jpg',
        'gallery' => ['pics/import/existing-local.jpg'],
    ]);

    ProductSupplierReference::query()->create([
        'supplier' => 'media_restore_feed',
        'external_id' => 'M-RESTORE-1',
        'product_id' => $product->id,
        'first_seen_run_id' => $runOne->id,
        'last_seen_run_id' => $runOne->id,
        'last_seen_at' => now(),
    ]);

    $sourceUrl = 'https://cdn.example.test/media/existing.jpg';

    ProductImportMedia::query()->create([
        'run_id' => $runOne->id,
        'product_id' => $product->id,
        'source_url' => $sourceUrl,
        'source_url_hash' => hash('sha256', $sourceUrl),
        'source_kind' => 'image',
        'status' => ProductImportMedia::STATUS_COMPLETED,
        'local_path' => 'pics/import/existing-local.jpg',
        'mime_type' => 'image/jpeg',
        'bytes' => 1024,
        'content_hash' => hash('sha256', 'existing-local-bytes'),
        'processed_at' => now(),
    ]);

    Storage::disk('public')->put('pics/import/existing-local.jpg', 'existing-image-bytes');
    expect((new ProductImportMediaService)->pathExistsOnDisk('pics/import/existing-local.jpg'))->toBeTrue();

    $processor = new ProductImportProcessor(new ProductPayloadNormalizer, new ProductImportMediaService);

    $summary = $processor->processBatch([
        new ProductPayload(
            externalId: 'M-RESTORE-1',
            name: 'Товар с ранее скачанным изображением',
            images: [$sourceUrl],
        ),
    ], [
        'supplier' => 'media_restore_feed',
        'run_id' => $runTwo->id,
        'download_media' => true,
        'update_existing' => true,
    ]);

    $product->refresh();

    expect($summary['updated'])->toBe(1)
        ->and($summary['results'])->toHaveCount(1)
        ->and($summary['results'][0]->meta['media_reused'] ?? null)->toBe(1)
        ->and($summary['results'][0]->meta['media_queued'] ?? null)->toBe(0)
        ->and($product->image)->toBe('pics/import/existing-local.jpg')
        ->and($product->thumb)->toBe('pics/import/existing-local.jpg')
        ->and($product->gallery)->toBe(['pics/import/existing-local.jpg'])
        ->and(ProductImportMedia::query()->count())->toBe(1);

    Queue::assertNotPushed(DownloadProductImportMediaJob::class);
});

it('requeues failed media for same product and source url on next import', function (): void {
    Queue::fake();

    $runOne = createProductImportMediaRun('catalog_import_yml');
    $runTwo = createProductImportMediaRun('catalog_import_yml');

    $product = Product::query()->create([
        'name' => 'Товар с failed-медиа',
        'slug' => 'product-with-failed-media',
        'price_amount' => 100,
        'currency' => 'RUB',
        'in_stock' => true,
        'is_active' => true,
        'is_in_yml_feed' => true,
        'with_dns' => true,
    ]);

    ProductSupplierReference::query()->create([
        'supplier' => 'media_retry_feed',
        'external_id' => 'M-RETRY-1',
        'product_id' => $product->id,
        'first_seen_run_id' => $runOne->id,
        'last_seen_run_id' => $runOne->id,
        'last_seen_at' => now(),
    ]);

    $sourceUrl = 'https://cdn.example.test/media/retry.jpg';

    ProductImportMedia::query()->create([
        'run_id' => $runOne->id,
        'product_id' => $product->id,
        'source_url' => $sourceUrl,
        'source_url_hash' => hash('sha256', $sourceUrl),
        'source_kind' => 'image',
        'status' => ProductImportMedia::STATUS_FAILED,
        'last_error' => 'temporary network issue',
        'processed_at' => now(),
    ]);

    $processor = new ProductImportProcessor(new ProductPayloadNormalizer, new ProductImportMediaService);

    $summary = $processor->processBatch([
        new ProductPayload(
            externalId: 'M-RETRY-1',
            name: 'Товар с failed-медиа',
            images: [$sourceUrl],
        ),
    ], [
        'supplier' => 'media_retry_feed',
        'run_id' => $runTwo->id,
        'download_media' => true,
        'update_existing' => true,
    ]);

    expect($summary['updated'])->toBe(1)
        ->and($summary['results'])->toHaveCount(1)
        ->and($summary['results'][0]->meta['media_queued'] ?? null)->toBe(1)
        ->and(ProductImportMedia::query()->count())->toBe(1)
        ->and(
            ProductImportMedia::query()
                ->where('run_id', $runTwo->id)
                ->where('status', ProductImportMedia::STATUS_PENDING)
                ->count()
        )->toBe(1);

    Queue::assertPushed(DownloadProductImportMediaJob::class, 1);
});

it('forces media recheck for fresh completed media when option is enabled', function (): void {
    Storage::fake('public');
    config()->set('catalog-import.media.disk', 'public');
    Queue::fake();

    $firstRun = createProductImportMediaRun('catalog_import_yml');
    $secondRun = createProductImportMediaRun('catalog_import_yml');

    $product = Product::query()->create([
        'name' => 'Товар с принудительной перепроверкой медиа',
        'slug' => 'force-recheck-media-product',
        'price_amount' => 100,
        'currency' => 'RUB',
        'in_stock' => true,
        'is_active' => true,
        'is_in_yml_feed' => true,
        'with_dns' => true,
        'meta_title' => 'Товар с принудительной перепроверкой медиа',
    ]);

    ProductSupplierReference::query()->create([
        'supplier' => 'force_media_feed',
        'external_id' => 'FORCE-1',
        'product_id' => $product->id,
        'first_seen_run_id' => $firstRun->id,
        'last_seen_run_id' => $firstRun->id,
        'last_seen_at' => now(),
    ]);

    $sourceUrl = 'https://cdn.example.test/media/force.jpg';

    ProductImportMedia::query()->create([
        'run_id' => $firstRun->id,
        'product_id' => $product->id,
        'source_url' => $sourceUrl,
        'source_url_hash' => hash('sha256', $sourceUrl),
        'source_kind' => 'image',
        'status' => ProductImportMedia::STATUS_COMPLETED,
        'local_path' => 'pics/import/force.jpg',
        'mime_type' => 'image/jpeg',
        'bytes' => 128,
        'content_hash' => hash('sha256', 'force-seed'),
        'processed_at' => now(),
        'meta' => [
            'etag' => '"force-v1"',
            'last_checked_at' => now()->toAtomString(),
        ],
    ]);

    Storage::disk('public')->put('pics/import/force.jpg', 'force-seed-bytes');

    $processor = new ProductImportProcessor(new ProductPayloadNormalizer, new ProductImportMediaService);

    $summary = $processor->processBatch([
        new ProductPayload(
            externalId: 'FORCE-1',
            name: 'Товар с принудительной перепроверкой медиа',
            priceAmount: 100,
            inStock: true,
            images: [$sourceUrl],
        ),
    ], [
        'supplier' => 'force_media_feed',
        'run_id' => $secondRun->id,
        'update_existing' => true,
        'download_media' => true,
        'force_media_recheck' => true,
    ]);

    $media = ProductImportMedia::query()->where('product_id', $product->id)->firstOrFail();

    expect($summary['results'])->toHaveCount(1)
        ->and($summary['results'][0]->meta['media_queued'] ?? null)->toBe(1)
        ->and($summary['results'][0]->meta['media_reused'] ?? null)->toBe(0)
        ->and($media->status)->toBe(ProductImportMedia::STATUS_PENDING)
        ->and(data_get($media->meta, 'recheck.required'))->toBeTrue()
        ->and(data_get($media->meta, 'recheck.force'))->toBeTrue();

    Queue::assertPushed(DownloadProductImportMediaJob::class, 1);
});

it('uses conditional headers for stale media recheck and keeps local file on 304', function (): void {
    Storage::fake('public');
    config()->set('catalog-import.media.disk', 'public');
    config()->set('catalog-import.media.recheck_ttl_seconds', 60 * 60);
    config()->set('catalog-import.media.use_conditional_headers_for_recheck', true);
    Queue::fake();

    $firstRun = createProductImportMediaRun('catalog_import_yml');
    $secondRun = createProductImportMediaRun('catalog_import_yml');

    Http::fake([
        'https://cdn.example.test/media/stale.jpg' => Http::response('', 304, [
            'ETag' => '"stale-v2"',
            'Last-Modified' => 'Mon, 01 Jan 2024 00:00:00 GMT',
        ]),
    ]);

    $product = Product::query()->create([
        'name' => 'Товар со stale-медиа',
        'slug' => 'stale-media-product',
        'price_amount' => 100,
        'currency' => 'RUB',
        'in_stock' => true,
        'is_active' => true,
        'is_in_yml_feed' => true,
        'with_dns' => true,
    ]);

    ProductSupplierReference::query()->create([
        'supplier' => 'stale_media_feed',
        'external_id' => 'STALE-1',
        'product_id' => $product->id,
        'first_seen_run_id' => $firstRun->id,
        'last_seen_run_id' => $firstRun->id,
        'last_seen_at' => now()->subDays(8),
    ]);

    $sourceUrl = 'https://cdn.example.test/media/stale.jpg';

    $media = ProductImportMedia::query()->create([
        'run_id' => $firstRun->id,
        'product_id' => $product->id,
        'source_url' => $sourceUrl,
        'source_url_hash' => hash('sha256', $sourceUrl),
        'source_kind' => 'image',
        'status' => ProductImportMedia::STATUS_COMPLETED,
        'local_path' => 'pics/import/stale.jpg',
        'mime_type' => 'image/jpeg',
        'bytes' => 128,
        'content_hash' => hash('sha256', 'stale-seed'),
        'processed_at' => now()->subDays(8),
        'meta' => [
            'etag' => '"stale-v1"',
            'last_modified' => 'Sun, 31 Dec 2023 00:00:00 GMT',
            'last_checked_at' => now()->subDays(8)->toAtomString(),
        ],
    ]);

    Storage::disk('public')->put('pics/import/stale.jpg', 'stale-seed-bytes');

    $processor = new ProductImportProcessor(new ProductPayloadNormalizer, new ProductImportMediaService);

    $summary = $processor->processBatch([
        new ProductPayload(
            externalId: 'STALE-1',
            name: 'Товар со stale-медиа',
            images: [$sourceUrl],
        ),
    ], [
        'supplier' => 'stale_media_feed',
        'run_id' => $secondRun->id,
        'update_existing' => true,
        'download_media' => true,
    ]);

    expect($summary['results'])->toHaveCount(1)
        ->and($summary['results'][0]->meta['media_queued'] ?? null)->toBe(1)
        ->and($summary['results'][0]->meta['media_reused'] ?? null)->toBe(0);

    Queue::assertPushed(DownloadProductImportMediaJob::class, 1);

    (new DownloadProductImportMediaJob($media->id))->handle(new ProductImportMediaService);

    $media->refresh();

    expect($media->status)->toBe(ProductImportMedia::STATUS_COMPLETED)
        ->and($media->local_path)->toBe('pics/import/stale.jpg')
        ->and(data_get($media->meta, 'etag'))->toBe('"stale-v2"')
        ->and(data_get($media->meta, 'last_modified'))->toBe('Mon, 01 Jan 2024 00:00:00 GMT')
        ->and(data_get($media->meta, 'last_checked_at'))->not->toBeNull();

    Http::assertSent(static function ($request) use ($sourceUrl): bool {
        return $request->url() === $sourceUrl
            && $request->hasHeader('If-None-Match', '"stale-v1"')
            && $request->hasHeader('If-Modified-Since', 'Sun, 31 Dec 2023 00:00:00 GMT');
    });

    Queue::assertNotPushed(GenerateImageDerivativesJob::class);
});

it('downloads media in queued job and deduplicates by content hash', function (): void {
    Storage::fake('public');
    Queue::fake();

    Http::fake([
        'https://cdn.example.test/media/hash-a.jpg' => Http::response('same-image-bytes', 200, [
            'Content-Type' => 'image/jpeg',
        ]),
        'https://cdn.example.test/media/hash-b.jpg' => Http::response('same-image-bytes', 200, [
            'Content-Type' => 'image/jpeg',
        ]),
    ]);

    $firstProduct = Product::query()->create([
        'name' => 'Первый товар',
        'slug' => 'first-product',
        'price_amount' => 100,
        'currency' => 'RUB',
        'in_stock' => true,
        'is_active' => true,
        'is_in_yml_feed' => true,
        'with_dns' => true,
    ]);

    $secondProduct = Product::query()->create([
        'name' => 'Второй товар',
        'slug' => 'second-product',
        'price_amount' => 100,
        'currency' => 'RUB',
        'in_stock' => true,
        'is_active' => true,
        'is_in_yml_feed' => true,
        'with_dns' => true,
    ]);

    $mediaOne = ProductImportMedia::query()->create([
        'product_id' => $firstProduct->id,
        'source_url' => 'https://cdn.example.test/media/hash-a.jpg',
        'source_url_hash' => hash('sha256', 'https://cdn.example.test/media/hash-a.jpg'),
        'source_kind' => 'image',
        'status' => ProductImportMedia::STATUS_PENDING,
    ]);

    $mediaTwo = ProductImportMedia::query()->create([
        'product_id' => $secondProduct->id,
        'source_url' => 'https://cdn.example.test/media/hash-b.jpg',
        'source_url_hash' => hash('sha256', 'https://cdn.example.test/media/hash-b.jpg'),
        'source_kind' => 'image',
        'status' => ProductImportMedia::STATUS_PENDING,
    ]);

    $service = new ProductImportMediaService;

    (new DownloadProductImportMediaJob($mediaOne->id))->handle($service);
    (new DownloadProductImportMediaJob($mediaTwo->id))->handle($service);

    $mediaOne->refresh();
    $mediaTwo->refresh();

    expect($mediaOne->status)->toBe(ProductImportMedia::STATUS_COMPLETED)
        ->and($mediaTwo->status)->toBe(ProductImportMedia::STATUS_COMPLETED)
        ->and($mediaOne->content_hash)->toBe($mediaTwo->content_hash)
        ->and($mediaOne->local_path)->toBe($mediaTwo->local_path)
        ->and(is_string($mediaOne->local_path))->toBeTrue();

    Storage::disk('public')->assertExists((string) $mediaOne->local_path);

    $firstProduct->refresh();
    $secondProduct->refresh();

    expect($firstProduct->image)->toBe($mediaOne->local_path)
        ->and($secondProduct->image)->toBe($mediaOne->local_path)
        ->and(ImportMediaIssue::query()->count())->toBe(0);

    Queue::assertPushed(GenerateImageDerivativesJob::class, 1);
});

it('logs media failures separately from main import issues', function (): void {
    Storage::fake('public');
    Queue::fake();

    Http::fake([
        'https://cdn.example.test/media/bad-file' => Http::response('<html>bad</html>', 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]),
    ]);

    $product = Product::query()->create([
        'name' => 'Проблемный товар',
        'slug' => 'broken-product',
        'price_amount' => 100,
        'currency' => 'RUB',
        'in_stock' => true,
        'is_active' => true,
        'is_in_yml_feed' => true,
        'with_dns' => true,
    ]);

    $media = ProductImportMedia::query()->create([
        'product_id' => $product->id,
        'source_url' => 'https://cdn.example.test/media/bad-file',
        'source_url_hash' => hash('sha256', 'https://cdn.example.test/media/bad-file'),
        'source_kind' => 'image',
        'status' => ProductImportMedia::STATUS_PENDING,
    ]);

    $service = new ProductImportMediaService;

    (new DownloadProductImportMediaJob($media->id))->handle($service);

    $media->refresh();

    expect($media->status)->toBe(ProductImportMedia::STATUS_FAILED)
        ->and(ImportMediaIssue::query()->count())->toBe(1)
        ->and(ImportMediaIssue::query()->value('code'))->toBe('unsupported_mime_type');

    Queue::assertNotPushed(GenerateImageDerivativesJob::class);
});

it('fails fast for non-absolute media source urls', function (): void {
    Storage::fake('public');
    Queue::fake();
    Http::fake();

    $product = Product::query()->create([
        'name' => 'Товар с относительным URL медиа',
        'slug' => 'relative-media-url-product',
        'price_amount' => 100,
        'currency' => 'RUB',
        'in_stock' => true,
        'is_active' => true,
        'is_in_yml_feed' => true,
        'with_dns' => true,
    ]);

    $media = ProductImportMedia::query()->create([
        'product_id' => $product->id,
        'source_url' => '/storage/100/main.jpg',
        'source_url_hash' => hash('sha256', '/storage/100/main.jpg'),
        'source_kind' => 'image',
        'status' => ProductImportMedia::STATUS_PENDING,
    ]);

    $service = new ProductImportMediaService;

    (new DownloadProductImportMediaJob($media->id))->handle($service);

    $media->refresh();

    expect($media->status)->toBe(ProductImportMedia::STATUS_FAILED)
        ->and($media->last_error)->toContain('absolute HTTP(S) URL')
        ->and(ImportMediaIssue::query()->count())->toBe(1)
        ->and(ImportMediaIssue::query()->value('code'))->toBe('invalid_source_url');

    Http::assertNothingSent();
    Queue::assertNotPushed(GenerateImageDerivativesJob::class);
});

function createProductImportMediaRun(string $type): ImportRun
{
    return ImportRun::query()->create([
        'type' => $type,
        'status' => 'running',
        'columns' => [],
        'totals' => [],
        'started_at' => now(),
    ]);
}

function prepareProductImportMediaPipelineSchemas(): void
{
    Schema::dropIfExists('import_media_issues');
    Schema::dropIfExists('product_import_media');
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
        $table->unsignedBigInteger('product_id');
        $table->unsignedBigInteger('first_seen_run_id')->nullable();
        $table->unsignedBigInteger('last_seen_run_id')->nullable();
        $table->timestamp('last_seen_at')->nullable();
        $table->timestamps();

        $table->unique(['supplier', 'external_id']);
        $table->index(['supplier', 'product_id']);
        $table->index(['supplier', 'last_seen_run_id']);

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

    Schema::create('import_media_issues', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('media_id')->nullable();
        $table->unsignedBigInteger('run_id')->nullable();
        $table->unsignedBigInteger('product_id')->nullable();
        $table->string('code', 64);
        $table->text('message')->nullable();
        $table->json('context')->nullable();
        $table->timestamps();

        $table->foreign('media_id')->references('id')->on('product_import_media')->nullOnDelete();
        $table->foreign('run_id')->references('id')->on('import_runs')->nullOnDelete();
        $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
    });
}
