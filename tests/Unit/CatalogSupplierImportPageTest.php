<?php

use App\Filament\Pages\CatalogSupplierImport;
use App\Jobs\RunMetalmasterProductImportJob;
use App\Jobs\RunVactoolProductImportJob;
use App\Models\ImportRun;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema as DatabaseSchema;
use Tests\TestCase;

uses(TestCase::class);

test('catalog supplier import page metadata and route are configured', function () {
    expect(CatalogSupplierImport::getNavigationGroup())->toBe('Экспорт/Импорт');
    expect(CatalogSupplierImport::getNavigationLabel())->toBe('Импорт поставщиков');
    expect(CatalogSupplierImport::getNavigationIcon())->toBe('heroicon-o-adjustments-horizontal');

    $defaults = (new ReflectionClass(CatalogSupplierImport::class))->getDefaultProperties();

    expect($defaults['view'])->toBe('filament.pages.catalog-supplier-import');
    expect($defaults['title'])->toBe('Единый импорт поставщиков');
    expect(Route::has('filament.admin.pages.catalog-supplier-import'))->toBeTrue();
});

test('catalog supplier import form has source and run controls', function () {
    $page = new CatalogSupplierImport;
    $schema = $page->form(Schema::make($page));

    $supplierField = $schema->getComponent(
        fn ($component) => $component instanceof Select && $component->getName() === 'supplier',
    );
    $profileField = $schema->getComponent(
        fn ($component) => $component instanceof Select && $component->getName() === 'profile',
    );
    $sourceField = $schema->getComponent(
        fn ($component) => $component instanceof TextInput && $component->getName() === 'source',
    );
    $modeField = $schema->getComponent(
        fn ($component) => $component instanceof Select && $component->getName() === 'mode',
    );
    $skipExistingField = $schema->getComponent(
        fn ($component) => $component instanceof Toggle && $component->getName() === 'skip_existing',
    );

    expect($supplierField)->not->toBeNull();
    expect($profileField)->not->toBeNull();
    expect($sourceField)->not->toBeNull();
    expect($modeField)->not->toBeNull();
    expect($skipExistingField)->not->toBeNull();
});

test('catalog supplier import page dispatches vactool and metalmaster jobs', function () {
    prepareCatalogSupplierImportPageTables();
    Queue::fake();

    $page = new CatalogSupplierImport;
    $page->mount();

    $page->data = array_merge($page->data ?? [], [
        'supplier' => 'vactool',
        'profile' => 'vactool_html',
        'source' => 'https://vactool.ru/sitemap.xml',
        'mode' => 'partial_import',
    ]);
    $page->doDryRun();

    $vactoolRun = ImportRun::query()->find($page->lastRunId);
    expect($vactoolRun?->type)->toBe('vactool_products');

    Queue::assertPushed(RunVactoolProductImportJob::class, function (RunVactoolProductImportJob $job) use ($page): bool {
        return $job->runId === $page->lastRunId;
    });

    $page->data = array_merge($page->data ?? [], [
        'supplier' => 'metalmaster',
        'profile' => 'metalmaster_html',
        'source' => storage_path('app/parser/metalmaster-buckets.json'),
        'bucket' => 'promyshlennye',
        'mode' => 'full_sync_authoritative',
    ]);
    $page->doImport();

    $metalmasterRun = ImportRun::query()->find($page->lastRunId);
    expect($metalmasterRun?->type)->toBe('metalmaster_products');
    expect(data_get($metalmasterRun?->columns, 'mode'))->toBe('full_sync_authoritative');

    Queue::assertPushed(RunMetalmasterProductImportJob::class, function (RunMetalmasterProductImportJob $job) use ($page): bool {
        return $job->runId === $page->lastRunId && $job->write === true;
    });
});

function prepareCatalogSupplierImportPageTables(): void
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
