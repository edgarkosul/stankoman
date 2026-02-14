<?php

use App\Jobs\RunSpecsMatchJob;
use App\Models\ImportRun;
use App\Support\Products\SpecsMatchService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

use function Pest\Laravel\mock;

uses(TestCase::class);

beforeEach(function () {
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
});

afterEach(function () {
    Schema::dropIfExists('import_issues');
    Schema::dropIfExists('import_runs');
    DB::disconnect();
});

it('updates import run status and totals after successful specs match run', function () {
    $run = ImportRun::query()->create([
        'type' => 'specs_match',
        'status' => 'pending',
        'totals' => [
            'create' => 0,
            'update' => 0,
            'same' => 0,
            'conflict' => 0,
            'error' => 0,
            'scanned' => 0,
            '_meta' => [
                'is_running' => true,
            ],
        ],
    ]);

    $options = [
        'target_category_id' => 15,
        'dry_run' => true,
        'only_empty_attributes' => true,
        'overwrite_existing' => false,
        'auto_create_options' => false,
        'detach_staging_after_success' => false,
    ];

    $service = mock(SpecsMatchService::class);
    $service->shouldReceive('run')
        ->once()
        ->withArgs(fn (ImportRun $argRun, array $ids, array $argOptions): bool => $argRun->is($run)
            && $ids === [11, 12]
            && $argOptions === $options
        )
        ->andReturn([
            'processed' => 2,
            'matched_pav' => 4,
            'matched_pao' => 3,
            'skipped' => 5,
            'issues' => 2,
            'fatal_error' => null,
            'fatal_code' => null,
        ]);

    $job = new RunSpecsMatchJob($run->id, [11, 12], $options);
    $job->handle($service);

    $run->refresh();

    expect($run->status)->toBe('dry_run')
        ->and((int) data_get($run->totals, 'create'))->toBe(4)
        ->and((int) data_get($run->totals, 'update'))->toBe(3)
        ->and((int) data_get($run->totals, 'same'))->toBe(5)
        ->and((int) data_get($run->totals, 'error'))->toBe(2)
        ->and((int) data_get($run->totals, 'scanned'))->toBe(2)
        ->and((bool) data_get($run->totals, '_meta.is_running'))->toBeFalse()
        ->and((int) data_get($run->totals, '_meta.attribute_links'))->toBe(0)
        ->and((int) data_get($run->totals, '_meta.pav_matched'))->toBe(4)
        ->and((int) data_get($run->totals, '_meta.pao_matched'))->toBe(3)
        ->and((int) data_get($run->totals, '_meta.skipped'))->toBe(5);
});

it('marks run as failed and stores issue when service throws exception', function () {
    $run = ImportRun::query()->create([
        'type' => 'specs_match',
        'status' => 'pending',
        'totals' => [
            '_meta' => [
                'is_running' => true,
            ],
        ],
    ]);

    $service = mock(SpecsMatchService::class);
    $service->shouldReceive('run')
        ->once()
        ->andThrow(new RuntimeException('Specs match failed hard'));

    $job = new RunSpecsMatchJob($run->id, [99], [
        'target_category_id' => 77,
        'dry_run' => false,
    ]);

    $job->handle($service);

    $run->refresh();

    expect($run->status)->toBe('failed')
        ->and((bool) data_get($run->totals, '_meta.is_running'))->toBeFalse();

    $issue = $run->issues()->first();

    expect($issue)->not->toBeNull()
        ->and($issue->code)->toBe('job_exception')
        ->and($issue->severity)->toBe('error')
        ->and($issue->message)->toContain('Specs match failed hard');
});
