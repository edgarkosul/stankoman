<?php

use App\Filament\Pages\ImportExportHelp;
use App\Filament\Pages\ProductImportExport;
use App\Filament\Pages\VactoolProductImport;
use App\Filament\Resources\ImportRuns\ImportRunResource;
use App\Jobs\RunVactoolProductImportJob;
use App\Models\ImportRun;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema as DatabaseSchema;
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

test('product import export form has inline hint icon tooltips for key fields', function () {
    if (! DatabaseSchema::hasTable('categories')) {
        DatabaseSchema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
    }

    $page = new ProductImportExport;
    $schema = $page->form(Schema::make($page));

    $categoryField = $schema->getComponent(
        fn ($component) => $component instanceof Select && $component->getName() === 'filter_category_ids',
    );
    $activeOnlyField = $schema->getComponent(
        fn ($component) => $component instanceof Toggle && $component->getName() === 'filter_only_active',
    );
    $inStockOnlyField = $schema->getComponent(
        fn ($component) => $component instanceof Toggle && $component->getName() === 'filter_only_stock',
    );
    $exportColumnsField = $schema->getComponent(
        fn ($component) => $component instanceof Select && $component->getName() === 'export_columns',
    );
    $importFileField = $schema->getComponent(
        fn ($component) => $component instanceof FileUpload && $component->getName() === 'import_file',
    );

    expect($categoryField)->not->toBeNull();
    expect($activeOnlyField)->not->toBeNull();
    expect($inStockOnlyField)->not->toBeNull();
    expect($exportColumnsField)->not->toBeNull();
    expect($importFileField)->not->toBeNull();

    expect($categoryField->getHintIcon())->toBe(Heroicon::InformationCircle);
    expect($categoryField->getHintIconTooltip())->toContain('Ограничивает экспорт');

    expect($activeOnlyField->getHintIcon())->toBe(Heroicon::InformationCircle);
    expect($activeOnlyField->getHintIconTooltip())->toContain('только товары с пометкой');

    expect($inStockOnlyField->getHintIcon())->toBe(Heroicon::InformationCircle);
    expect($inStockOnlyField->getHintIconTooltip())->toContain('пометкой В наличии');

    expect($exportColumnsField->getHintIcon())->toBe(Heroicon::InformationCircle);
    expect($exportColumnsField->getHintIconTooltip())->toContain('Обязательные служебные колонки');

    expect($importFileField->getHintIcon())->toBe(Heroicon::InformationCircle);
    expect($importFileField->getHintIconTooltip())->toContain('Поддерживается только формат XLSX');
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
