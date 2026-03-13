<?php

use App\Jobs\RunYandexMarketFeedDeactivationJob;
use App\Models\ImportRun;
use App\Support\CatalogImport\Runs\ImportRunOrchestrator;
use App\Support\CatalogImport\Yml\YandexMarketFeedDeactivationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

it('forwards dry-run mode to yandex market feed deactivation service options', function () {
    prepareYandexMarketFeedDeactivationJobTables();

    $options = [
        'source' => 'https://example.test/yandex-market-feed.xml',
        'site_category_id' => 42,
        'site_category_name' => 'Станки',
    ];

    $run = ImportRun::query()->create([
        'type' => 'yandex_market_feed_deactivation',
        'status' => 'pending',
        'columns' => $options,
        'totals' => [
            '_meta' => [
                'mode' => 'dry-run',
                'is_running' => true,
            ],
        ],
        'source_filename' => $options['source'],
        'started_at' => now(),
    ]);

    $service = Mockery::mock(YandexMarketFeedDeactivationService::class);
    $service->shouldReceive('run')
        ->once()
        ->withArgs(function (array $receivedOptions, ?callable $output, ?callable $progress) use ($run): bool {
            expect($receivedOptions['run_id'] ?? null)->toBe($run->id);
            expect($receivedOptions['write'] ?? null)->toBeFalse();

            return true;
        })
        ->andReturn([
            'found_urls' => 3,
            'processed' => 3,
            'errors' => 0,
            'candidates' => 2,
            'deactivated' => 0,
            'samples' => [
                ['external_id' => 'SKU-1'],
                ['external_id' => 'SKU-2'],
            ],
            'fatal_error' => null,
            'no_urls' => false,
        ]);

    $job = new RunYandexMarketFeedDeactivationJob($run->id, $options, false);
    $job->handle($service, app(ImportRunOrchestrator::class));

    $run->refresh();

    expect($run->status)->toBe('completed');
    expect((int) data_get($run->totals, 'update'))->toBe(0);
    expect((int) data_get($run->totals, 'same'))->toBe(2);
});

function prepareYandexMarketFeedDeactivationJobTables(): void
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
