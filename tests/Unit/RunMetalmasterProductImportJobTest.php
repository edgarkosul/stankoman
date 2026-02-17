<?php

use App\Jobs\RunMetalmasterProductImportJob;
use App\Models\ImportRun;
use App\Support\Metalmaster\MetalmasterProductImportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

it('updates import run totals and status while handling queued metalmaster import job', function () {
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

    $productUrl = 'https://metalmaster.ru/promyshlennye/z50100-dro/';
    $bucketsFile = storage_path('app/testing/metalmaster-buckets-job-'.Str::lower(Str::random(10)).'.json');

    file_put_contents($bucketsFile, json_encode([
        [
            'bucket' => 'promyshlennye',
            'category_url' => 'https://metalmaster.ru/promyshlennye/',
            'products_count' => 1,
            'product_urls' => [$productUrl],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

    Http::fake([
        $productUrl => Http::response(
            metalmasterJobProductHtml('Станок токарный Metal Master Z 50100', 1049972),
            200
        ),
    ]);

    $options = [
        'buckets_file' => $bucketsFile,
        'bucket' => '',
        'limit' => 0,
        'timeout' => 25,
        'delay_ms' => 0,
        'write' => false,
        'publish' => false,
        'skip_existing' => false,
        'show_samples' => 3,
    ];

    $run = ImportRun::query()->create([
        'type' => 'metalmaster_products',
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
        'source_filename' => $bucketsFile,
        'started_at' => now(),
    ]);

    try {
        $job = new RunMetalmasterProductImportJob($run->id, $options, false);
        $job->handle(app(MetalmasterProductImportService::class));

        $run->refresh();

        expect($run->status)->toBe('dry_run');
        expect((int) data_get($run->totals, 'scanned'))->toBe(1);
        expect((int) data_get($run->totals, '_meta.found_urls'))->toBe(1);
        expect((bool) data_get($run->totals, '_meta.is_running'))->toBeFalse();
    } finally {
        @unlink($bucketsFile);
    }
});

function metalmasterJobProductHtml(string $title, int $price): string
{
    $jsonLd = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => $title,
        'description' => 'Описание товара',
        'brand' => [
            '@type' => 'Brand',
            'name' => 'MetalMaster',
        ],
        'image' => ['https://metalmaster.ru/files/originals/z50100-main.jpg'],
        'additionalProperty' => [
            ['name' => 'Мощность', 'value' => '5.5 кВт'],
        ],
        'offers' => [
            'price' => (string) $price,
            'priceCurrency' => 'RUB',
            'availability' => 'https://schema.org/InStock',
            'inventoryLevel' => ['value' => 7],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return '<html><head><script type="application/ld+json">'
        .$jsonLd
        .'</script></head><body><h1>'.$title.'</h1></body></html>';
}
