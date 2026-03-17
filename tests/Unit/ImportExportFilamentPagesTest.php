<?php

use App\Filament\Pages\CategoryFiltersImportExport;
use App\Filament\Pages\ImportExportHelp;
use App\Filament\Pages\ProductImportExport;
use App\Filament\Pages\YandexMarketFeedDeactivate;
use App\Filament\Pages\YandexMarketFeedImport;
use App\Filament\Resources\ImportRuns\ImportRunResource;
use App\Support\Filament\HelpCenter;
use App\Support\Products\CategoryFilterSchemaService;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema as DatabaseSchema;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

pest()->extend(TestCase::class);

test('product import export page metadata is configured', function () {
    expect(ProductImportExport::getNavigationGroup())->toBe('Экспорт/Импорт');
    expect(ProductImportExport::getNavigationLabel())->toBe('Excel Экспорт/Импорт');
    expect(ProductImportExport::getNavigationIcon())->toBe('heroicon-o-arrow-up-on-square-stack');

    $defaults = (new ReflectionClass(ProductImportExport::class))->getDefaultProperties();

    expect($defaults['view'])->toBe('filament.pages.product-import-export');
    expect($defaults['title'])->toBe('Экспорт/Иморт товаров в Excel');
});

test('category filters import export page metadata and route are configured', function () {
    expect(CategoryFiltersImportExport::getNavigationGroup())->toBe('Экспорт/Импорт');
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

test('yandex market feed import page metadata and route are configured', function () {
    expect(YandexMarketFeedImport::getNavigationGroup())->toBe('Экспорт/Импорт');
    expect(YandexMarketFeedImport::getNavigationLabel())->toBe('Импорт Yandex Feed');
    expect(YandexMarketFeedImport::getNavigationIcon())->toBe('heroicon-o-cloud-arrow-down');

    $defaults = (new ReflectionClass(YandexMarketFeedImport::class))->getDefaultProperties();

    expect($defaults['view'])->toBe('filament.pages.yandex-market-feed-import');
    expect($defaults['title'])->toBe('Импорт товаров из Yandex Market Feed');
    expect(Route::has('filament.admin.pages.yandex-market-feed-import'))->toBeTrue();
});

test('yandex market feed deactivation page metadata and route are configured', function () {
    expect(YandexMarketFeedDeactivate::getNavigationGroup())->toBe('Экспорт/Импорт');
    expect(YandexMarketFeedDeactivate::getNavigationLabel())->toBe('Деактивация Yandex Feed');
    expect(YandexMarketFeedDeactivate::getNavigationIcon())->toBe('heroicon-o-power');

    $defaults = (new ReflectionClass(YandexMarketFeedDeactivate::class))->getDefaultProperties();

    expect($defaults['view'])->toBe('filament.pages.yandex-market-feed-deactivate');
    expect($defaults['title'])->toBe('Деактивация товаров по Yandex Market Feed');
    expect(Route::has('filament.admin.pages.yandex-market-feed-deactivate'))->toBeTrue();
});

test('product import export page is mapped to production help page', function () {
    expect(HelpCenter::urlForRouteName('filament.admin.pages.product-import-export'))
        ->toBe('https://help.stankoman.ru/import/excel-import/');
});

test('import runs resource metadata is configured', function () {
    expect(ImportRunResource::getNavigationGroup())->toBe('Экспорт/Импорт');
    expect(ImportRunResource::getNavigationLabel())->toBe('История импортов');
    expect(Route::has('filament.admin.resources.import-runs.view'))->toBeTrue();
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
