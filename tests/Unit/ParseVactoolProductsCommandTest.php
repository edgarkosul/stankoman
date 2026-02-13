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

it('runs parser command in dry-run mode', function () {
    Http::fake([
        'https://vactool.ru/sitemap.xml' => Http::response(sitemapIndexXml([
            'https://vactool.ru/catalog-sitemap.xml',
        ]), 200),
        'https://vactool.ru/catalog-sitemap.xml' => Http::response(sitemapUrlsetXml([
            'https://vactool.ru/catalog/product-industrial-cleaner-5000',
            'https://vactool.ru/news/something',
        ]), 200),
        'https://vactool.ru/catalog/product-industrial-cleaner-5000' => Http::response(
            productHtml('Промышленный пылесос 5000', 34500),
            200
        ),
    ]);

    $this->artisan('products:parse-vactool', [
        '--sitemap' => 'https://vactool.ru/sitemap.xml',
        '--limit' => 1,
        '--delay-ms' => 0,
        '--show-samples' => 1,
    ])
        ->expectsOutputToContain('Режим: dry-run')
        ->expectsOutputToContain('OK: https://vactool.ru/catalog/product-industrial-cleaner-5000')
        ->assertSuccessful();

    Http::assertSentCount(3);
});

it('generates local slug and downloads image to pics with queued derivatives', function () {
    rebuildVactoolParserSchemas();

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

        $title = 'Промышленный пылесос Локальный 5000';
        $donorSlug = 'industrial-cleaner-donor-5000';
        $imageUrl = 'https://vactool.ru/file/2ad2795067aa3a37904e51ab614a5012_4780_111_83';

        Http::fake([
            'https://vactool.ru/sitemap.xml' => Http::response(sitemapUrlsetXml([
                'https://vactool.ru/catalog/product-'.$donorSlug,
            ]), 200),
            'https://vactool.ru/catalog/product-'.$donorSlug => Http::response(
                productHtml($title, 43000, $imageUrl),
                200
            ),
            $imageUrl => Http::response('fake-image-binary', 200, [
                'Content-Type' => 'image/jpeg',
            ]),
        ]);

        $this->artisan('products:parse-vactool', [
            '--sitemap' => 'https://vactool.ru/sitemap.xml',
            '--delay-ms' => 0,
            '--write' => true,
            '--download-images' => true,
        ])
            ->expectsOutputToContain('Режим: write')
            ->assertSuccessful();

        $product = Product::query()->firstOrFail();

        expect($product->slug)->toBe(Str::slug($title));
        expect($product->slug)->not->toBe($donorSlug);
        expect(str_starts_with((string) $product->image, 'pics/'))->toBeTrue();
        expect(str_ends_with((string) $product->image, '.jpg'))->toBeTrue();
        expect($product->gallery)->toBe([$product->image]);
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
        dropVactoolParserSchemas();
    }
});

it('attaches staging category when existing product is updated by parser import', function () {
    rebuildVactoolParserSchemas();

    try {
        $stagingCategoryId = DB::table('categories')->insertGetId([
            'name' => 'Staging',
            'slug' => 'staging',
            'parent_id' => -1,
            'order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $title = 'Промышленный пылесос Обновляемый';
        $brand = 'Vactool';

        Product::query()->create([
            'name' => $title,
            'title' => $title,
            'slug' => Str::slug($title),
            'brand' => $brand,
            'price_amount' => 10000,
            'currency' => 'RUB',
            'in_stock' => true,
            'is_active' => false,
        ]);

        Http::fake([
            'https://vactool.ru/sitemap.xml' => Http::response(sitemapUrlsetXml([
                'https://vactool.ru/catalog/product-updatable',
            ]), 200),
            'https://vactool.ru/catalog/product-updatable' => Http::response(
                productHtml($title, 55000),
                200
            ),
        ]);

        $this->artisan('products:parse-vactool', [
            '--sitemap' => 'https://vactool.ru/sitemap.xml',
            '--delay-ms' => 0,
            '--write' => true,
        ])->assertSuccessful();

        $product = Product::query()->firstOrFail();

        expect($product->price_amount)->toBe(55000);
        expect(
            DB::table('product_categories')
                ->where('product_id', $product->id)
                ->where('category_id', $stagingCategoryId)
                ->exists()
        )->toBeTrue();
    } finally {
        dropVactoolParserSchemas();
    }
});

it('creates staging category automatically when it does not exist', function () {
    rebuildVactoolParserSchemas();

    try {
        $title = 'Промышленный пылесос Автокатегория';

        Http::fake([
            'https://vactool.ru/sitemap.xml' => Http::response(sitemapUrlsetXml([
                'https://vactool.ru/catalog/product-auto-staging',
            ]), 200),
            'https://vactool.ru/catalog/product-auto-staging' => Http::response(
                productHtml($title, 120000),
                200
            ),
        ]);

        $this->artisan('products:parse-vactool', [
            '--sitemap' => 'https://vactool.ru/sitemap.xml',
            '--delay-ms' => 0,
            '--write' => true,
        ])->assertSuccessful();

        $product = Product::query()->firstOrFail();
        $stagingCategoryId = DB::table('categories')
            ->where('slug', 'staging')
            ->value('id');

        expect($stagingCategoryId)->not->toBeNull();
        expect(
            DB::table('product_categories')
                ->where('product_id', $product->id)
                ->where('category_id', $stagingCategoryId)
                ->exists()
        )->toBeTrue();
    } finally {
        dropVactoolParserSchemas();
    }
});

function sitemapIndexXml(array $sitemaps): string
{
    $items = array_map(
        static fn (string $url): string => '<sitemap><loc>'.$url.'</loc></sitemap>',
        $sitemaps
    );

    return '<?xml version="1.0" encoding="UTF-8"?>'
        .'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
        .implode('', $items)
        .'</sitemapindex>';
}

function sitemapUrlsetXml(array $urls): string
{
    $items = array_map(
        static fn (string $url): string => '<url><loc>'.$url.'</loc></url>',
        $urls
    );

    return '<?xml version="1.0" encoding="UTF-8"?>'
        .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
        .implode('', $items)
        .'</urlset>';
}

function productHtml(string $title, int $price, ?string $imageUrl = null): string
{
    $imageUrl = $imageUrl ?? 'https://cdn.vactool.ru/images/industrial-cleaner-5000-main.jpg';

    $jsonLd = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => $title,
        'description' => 'Описание товара',
        'brand' => ['name' => 'Vactool'],
        'image' => [
            $imageUrl,
        ],
        'additionalProperty' => [
            ['name' => 'Мощность', 'value' => '2200 Вт'],
        ],
        'offers' => [
            'price' => (string) $price,
            'priceCurrency' => 'RUB',
            'availability' => 'https://schema.org/InStock',
            'inventoryLevel' => ['value' => 12],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return '<html><head><script type="application/ld+json">'
        .$jsonLd
        .'</script></head><body></body></html>';
}

function rebuildVactoolParserSchemas(): void
{
    dropVactoolParserSchemas();

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

function dropVactoolParserSchemas(): void
{
    Schema::dropIfExists('product_categories');
    Schema::dropIfExists('categories');
    Schema::dropIfExists('products');
    DB::disconnect();
}
