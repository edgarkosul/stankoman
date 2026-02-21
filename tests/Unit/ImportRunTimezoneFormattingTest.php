<?php

use App\Filament\Pages\MetalmasterProductImport;
use App\Filament\Pages\VactoolProductImport;
use App\Models\ImportRun;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema as DatabaseSchema;
use Tests\TestCase;

uses(TestCase::class);

function ensureImportRunPageTablesExist(): void
{
    if (! DatabaseSchema::hasTable('import_runs')) {
        DatabaseSchema::create('import_runs', function (Blueprint $table): void {
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
    }

    if (! DatabaseSchema::hasTable('import_issues')) {
        DatabaseSchema::create('import_issues', function (Blueprint $table): void {
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
}

test('metalmaster product import formats finished at in moscow timezone', function () {
    ensureImportRunPageTablesExist();
    config(['app.timezone' => 'UTC']);

    $run = ImportRun::query()->create([
        'type' => 'metalmaster_products',
        'status' => 'applied',
        'columns' => [
            'bucket' => '',
            'buckets_file' => '',
        ],
        'totals' => [
            'create' => 0,
            'update' => 0,
            'same' => 0,
            'error' => 0,
            'scanned' => 0,
            '_meta' => [
                'mode' => 'write',
                'found_urls' => 0,
                'images_downloaded' => 0,
                'image_download_failed' => 0,
                'derivatives_queued' => 0,
                'no_urls' => false,
                'is_running' => false,
            ],
            '_samples' => [],
        ],
        'finished_at' => CarbonImmutable::parse('2026-01-10 12:00:00', 'UTC'),
    ]);

    $page = new MetalmasterProductImport;
    $page->lastRunId = $run->id;
    $page->refreshLastSavedRun();

    expect($page->lastSavedRun)->toBeArray();
    expect($page->lastSavedRun['finished_at'])->toBe('2026-01-10 15:00');
});

test('vactool product import formats finished at in moscow timezone', function () {
    ensureImportRunPageTablesExist();
    config(['app.timezone' => 'UTC']);

    $run = ImportRun::query()->create([
        'type' => 'vactool_products',
        'status' => 'applied',
        'columns' => [],
        'totals' => [
            'create' => 0,
            'update' => 0,
            'same' => 0,
            'error' => 0,
            'scanned' => 0,
            '_meta' => [
                'mode' => 'write',
                'found_urls' => 0,
                'images_downloaded' => 0,
                'image_download_failed' => 0,
                'derivatives_queued' => 0,
                'is_running' => false,
            ],
            '_samples' => [],
        ],
        'finished_at' => CarbonImmutable::parse('2026-01-10 12:00:00', 'UTC'),
    ]);

    $page = new VactoolProductImport;
    $page->lastRunId = $run->id;
    $page->refreshLastSavedRun();

    expect($page->lastSavedRun)->toBeArray();
    expect($page->lastSavedRun['finished_at'])->toBe('2026-01-10 15:00');
});
