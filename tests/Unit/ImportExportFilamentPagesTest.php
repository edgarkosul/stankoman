<?php

use App\Filament\Pages\CategoryFiltersImportExport;
use App\Filament\Pages\ImportExportHelp;
use App\Filament\Pages\MetalmasterProductImport;
use App\Filament\Pages\ProductImportExport;
use App\Filament\Pages\VactoolProductImport;
use App\Filament\Resources\ImportRuns\ImportRunResource;
use App\Jobs\RunMetalmasterProductImportJob;
use App\Jobs\RunVactoolProductImportJob;
use App\Models\ImportRun;
use App\Support\Products\CategoryFilterSchemaService;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema as DatabaseSchema;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

pest()->extend(TestCase::class);

test('product import export page metadata is configured', function () {
    expect(ProductImportExport::getNavigationGroup())->toBe('Импорт/Экспорт');
    expect(ProductImportExport::getNavigationLabel())->toBe('Импорт/Экспорт товаров');
    expect(ProductImportExport::getNavigationIcon())->toBe('heroicon-o-arrow-up-on-square-stack');

    $defaults = (new ReflectionClass(ProductImportExport::class))->getDefaultProperties();

    expect($defaults['view'])->toBe('filament.pages.product-import-export');
    expect($defaults['title'])->toBe('Импорт/Экспорт товаров в Excel');
});

test('category filters import export page metadata and route are configured', function () {
    expect(CategoryFiltersImportExport::getNavigationGroup())->toBe('Импорт/Экспорт');
    expect(CategoryFiltersImportExport::getNavigationLabel())->toBe('Фильтры (экспорт и импорт)');
    expect(CategoryFiltersImportExport::getNavigationIcon())->toBe('heroicon-o-funnel');

    $defaults = (new ReflectionClass(CategoryFiltersImportExport::class))->getDefaultProperties();

    expect($defaults['view'])->toBe('filament.pages.category-filters-import-export');
    expect($defaults['title'])->toBe('Фильтры (экспорт и импорт)');
    expect(Route::has('filament.admin.pages.category-filters-import-export'))->toBeTrue();
});

test('import export help page metadata and route are configured', function () {
    $defaults = (new ReflectionClass(ImportExportHelp::class))->getDefaultProperties();

    expect($defaults['view'])->toBe('filament.pages.import-export-help');
    expect($defaults['title'])->toBe('Инструкция по импорту/экспорту товаров');
    expect($defaults['slug'])->toBe('import-export-help');
    expect(Route::has('filament.admin.pages.import-export-help'))->toBeTrue();
});

test('vactool product import page metadata and route are configured', function () {
    expect(VactoolProductImport::getNavigationGroup())->toBe('Импорт/Экспорт');
    expect(VactoolProductImport::getNavigationLabel())->toBe('Импорт Vactool');
    expect(VactoolProductImport::getNavigationIcon())->toBe('heroicon-o-cloud-arrow-down');

    $defaults = (new ReflectionClass(VactoolProductImport::class))->getDefaultProperties();

    expect($defaults['view'])->toBe('filament.pages.vactool-product-import');
    expect($defaults['title'])->toBe('Импорт товаров из Vactool');
    expect(Route::has('filament.admin.pages.vactool-product-import'))->toBeTrue();
});

test('metalmaster product import page metadata and route are configured', function () {
    expect(MetalmasterProductImport::getNavigationGroup())->toBe('Импорт/Экспорт');
    expect(MetalmasterProductImport::getNavigationLabel())->toBe('Импорт Metalmaster');
    expect(MetalmasterProductImport::getNavigationIcon())->toBe('heroicon-o-cloud-arrow-down');

    $defaults = (new ReflectionClass(MetalmasterProductImport::class))->getDefaultProperties();

    expect($defaults['view'])->toBe('filament.pages.metalmaster-product-import');
    expect($defaults['title'])->toBe('Импорт товаров из Metalmaster');
    expect(Route::has('filament.admin.pages.metalmaster-product-import'))->toBeTrue();
});

test('product import export page has instruction header action', function () {
    $page = new ProductImportExport;

    $method = new ReflectionMethod(ProductImportExport::class, 'getHeaderActions');
    $method->setAccessible(true);

    $actions = $method->invoke($page);

    expect($actions)->toHaveCount(1);
    expect($actions[0]->getName())->toBe('instructions');
    expect($actions[0]->getLabel())->toBe('Инструкция');
    expect($actions[0]->getUrl())->toBe(ImportExportHelp::getUrl());
});

test('vactool product import page has expected header actions', function () {
    $page = new VactoolProductImport;

    $method = new ReflectionMethod(VactoolProductImport::class, 'getHeaderActions');
    $method->setAccessible(true);

    $actions = $method->invoke($page);

    expect($actions)->toHaveCount(2);
    expect($actions[0]->getName())->toBe('instructions');
    expect($actions[0]->getUrl())->toBe(ImportExportHelp::getUrl());
    expect($actions[1]->getName())->toBe('history');
    expect($actions[1]->getUrl())->toBe(ImportRunResource::getUrl());
});

test('metalmaster product import page has expected header actions', function () {
    $page = new MetalmasterProductImport;

    $method = new ReflectionMethod(MetalmasterProductImport::class, 'getHeaderActions');
    $method->setAccessible(true);

    $actions = $method->invoke($page);

    expect($actions)->toHaveCount(2);
    expect($actions[0]->getName())->toBe('instructions');
    expect($actions[0]->getUrl())->toBe(ImportExportHelp::getUrl());
    expect($actions[1]->getName())->toBe('history');
    expect($actions[1]->getUrl())->toBe(ImportRunResource::getUrl());
});

test('import runs resource metadata is configured', function () {
    expect(ImportRunResource::getNavigationGroup())->toBe('Импорт/Экспорт');
    expect(ImportRunResource::getNavigationLabel())->toBe('История импортов');
});

test('download routes for import export tools are registered with auth middleware', function () {
    expect(Route::has('admin.tools.download-export'))->toBeTrue();
    expect(Route::has('admin.tools.download-import'))->toBeTrue();

    $exportRoute = Route::getRoutes()->getByName('admin.tools.download-export');
    $importRoute = Route::getRoutes()->getByName('admin.tools.download-import');

    expect($exportRoute)->not->toBeNull();
    expect($importRoute)->not->toBeNull();
    expect($exportRoute->uri())->toBe('admin/tools/download-export/{token}/{name}');
    expect($importRoute->uri())->toBe('admin/tools/download-import/{run}');
    expect($exportRoute->gatherMiddleware())->toContain('auth');
    expect($importRoute->gatherMiddleware())->toContain('auth');
});

test('product import export form has inline hint icon tooltips for excel fields', function () {
    $page = new ProductImportExport;
    $schema = $page->form(Schema::make($page));

    $exportColumnsField = $schema->getComponent(
        fn ($component) => $component instanceof Select && $component->getName() === 'export_columns',
    );
    $importFileField = $schema->getComponent(
        fn ($component) => $component instanceof FileUpload && $component->getName() === 'import_file',
    );

    expect($exportColumnsField)->not->toBeNull();
    expect($importFileField)->not->toBeNull();

    expect($exportColumnsField->getHintIcon())->toBe(Heroicon::InformationCircle);
    expect($exportColumnsField->getHintIconTooltip())->toContain('Обязательные служебные колонки');

    expect($importFileField->getHintIcon())->toBe(Heroicon::InformationCircle);
    expect($importFileField->getHintIconTooltip())->toContain('Поддерживается только формат XLSX');
});

test('category filters import export form has expected fields and hint icons', function () {
    if (! DatabaseSchema::hasTable('categories')) {
        DatabaseSchema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->integer('parent_id')->default(-1);
        });
    }

    if (! DatabaseSchema::hasColumn('categories', 'parent_id')) {
        DatabaseSchema::table('categories', function (Blueprint $table): void {
            $table->integer('parent_id')->default(-1);
        });
    }

    $page = new CategoryFiltersImportExport;
    $schema = $page->form(Schema::make($page));

    $categoryField = $schema->getComponent(
        fn ($component) => $component instanceof Select && $component->getName() === 'category_id',
    );
    $importFileField = $schema->getComponent(
        fn ($component) => $component instanceof FileUpload && $component->getName() === 'import_file',
    );

    expect($categoryField)->not->toBeNull();
    expect($importFileField)->not->toBeNull();

    expect($categoryField->getHintIcon())->toBe(Heroicon::InformationCircle);
    expect($categoryField->getHintIconTooltip())->toContain('только для листовой категории');

    expect($importFileField->getHintIcon())->toBe(Heroicon::InformationCircle);
    expect($importFileField->getHintIconTooltip())->toContain('Сначала запустите dry-run');
});

test('category filters import export resolves stored path from nested file upload state', function () {
    $page = new CategoryFiltersImportExport;

    $method = new ReflectionMethod(CategoryFiltersImportExport::class, 'resolveStoredImportPath');
    $method->setAccessible(true);

    $resolved = $method->invoke(
        $page,
        [
            [
                'name' => 'template.xlsx',
                'size' => 12345,
                'path' => 'imports/template.xlsx',
            ],
        ],
    );

    expect($resolved)->toBe('imports/template.xlsx');
});

test('category filters import export detects category id from template meta sheet', function () {
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle(CategoryFilterSchemaService::META_SHEET);

    $sheet->setCellValue('A1', 'key');
    $sheet->setCellValue('B1', 'value');
    $sheet->setCellValue('A2', 'template_type');
    $sheet->setCellValue('B2', CategoryFilterSchemaService::TEMPLATE_TYPE);
    $sheet->setCellValue('A3', 'category_id');
    $sheet->setCellValue('B3', '168');
    $sheet->setCellValue('A4', 'schema_hash');
    $sheet->setCellValue('B4', 'test-hash');

    $relativePath = 'imports/test-filter-template-meta-'.uniqid('', true).'.xlsx';
    $absolutePath = Storage::disk('local')->path($relativePath);
    $directory = dirname($absolutePath);

    if (! is_dir($directory)) {
        mkdir($directory, 0775, true);
    }

    (new Xlsx($spreadsheet))->save($absolutePath);
    $spreadsheet->disconnectWorksheets();

    $page = new CategoryFiltersImportExport;

    $method = new ReflectionMethod(CategoryFiltersImportExport::class, 'detectCategoryIdFromTemplatePath');
    $method->setAccessible(true);

    $detectedCategoryId = $method->invoke($page, $relativePath);

    Storage::disk('local')->delete($relativePath);

    expect($detectedCategoryId)->toBe(168);
});

test('vactool product import form has default fields and hint icons', function () {
    $page = new VactoolProductImport;
    $schema = $page->form(Schema::make($page));

    $limitField = $schema->getComponent(
        fn ($component) => $component instanceof TextInput && $component->getName() === 'limit',
    );
    $downloadImagesField = $schema->getComponent(
        fn ($component) => $component instanceof Toggle && $component->getName() === 'download_images',
    );
    $skipExistingField = $schema->getComponent(
        fn ($component) => $component instanceof Toggle && $component->getName() === 'skip_existing',
    );
    $sitemapField = $schema->getComponent(
        fn ($component) => $component instanceof TextInput && $component->getName() === 'sitemap',
    );

    expect($limitField)->not->toBeNull();
    expect($downloadImagesField)->not->toBeNull();
    expect($skipExistingField)->not->toBeNull();
    expect($sitemapField)->toBeNull();

    expect($limitField->isNumeric())->toBeTrue();
    expect($downloadImagesField->getHintIcon())->toBe(Heroicon::InformationCircle);
    expect($skipExistingField->getHintIcon())->toBe(Heroicon::InformationCircle);
});

test('metalmaster product import form has default fields and hint icons', function () {
    $page = new MetalmasterProductImport;
    $schema = $page->form(Schema::make($page));

    $bucketField = $schema->getComponent(
        fn ($component) => $component instanceof Select && $component->getName() === 'bucket',
    );
    $bucketsFileField = $schema->getComponent(
        fn ($component) => $component instanceof TextInput && $component->getName() === 'buckets_file',
    );
    $timeoutField = $schema->getComponent(
        fn ($component) => $component instanceof TextInput && $component->getName() === 'timeout',
    );
    $skipExistingField = $schema->getComponent(
        fn ($component) => $component instanceof Toggle && $component->getName() === 'skip_existing',
    );
    $downloadImagesField = $schema->getComponent(
        fn ($component) => $component instanceof Toggle && $component->getName() === 'download_images',
    );

    expect($bucketField)->not->toBeNull();
    expect($bucketsFileField)->toBeNull();
    expect($timeoutField)->not->toBeNull();
    expect($skipExistingField)->not->toBeNull();
    expect($downloadImagesField)->not->toBeNull();

    expect($bucketField->isSearchable())->toBeTrue();
    expect($bucketField->getHintIcon())->toBe(Heroicon::InformationCircle);
    expect($timeoutField->isNumeric())->toBeTrue();
    expect($downloadImagesField->getHintIcon())->toBe(Heroicon::InformationCircle);
    expect($skipExistingField->getHintIcon())->toBe(Heroicon::InformationCircle);
});

test('metalmaster product import page can regenerate categories via artisan command', function () {
    Artisan::shouldReceive('call')
        ->once()
        ->with('parser:sitemap-buckets', ['--no-interaction' => true])
        ->andReturn(0);

    Artisan::shouldReceive('output')
        ->once()
        ->andReturn('Buckets saved: 12');

    $page = new MetalmasterProductImport;
    $page->data = [
        'bucket' => 'promyshlennye',
    ];
    $page->regenerateBuckets();

    expect($page->data)->toBeArray();
    expect($page->data['bucket'])->toBe('');
});

test('vactool product import page dispatches queued dry-run job', function () {
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

    Queue::fake();

    $page = new VactoolProductImport;
    $page->mount();
    $page->doDryRun();

    Queue::assertPushed(RunVactoolProductImportJob::class, function (RunVactoolProductImportJob $job) use ($page): bool {
        return $job->runId === $page->lastRunId
            && $job->write === false;
    });

    $run = ImportRun::query()->find($page->lastRunId);

    expect($run)->not->toBeNull();
    expect($run?->type)->toBe('vactool_products');
    expect($run?->status)->toBe('pending');
    expect(data_get($run?->totals, '_meta.is_running'))->toBeTrue();
});

test('metalmaster product import page dispatches queued dry-run job', function () {
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

    Queue::fake();

    $page = new MetalmasterProductImport;
    $page->mount();
    $page->doDryRun();

    Queue::assertPushed(RunMetalmasterProductImportJob::class, function (RunMetalmasterProductImportJob $job) use ($page): bool {
        return $job->runId === $page->lastRunId
            && $job->write === false;
    });

    $run = ImportRun::query()->find($page->lastRunId);

    expect($run)->not->toBeNull();
    expect($run?->type)->toBe('metalmaster_products');
    expect($run?->status)->toBe('pending');
    expect(data_get($run?->totals, '_meta.is_running'))->toBeTrue();
    expect(data_get($run?->columns, 'buckets_file'))->toBe(storage_path('app/parser/metalmaster-buckets.json'));
    expect(data_get($run?->columns, 'download_images'))->toBeTrue();
});
