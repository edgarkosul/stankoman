<?php

use App\Jobs\RunMetalmasterProductImportJob;
use App\Jobs\RunVactoolProductImportJob;
use App\Jobs\RunYandexMarketFeedImportJob;
use App\Models\ImportRun;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

it('dispatches queued vactool run from unified catalog import command', function () {
    prepareCatalogImportCommandTables();
    Queue::fake();

    $this->artisan('catalog:import-products', [
        'supplier' => 'vactool',
        '--queue' => true,
        '--write' => true,
        '--source' => 'https://vactool.ru/sitemap.xml',
        '--mode' => 'full_sync_authoritative',
        '--skip-existing' => true,
    ])->assertSuccessful();

    $run = ImportRun::query()->latest('id')->first();

    expect($run)->not->toBeNull();
    expect($run?->type)->toBe('vactool_products');
    expect(data_get($run?->columns, 'mode'))->toBe('full_sync_authoritative');
    expect(data_get($run?->columns, 'skip_existing'))->toBeTrue();
    expect(data_get($run?->columns, 'sitemap'))->toBe('https://vactool.ru/sitemap.xml');

    Queue::assertPushed(RunVactoolProductImportJob::class, function (RunVactoolProductImportJob $job) use ($run): bool {
        return $job->runId === $run?->id
            && $job->write === true
            && (string) ($job->options['mode'] ?? '') === 'full_sync_authoritative'
            && $job->afterCommit === true;
    });
});

it('dispatches queued metalmaster run from unified catalog import command', function () {
    prepareCatalogImportCommandTables();
    Queue::fake();

    $source = storage_path('app/parser/metalmaster-buckets.json');

    $this->artisan('catalog:import-products', [
        'supplier' => 'metalmaster',
        '--queue' => true,
        '--source' => $source,
        '--bucket' => 'promyshlennye',
        '--timeout' => 40,
        '--download-images' => true,
    ])->assertSuccessful();

    $run = ImportRun::query()->latest('id')->first();

    expect($run)->not->toBeNull();
    expect($run?->type)->toBe('metalmaster_products');
    expect(data_get($run?->columns, 'buckets_file'))->toBe($source);
    expect(data_get($run?->columns, 'bucket'))->toBe('promyshlennye');
    expect(data_get($run?->columns, 'timeout'))->toBe(40);

    Queue::assertPushed(RunMetalmasterProductImportJob::class, function (RunMetalmasterProductImportJob $job) use ($run): bool {
        return $job->runId === $run?->id
            && $job->write === false
            && $job->afterCommit === true;
    });
});

it('dispatches queued yandex market feed run from unified catalog import command', function () {
    prepareCatalogImportCommandTables();
    Queue::fake();

    $source = 'https://example.test/yandex-market-feed.xml';

    $this->artisan('catalog:import-products', [
        'supplier' => 'yandex_market_feed',
        '--queue' => true,
        '--source' => $source,
        '--timeout' => 35,
        '--mode' => 'partial_import',
        '--download-images' => true,
    ])->assertSuccessful();

    $run = ImportRun::query()->latest('id')->first();

    expect($run)->not->toBeNull();
    expect($run?->type)->toBe('yandex_market_feed_products');
    expect(data_get($run?->columns, 'source'))->toBe($source);
    expect(data_get($run?->columns, 'timeout'))->toBe(35);

    Queue::assertPushed(RunYandexMarketFeedImportJob::class, function (RunYandexMarketFeedImportJob $job) use ($run): bool {
        return $job->runId === $run?->id
            && $job->write === false
            && (string) ($job->options['source'] ?? '') === 'https://example.test/yandex-market-feed.xml'
            && $job->afterCommit === true;
    });
});

function prepareCatalogImportCommandTables(): void
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
