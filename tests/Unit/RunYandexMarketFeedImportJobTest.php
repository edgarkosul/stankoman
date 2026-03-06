<?php

use App\Jobs\RunYandexMarketFeedImportJob;
use App\Models\ImportRun;
use App\Support\CatalogImport\Runs\ImportRunOrchestrator;
use App\Support\CatalogImport\Yml\YandexMarketFeedImportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

it('updates import run totals and status while handling queued yandex market feed import job', function () {
    prepareYandexMarketFeedJobImportTables();

    $options = [
        'source' => 'https://example.test/yandex-market-feed.xml',
        'limit' => 0,
        'delay_ms' => 0,
        'write' => false,
        'publish' => false,
        'download_images' => true,
        'skip_existing' => false,
        'show_samples' => 3,
        'mode' => 'partial_import',
    ];

    $run = ImportRun::query()->create([
        'type' => 'yandex_market_feed_products',
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
        'source_filename' => $options['source'],
        'started_at' => now(),
    ]);

    $service = \Mockery::mock(YandexMarketFeedImportService::class);
    $service->shouldReceive('run')
        ->once()
        ->andReturnUsing(function (array $options, ?callable $output, ?callable $progress): array {
            if ($progress !== null) {
                $progress([
                    'found_urls' => 2,
                    'processed' => 2,
                    'errors' => 1,
                    'created' => 0,
                    'updated' => 0,
                    'skipped' => 0,
                    'images_downloaded' => 0,
                    'image_download_failed' => 0,
                    'derivatives_queued' => 0,
                    'no_urls' => false,
                ]);
            }

            return [
                'options' => $options,
                'write_mode' => false,
                'found_urls' => 2,
                'processed' => 2,
                'errors' => 1,
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'images_downloaded' => 0,
                'image_download_failed' => 0,
                'derivatives_queued' => 0,
                'samples' => [],
                'url_errors' => [
                    [
                        'url' => 'offer:A-2',
                        'message' => 'Offer is missing required field <name>.',
                    ],
                ],
                'fatal_error' => null,
                'no_urls' => false,
                'success' => true,
            ];
        });

    $job = new RunYandexMarketFeedImportJob($run->id, $options, false);
    $job->handle($service, app(ImportRunOrchestrator::class));

    $run->refresh();

    expect($run->status)->toBe('completed');
    expect((int) data_get($run->totals, 'scanned'))->toBe(2);
    expect((int) data_get($run->totals, '_meta.found_urls'))->toBe(2);
    expect((bool) data_get($run->totals, '_meta.is_running'))->toBeFalse();
    expect($run->issues()->where('code', 'parse_error')->exists())->toBeTrue();
});

it('marks run as failed from queue failed callback for yandex market feed import job', function () {
    prepareYandexMarketFeedJobImportTables();

    $options = [
        'source' => 'https://example.test/yandex-market-feed.xml',
        'write' => true,
    ];

    $run = ImportRun::query()->create([
        'type' => 'yandex_market_feed_products',
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

    $job = new RunYandexMarketFeedImportJob($run->id, $options, true);
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

function prepareYandexMarketFeedJobImportTables(): void
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
