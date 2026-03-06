<?php

use App\Models\ImportRun;
use App\Models\ImportRunEvent;
use App\Support\CatalogImport\Runs\ImportRunEventsExportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    prepareImportRunEventsExportTables();
});

it('exports import run events to xlsx', function (): void {
    $run = ImportRun::query()->create([
        'type' => 'yandex_market_feed_products',
        'status' => 'completed',
        'columns' => [],
        'totals' => [],
        'started_at' => now(),
        'finished_at' => now(),
    ]);

    ImportRunEvent::query()->create([
        'run_id' => $run->id,
        'supplier' => 'yandex_market_feed',
        'stage' => 'processing',
        'result' => 'created',
        'source_ref' => 'offer:EXP-1',
        'external_id' => 'EXP-1',
        'product_id' => 42,
        'source_category_id' => 11,
        'code' => 'created',
        'message' => 'Product created.',
        'context' => ['foo' => 'bar'],
    ]);

    $service = app(ImportRunEventsExportService::class);
    $result = $service->exportToXlsx(
        ImportRunEvent::query()->where('run_id', $run->id),
        $run->id,
    );

    expect(is_file($result['path']))->toBeTrue();
    expect($result['downloadName'])->toContain('import-run-'.$run->id.'-events-');
    expect($result['downloadName'])->toEndWith('.xlsx');

    $spreadsheet = (new XlsxReader)->load($result['path']);
    $sheet = $spreadsheet->getActiveSheet();

    expect((string) $sheet->getCell('A1')->getValue())->toBe('ID');
    expect((string) $sheet->getCell('B2')->getValue())->toBe('Обработка');
    expect((string) $sheet->getCell('C2')->getValue())->toBe('Создан');
    expect((string) $sheet->getCell('F2')->getValue())->toBe('EXP-1');

    $spreadsheet->disconnectWorksheets();
    @unlink($result['path']);
});

function prepareImportRunEventsExportTables(): void
{
    Schema::dropIfExists('import_run_events');
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

    Schema::create('import_run_events', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('run_id');
        $table->string('supplier', 120)->nullable();
        $table->string('stage', 32);
        $table->string('result', 32);
        $table->string('source_ref', 2048)->nullable();
        $table->string('external_id')->nullable();
        $table->unsignedBigInteger('product_id')->nullable();
        $table->unsignedInteger('source_category_id')->nullable();
        $table->integer('row_index')->nullable();
        $table->string('code', 64)->nullable();
        $table->text('message')->nullable();
        $table->json('context')->nullable();
        $table->timestamps();
    });
}
