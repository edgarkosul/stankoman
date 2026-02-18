<?php

use App\Jobs\GenerateImageDerivativesJob;
use App\Models\Product;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
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
        Storage::fake('public');
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
            $imageUrl => Http::response('fake-image-binary', 200, [
                'Content-Type' => 'image/jpeg',
            ]),
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
        expect(str_starts_with((string) $product->image, 'pics/'))->toBeTrue();
        expect(str_ends_with((string) $product->image, '.jpg'))->toBeTrue();
        expect($product->gallery)->toBe([$product->image]);
        expect($rawSpecs)->toBeString();
        expect($rawSpecs)->toContain('Мощность');
        expect($rawSpecs)->not->toContain('\\u');
        expect(
            DB::table('product_categories')
                ->where('product_id', $product->id)
                ->where('category_id', $stagingCategoryId)
                ->exists()
        )->toBeTrue();

        Storage::disk('public')->assertExists($product->image);

        Queue::assertPushed(GenerateImageDerivativesJob::class, function (GenerateImageDerivativesJob $job) use ($product): bool {
            return $job->sourcePath === $product->image;
        });
    } finally {
        @unlink($bucketsFile);
        dropMetalmasterParserSchemas();
    }
});

function metalmasterProductHtml(string $title, int $price, string $imageUrl): string
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

    return '<html><head><script type="application/ld+json">'
        .$jsonLd
        .'</script><title>'.$title.'</title>'
        .'<meta name="description" content="Описание '.$title.'">'
        .'</head><body>'
        .'<h1>'.$title.'</h1>'
        .'</body></html>';
}

function rebuildMetalmasterParserSchemas(): void
{
    dropMetalmasterParserSchemas();

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
}

function dropMetalmasterParserSchemas(): void
{
    Schema::dropIfExists('product_categories');
    Schema::dropIfExists('categories');
    Schema::dropIfExists('products');
    DB::disconnect();
}
