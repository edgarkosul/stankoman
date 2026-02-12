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
    Schema::dropIfExists('products');
    Schema::create('products', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
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
        $table->longText('specs')->nullable();
        $table->string('promo_info')->nullable();
        $table->string('image')->nullable();
        $table->string('thumb')->nullable();
        $table->text('gallery')->nullable();
        $table->string('meta_title')->nullable();
        $table->text('meta_description')->nullable();
        $table->timestamps();
    });

    try {
        Storage::fake('public');
        Queue::fake();

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

        Storage::disk('public')->assertExists($product->image);

        Queue::assertPushed(GenerateImageDerivativesJob::class, function (GenerateImageDerivativesJob $job) use ($product): bool {
            return $job->sourcePath === $product->image;
        });
    } finally {
        Schema::dropIfExists('products');
        DB::disconnect();
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
