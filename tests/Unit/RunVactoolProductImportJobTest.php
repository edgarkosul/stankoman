<?php

use App\Jobs\RunVactoolProductImportJob;
use App\Models\ImportRun;
use App\Support\Vactool\VactoolProductImportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

it('updates import run totals and status while handling queued vactool import job', function () {
    prepareVactoolJobImportTables();

    $sitemap = 'https://vactool.ru/sitemap.xml';
    $productUrl = 'https://vactool.ru/catalog/product-industrial-cleaner-5000';

    Http::fake([
        $sitemap => Http::response(vactoolJobSitemapUrlsetXml([$productUrl]), 200),
        $productUrl => Http::response(
            vactoolJobProductHtml('Промышленный пылесос 5000', 34500),
            200
        ),
    ]);

    $options = [
        'sitemap' => $sitemap,
        'match' => '/catalog/product-',
        'limit' => 0,
        'delay_ms' => 0,
        'write' => false,
        'publish' => false,
        'download_images' => true,
        'skip_existing' => false,
        'show_samples' => 3,
    ];

    $run = ImportRun::query()->create([
        'type' => 'vactool_products',
        'status' => 'pending',
        'columns' => $options,
        'totals' => [
            'create' => 0,
            'update' => 0,
            'same' => 0,
            'conflict' => 0,
            'error' => 0,
            'scanned' => 0,
            '_meta' => [
                'mode' => 'dry-run',
                'is_running' => true,
            ],
        ],
        'source_filename' => $sitemap,
        'started_at' => now(),
    ]);

    $job = new RunVactoolProductImportJob($run->id, $options, false);
    $job->handle(app(VactoolProductImportService::class));

    $run->refresh();

    expect($run->status)->toBe('dry_run');
    expect((int) data_get($run->totals, 'scanned'))->toBe(1);
    expect((int) data_get($run->totals, '_meta.found_urls'))->toBe(1);
    expect((bool) data_get($run->totals, '_meta.is_running'))->toBeFalse();
});

it('marks run as failed from queue failed callback for vactool import job', function () {
    prepareVactoolJobImportTables();

    $options = [
        'sitemap' => 'https://vactool.ru/sitemap.xml',
        'match' => '/catalog/product-',
        'write' => true,
    ];

    $run = ImportRun::query()->create([
        'type' => 'vactool_products',
        'status' => 'pending',
        'columns' => $options,
        'totals' => [
            '_meta' => [
                'mode' => 'write',
                'is_running' => true,
            ],
        ],
        'started_at' => now(),
    ]);

    $job = new RunVactoolProductImportJob($run->id, $options, true);
    $job->failed(new RuntimeException('Queue timeout exceeded.'));

    $run->refresh();

    expect($job->timeout)->toBe(7200);
    expect($job->failOnTimeout)->toBeTrue();
    expect($run->status)->toBe('failed');
    expect($run->finished_at)->not->toBeNull();
    expect((bool) data_get($run->totals, '_meta.is_running'))->toBeFalse();
    expect($run->issues()->count())->toBe(1);
    expect($run->issues()->first()?->code)->toBe('job_failed');
    expect($run->issues()->first()?->message)->toContain('Queue timeout exceeded');
});

function prepareVactoolJobImportTables(): void
{
    Schema::dropIfExists('import_issues');
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

function vactoolJobSitemapUrlsetXml(array $urls): string
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

function vactoolJobProductHtml(string $title, int $price): string
{
    $jsonLd = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => $title,
        'description' => 'Описание товара',
        'brand' => ['name' => 'Vactool'],
        'image' => ['https://cdn.vactool.ru/images/industrial-cleaner-5000-main.jpg'],
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
