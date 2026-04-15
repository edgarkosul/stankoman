<?php

use App\Jobs\DownloadProductImportMediaJob;
use App\Models\Product;
use App\Models\ProductImportMedia;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

it('runs metalmaster parser command in dry-run mode', function () {
    Http::preventStrayRequests();

    $productUrl = 'https://metalmaster.ru/promyshlennye/z50100-dro/';
    $bucketsFile = storage_path('app/testing/metalmaster-buckets-'.Str::lower(Str::random(10)).'.json');

    file_put_contents($bucketsFile, json_encode([
        'meta' => [
            'generated_at' => now()->toIso8601String(),
        ],
        'buckets' => [
            [
                'bucket' => 'promyshlennye',
                'category_url' => 'https://metalmaster.ru/promyshlennye/',
                'products_count' => 1,
                'product_urls' => [$productUrl],
            ],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

    Http::fake([
        $productUrl => Http::response(metalmasterProductHtml(
            title: 'Станок токарно-винторезный Metal Master Z 50100 DRO',
            price: 1049972,
            imageUrl: 'https://metalmaster.ru/files/originals/z50100-main.jpg',
        ), 200),
    ]);

    try {
        $this->artisan('parser:parse-products', [
            '--buckets-file' => $bucketsFile,
            '--dry-run' => 1,
            '--sleep' => 0,
            '--show-samples' => 1,
        ])
            ->expectsOutputToContain('Режим: dry-run')
            ->expectsOutputToContain('OK: '.$productUrl)
            ->assertSuccessful();
    } finally {
        @unlink($bucketsFile);
    }
});

it('prefilters existing supplier references before loading metalmaster pages', function () {
    rebuildMetalmasterParserSchemas();

    $productUrl = 'https://metalmaster.ru/promyshlennye/z50100-dro/';
    $bucketsFile = storage_path('app/testing/metalmaster-buckets-'.Str::lower(Str::random(10)).'.json');

    file_put_contents($bucketsFile, json_encode([
        [
            'bucket' => 'promyshlennye',
            'category_url' => 'https://metalmaster.ru/promyshlennye/',
            'products_count' => 1,
            'product_urls' => [$productUrl],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

    try {
        $existingProduct = Product::query()->create([
            'name' => 'Уже импортированный Metalmaster товар',
            'slug' => 'z50100-dro',
            'price_amount' => 1000,
            'currency' => 'RUB',
            'in_stock' => true,
            'is_active' => true,
        ]);

        DB::table('product_supplier_references')->insert([
            'supplier' => 'metalmaster',
            'external_id' => 'z50100-dro',
            'product_id' => $existingProduct->id,
            'first_seen_run_id' => null,
            'last_seen_run_id' => null,
            'last_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::preventStrayRequests();
        Http::fake();

        $this->artisan('parser:parse-products', [
            '--buckets-file' => $bucketsFile,
            '--sleep' => 0,
            '--write' => true,
            '--skip-existing' => true,
        ])
            ->expectsOutputToContain('SKIP: '.$productUrl)
            ->assertSuccessful();

        Http::assertNothingSent();
        expect(Product::query()->count())->toBe(1);
    } finally {
        @unlink($bucketsFile);
        dropMetalmasterParserSchemas();
    }
});

it('imports metalmaster products into database and attaches staging category', function () {
    rebuildMetalmasterParserSchemas();

    $productUrl = 'https://metalmaster.ru/promyshlennye/z50100-dro/';
    $bucketsFile = storage_path('app/testing/metalmaster-buckets-'.Str::lower(Str::random(10)).'.json');

    file_put_contents($bucketsFile, json_encode([
        [
            'bucket' => 'promyshlennye',
            'category_url' => 'https://metalmaster.ru/promyshlennye/',
            'products_count' => 1,
            'product_urls' => [$productUrl],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

    try {
        Queue::fake();

        $stagingCategoryId = DB::table('categories')->insertGetId([
            'name' => 'Staging',
            'slug' => 'staging',
            'parent_id' => -1,
            'order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $imageUrl = 'https://metalmaster.ru/files/originals/z50100-main.jpg';

        Http::preventStrayRequests();
        Http::fake([
            $productUrl => Http::response(metalmasterProductHtml(
                title: 'Станок токарно-винторезный Metal Master Z 50100 DRO',
                price: 1049972,
                imageUrl: $imageUrl,
            ), 200),
        ]);

        $this->artisan('parser:parse-products', [
            '--buckets-file' => $bucketsFile,
            '--sleep' => 0,
            '--write' => true,
            '--publish' => true,
            '--download-images' => true,
        ])
            ->expectsOutputToContain('Режим: write')
            ->assertSuccessful();

        $product = Product::query()->firstOrFail();
        $rawSpecs = DB::table('products')
            ->where('id', $product->id)
            ->value('specs');

        expect($product->slug)->toBe('z50100-dro');
        expect($product->price_amount)->toBe(1049972);
        expect($product->brand)->toBe('MetalMaster');
        expect($product->is_active)->toBeTrue();
        expect((string) $product->image)->toBe($imageUrl);
        expect($product->gallery)->toBe([$imageUrl]);
        expect($rawSpecs)->toBeString();
        expect($rawSpecs)->toContain('Мощность');
        expect($rawSpecs)->not->toContain('\\u');
        expect(
            DB::table('product_categories')
                ->where('product_id', $product->id)
                ->where('category_id', $stagingCategoryId)
                ->exists()
        )->toBeTrue();

        expect(ProductImportMedia::query()->count())->toBe(1);
        expect(ProductImportMedia::query()->first()?->source_url)->toBe($imageUrl);
        Queue::assertPushed(DownloadProductImportMediaJob::class);
    } finally {
        @unlink($bucketsFile);
        dropMetalmasterParserSchemas();
    }
});

it('keeps description html unchanged and queues main media in async pipeline', function () {
    rebuildMetalmasterParserSchemas();

    $productUrl = 'https://metalmaster.ru/promyshlennye/z50100-dro/';
    $bucketsFile = storage_path('app/testing/metalmaster-buckets-'.Str::lower(Str::random(10)).'.json');

    file_put_contents($bucketsFile, json_encode([
        [
            'bucket' => 'promyshlennye',
            'category_url' => 'https://metalmaster.ru/promyshlennye/',
            'products_count' => 1,
            'product_urls' => [$productUrl],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

    try {
        Queue::fake();

        DB::table('categories')->insert([
            'name' => 'Staging',
            'slug' => 'staging',
            'parent_id' => -1,
            'order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $mainImageUrl = 'https://metalmaster.ru/files/originals/z50100-main.jpg';
        $descriptionImageUrl = 'https://metalmaster.ru/assets/images/z5100dro/22.jpg';

        Http::preventStrayRequests();
        Http::fake([
            $productUrl => Http::response(metalmasterProductHtml(
                title: 'Станок токарно-винторезный Metal Master Z 50100 DRO',
                price: 1049972,
                imageUrl: $mainImageUrl,
                descriptionImageUrl: $descriptionImageUrl,
            ), 200),
        ]);

        $this->artisan('parser:parse-products', [
            '--buckets-file' => $bucketsFile,
            '--sleep' => 0,
            '--write' => true,
            '--publish' => true,
            '--download-images' => true,
        ])
            ->expectsOutputToContain('Режим: write')
            ->assertSuccessful();

        $product = Product::query()->firstOrFail();
        $description = (string) $product->description;

        expect($description)->toContain($descriptionImageUrl);
        expect($description)->toContain('data-src=');

        expect(ProductImportMedia::query()->count())->toBe(1);
        expect(ProductImportMedia::query()->first()?->source_url)->toBe($mainImageUrl);
        Queue::assertPushed(DownloadProductImportMediaJob::class);
    } finally {
        @unlink($bucketsFile);
        dropMetalmasterParserSchemas();
    }
});

function metalmasterProductHtml(string $title, int $price, string $imageUrl, ?string $descriptionImageUrl = null): string
{
    $jsonLd = json_encode([
        '@context' => 'https://schema.org/',
        '@type' => 'Product',
        'name' => $title,
        'description' => 'Описание товара '.$title,
        'brand' => [
            '@type' => 'Brand',
            'name' => 'MetalMaster',
        ],
        'image' => [$imageUrl],
        'additionalProperty' => [
            [
                '@type' => 'PropertyValue',
                'name' => 'Артикул',
                'value' => 'Z50100-DRO',
            ],
            [
                '@type' => 'PropertyValue',
                'name' => 'Мощность',
                'value' => '5.5 кВт',
            ],
        ],
        'offers' => [
            '@type' => 'Offer',
            'priceCurrency' => 'RUB',
            'price' => $price,
            'availability' => 'https://schema.org/InStock',
            'inventoryLevel' => [
                'value' => 7,
            ],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $descriptionBlock = '';

    if (is_string($descriptionImageUrl) && trim($descriptionImageUrl) !== '') {
        $descriptionImagePath = (string) parse_url($descriptionImageUrl, PHP_URL_PATH);

        if ($descriptionImagePath === '') {
            $descriptionImagePath = $descriptionImageUrl;
        }

        $descriptionBlock = '<div class="d-none d-sm-block lx-hide wrapper__content-product" id="blp_3">'
            .'  <h3 class="wrapper__left-title">Описание станка '.$title.'</h3>'
            .'  <div class="product__body">'
            .'    <p>Описание станка '.$title.'</p>'
            .'    <p><img src="/design/metalmasternew/images/white.gif" data-src="'.$descriptionImagePath.'" alt="'.$title.'"></p>'
            .'  </div>'
            .'</div>';
    }

    return '<html><head><script type="application/ld+json">'
        .$jsonLd
        .'</script><title>'.$title.'</title>'
        .'<meta name="description" content="Описание '.$title.'">'
        .'</head><body>'
        .'<h1>'.$title.'</h1>'
        .$descriptionBlock
        .'</body></html>';
}

function rebuildMetalmasterParserSchemas(): void
{
    dropMetalmasterParserSchemas();

    Schema::create('import_runs', function (Blueprint $table): void {
        $table->id();
        $table->string('type')->default('products');
        $table->string('status')->default('pending');
        $table->json('columns')->nullable();
        $table->json('totals')->nullable();
        $table->string('source_filename')->nullable();
        $table->string('stored_path')->nullable();
        $table->unsignedBigInteger('supplier_id')->nullable();
        $table->unsignedBigInteger('supplier_import_source_id')->nullable();
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
        $table->longText('instructions')->nullable();
        $table->longText('video')->nullable();
        $table->json('specs')->nullable();
        $table->string('promo_info')->nullable();
        $table->string('image')->nullable();
        $table->string('thumb')->nullable();
        $table->text('gallery')->nullable();
        $table->string('meta_title')->nullable();
        $table->text('meta_description')->nullable();
        $table->timestamps();
    });

    Schema::create('categories', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('slug')->unique();
        $table->integer('parent_id')->default(-1);
        $table->integer('order')->default(0);
        $table->timestamps();
    });

    Schema::create('product_categories', function (Blueprint $table): void {
        $table->unsignedBigInteger('product_id');
        $table->unsignedBigInteger('category_id');
        $table->boolean('is_primary')->default(false);
        $table->primary(['product_id', 'category_id']);
    });

    Schema::create('product_supplier_references', function (Blueprint $table): void {
        $table->id();
        $table->string('supplier', 120);
        $table->unsignedBigInteger('supplier_id')->nullable();
        $table->string('external_id');
        $table->unsignedBigInteger('product_id');
        $table->unsignedBigInteger('source_category_id')->nullable();
        $table->unsignedBigInteger('first_seen_run_id')->nullable();
        $table->unsignedBigInteger('last_seen_run_id')->nullable();
        $table->timestamp('last_seen_at')->nullable();
        $table->timestamps();
        $table->unique(['supplier', 'external_id']);
        $table->unique(['supplier_id', 'external_id']);
        $table->index(['supplier', 'product_id']);
        $table->index(['supplier', 'last_seen_run_id']);
        $table->index(['supplier_id', 'product_id']);
        $table->index(['supplier_id', 'last_seen_run_id']);
    });

    Schema::create('product_import_media', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('run_id')->nullable();
        $table->unsignedBigInteger('product_id');
        $table->text('source_url');
        $table->string('source_url_hash', 64);
        $table->string('source_kind', 32)->default('image');
        $table->string('status', 32)->default('pending');
        $table->string('mime_type', 120)->nullable();
        $table->unsignedBigInteger('bytes')->nullable();
        $table->string('content_hash', 64)->nullable();
        $table->string('local_path')->nullable();
        $table->unsignedInteger('attempts')->default(0);
        $table->text('last_error')->nullable();
        $table->timestamp('processed_at')->nullable();
        $table->json('meta')->nullable();
        $table->timestamps();
    });

    Schema::create('import_media_issues', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('media_id');
        $table->unsignedBigInteger('run_id')->nullable();
        $table->unsignedBigInteger('product_id')->nullable();
        $table->string('code', 64);
        $table->text('message');
        $table->json('context')->nullable();
        $table->timestamps();
    });
}

function dropMetalmasterParserSchemas(): void
{
    Schema::dropIfExists('import_issues');
    Schema::dropIfExists('import_runs');
    Schema::dropIfExists('import_media_issues');
    Schema::dropIfExists('product_import_media');
    Schema::dropIfExists('product_supplier_references');
    Schema::dropIfExists('product_categories');
    Schema::dropIfExists('categories');
    Schema::dropIfExists('products');
    DB::disconnect();
}
