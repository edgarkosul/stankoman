<?php

use App\Support\CatalogImport\Runs\ImportRunOrchestrator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

it('starts import run with normalized pending totals', function () {
    prepareImportRunOrchestratorTables();

    $run = app(ImportRunOrchestrator::class)->start(
        type: 'vactool_products',
        columns: ['write' => false],
        mode: 'dry-run',
        sourceFilename: 'https://vactool.ru/sitemap.xml',
    );

    expect($run->status)->toBe('pending');
    expect((int) data_get($run->totals, 'create'))->toBe(0);
    expect((bool) data_get($run->totals, '_meta.is_running'))->toBeTrue();
    expect((string) data_get($run->totals, '_meta.mode'))->toBe('dry-run');
});

it('completes import run with legacy status mapping', function () {
    prepareImportRunOrchestratorTables();

    $orchestrator = app(ImportRunOrchestrator::class);
    $run = $orchestrator->start(
        type: 'metalmaster_products',
        columns: ['write' => false],
        mode: 'dry-run',
    );

    $orchestrator->completeFromResult($run, [
        'processed' => 0,
        'no_urls' => true,
        'created' => 0,
        'updated' => 0,
        'skipped' => 0,
        'errors' => 0,
        'samples' => [],
    ], write: false);

    $run->refresh();

    expect($run->status)->toBe('dry_run');
    expect((bool) data_get($run->totals, '_meta.is_running'))->toBeFalse();
});

it('detects threshold overflow by count and percent', function () {
    $orchestrator = app(ImportRunOrchestrator::class);

    $countExceeded = $orchestrator->thresholdExceeded(
        progress: ['processed' => 10, 'errors' => 3],
        options: ['error_threshold_count' => 3],
    );

    expect($countExceeded)->not->toBeNull();
    expect($countExceeded['metric'])->toBe('count');
    expect((int) $countExceeded['threshold'])->toBe(3);

    $percentExceeded = $orchestrator->thresholdExceeded(
        progress: ['processed' => 20, 'errors' => 6],
        options: ['error_threshold_percent' => 25],
    );

    expect($percentExceeded)->not->toBeNull();
    expect($percentExceeded['metric'])->toBe('percent');
    expect((float) $percentExceeded['threshold'])->toBe(25.0);
    expect((float) $percentExceeded['actual'])->toBeGreaterThanOrEqual(30.0);
});

function prepareImportRunOrchestratorTables(): void
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
