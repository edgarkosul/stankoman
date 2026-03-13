<?php

use App\Filament\Pages\YandexMarketFeedImport;
use App\Jobs\RunYandexMarketFeedImportJob;
use App\Models\ImportFeedSource;
use App\Models\ImportRun;
use App\Models\Supplier;
use App\Support\CatalogImport\Yml\YandexMarketFeedImportService;
use App\Support\CatalogImport\Yml\YandexMarketFeedSourceHistoryService;
use Filament\Forms\Components\FileUpload;
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

test('yandex market feed import page metadata and route are configured', function () {
    expect(YandexMarketFeedImport::getNavigationGroup())->toBe('Экспорт/Импорт');
    expect(YandexMarketFeedImport::getNavigationLabel())->toBe('Импорт Yandex Feed');
    expect(YandexMarketFeedImport::getNavigationIcon())->toBe('heroicon-o-cloud-arrow-down');

    $defaults = (new ReflectionClass(YandexMarketFeedImport::class))->getDefaultProperties();

    expect($defaults['view'])->toBe('filament.pages.yandex-market-feed-import');
    expect($defaults['title'])->toBe('Импорт товаров из Yandex Market Feed');
    expect(Route::has('filament.admin.pages.yandex-market-feed-import'))->toBeTrue();
});

test('yandex market feed import form has source, category and run controls', function () {
    prepareYandexMarketFeedImportPageTables();

    $page = new YandexMarketFeedImport;
    $page->mount();

    $schema = $page->form(Schema::make($page));

    $supplierField = $schema->getComponent(
        fn ($component) => $component instanceof Select && $component->getName() === 'supplier_id',
    );
    $sourceModeField = $schema->getComponent(
        fn ($component) => $component instanceof Select && $component->getName() === 'source_mode',
    );
    $sourceUrlField = $schema->getComponent(
        fn ($component) => $component instanceof TextInput && $component->getName() === 'source_url',
    );
    $categoryField = $schema->getComponent(
        fn ($component) => $component instanceof Select && $component->getName() === 'category_id',
    );
    $syncScenarioField = $schema->getComponent(
        fn ($component) => $component instanceof Select && $component->getName() === 'sync_scenario',
    );
    $downloadImagesField = $schema->getComponent(
        fn ($component) => $component instanceof Toggle && $component->getName() === 'download_images',
    );

    expect($supplierField)->not->toBeNull();
    expect($sourceModeField)->not->toBeNull();
    expect($sourceUrlField)->not->toBeNull();
    expect($categoryField)->not->toBeNull();
    expect($syncScenarioField)->not->toBeNull();
    expect($downloadImagesField)->not->toBeNull();

    $page->data = array_merge($page->data ?? [], [
        'source_mode' => 'upload',
    ]);

    $uploadSchema = $page->form(Schema::make($page));
    $sourceUploadField = $uploadSchema->getComponent(
        fn ($component) => $component instanceof FileUpload && $component->getName() === 'source_upload',
    );

    expect($sourceUploadField)->not->toBeNull();
});

test('yandex market feed import page applies sync scenario to technical flags', function () {
    $page = new YandexMarketFeedImport;
    $page->mount();

    $page->data = array_merge($page->data ?? [], [
        'sync_scenario' => 'new_only',
    ]);

    $page->updatedDataSyncScenario('new_only');

    expect(data_get($page->data, 'create_missing'))->toBeTrue();
    expect(data_get($page->data, 'update_existing'))->toBeFalse();
    expect(data_get($page->data, 'skip_existing'))->toBeTrue();
});

test('yandex market feed import page builds processor options without auto finalize', function () {
    prepareYandexMarketFeedImportPageTables();

    $supplier = Supplier::query()->create([
        'name' => 'Yandex Feed Supplier Options',
        'is_active' => true,
    ]);

    $page = new YandexMarketFeedImport;
    $page->mount();
    $page->data = array_merge($page->data ?? [], [
        'supplier_id' => $supplier->id,
        'create_missing' => true,
        'update_existing' => true,
        'skip_existing' => false,
    ]);

    $method = new ReflectionMethod(YandexMarketFeedImport::class, 'buildOptions');
    $method->setAccessible(true);

    $options = $method->invoke($page, true, [
        'source' => 'https://example.test/yandex-market-feed.xml',
        'source_type' => YandexMarketFeedSourceHistoryService::SOURCE_TYPE_URL,
        'source_id' => null,
        'source_label' => 'https://example.test/yandex-market-feed.xml',
    ]);

    expect($options['supplier_id'])->toBe($supplier->id);
    expect($options['mode'])->toBe('partial_import');
    expect($options['finalize_missing'])->toBeFalse();
});

test('yandex market feed import page switches scenario to custom for manual technical flags', function () {
    $page = new YandexMarketFeedImport;
    $page->mount();

    $page->data = array_merge($page->data ?? [], [
        'create_missing' => false,
        'update_existing' => false,
    ]);

    $page->updatedDataUpdateExisting();

    expect(data_get($page->data, 'sync_scenario'))->toBe('custom');
});

test('yandex market feed import page keeps remembered uploaded source in file upload array state', function () {
    $page = new YandexMarketFeedImport;
    $page->mount();
    $page->data = array_merge($page->data ?? [], [
        'source_mode' => 'upload',
        'source_upload' => null,
    ]);

    $storedPath = 'imports/yandex_feed.xml';

    $record = new ImportFeedSource([
        'source_type' => YandexMarketFeedSourceHistoryService::SOURCE_TYPE_UPLOAD,
        'stored_path' => $storedPath,
        'original_filename' => 'yandex_feed.xml',
    ]);
    $record->id = 123;

    $resolvedSource = [
        'source' => '/tmp/yandex_feed.xml',
        'source_type' => YandexMarketFeedSourceHistoryService::SOURCE_TYPE_UPLOAD,
        'source_id' => null,
        'source_label' => 'yandex_feed.xml',
        'source_url' => null,
        'stored_path' => $storedPath,
        'original_filename' => 'yandex_feed.xml',
        'source_key' => 'upload|'.$storedPath,
    ];

    $method = new ReflectionMethod(YandexMarketFeedImport::class, 'applyRememberedSourceToState');
    $method->setAccessible(true);

    $result = $method->invoke($page, $resolvedSource, $record);

    expect(data_get($page->data, 'source_upload'))->toBe([$storedPath]);
    expect(data_get($result, 'source_id'))->toBe(123);
    expect(data_get($result, 'source_key'))->toBe('upload|'.$storedPath);
});

test('yandex market feed import page loads categories from feed source', function () {
    prepareYandexMarketFeedImportPageTables();

    $service = Mockery::mock(YandexMarketFeedImportService::class);
    $service->shouldReceive('listCategoryNodes')
        ->once()
        ->andReturn([
            ['id' => 11, 'name' => 'Компрессоры', 'parent_id' => null],
            ['id' => 22, 'name' => 'Пылесосы', 'parent_id' => 11],
            ['id' => 33, 'name' => 'Промышленные', 'parent_id' => 22],
        ]);

    app()->instance(YandexMarketFeedImportService::class, $service);

    $page = new YandexMarketFeedImport;
    $page->mount();
    $page->data = array_merge($page->data ?? [], [
        'source_mode' => 'url',
        'source_url' => 'https://example.test/yandex.xml',
        'category_id' => 11,
    ]);

    $page->loadFeedCategories();

    expect($page->parsedCategories)->toBe([
        11 => 'Компрессоры',
        22 => 'Пылесосы',
        33 => 'Промышленные',
    ]);
    expect($page->parsedCategoryTree)->toBe([
        11 => [
            'id' => 11,
            'name' => 'Компрессоры',
            'parent_id' => null,
            'depth' => 0,
            'is_leaf' => false,
            'tree_name' => 'Компрессоры',
        ],
        22 => [
            'id' => 22,
            'name' => 'Пылесосы',
            'parent_id' => 11,
            'depth' => 1,
            'is_leaf' => false,
            'tree_name' => '— Пылесосы',
        ],
        33 => [
            'id' => 33,
            'name' => 'Промышленные',
            'parent_id' => 22,
            'depth' => 2,
            'is_leaf' => true,
            'tree_name' => '— — Промышленные',
        ],
    ]);
    expect($page->leafCategoryIds)->toBe([33 => true]);
    expect(data_get($page->data, 'category_id'))->toBe(11);
    expect($page->categoriesLoadedSource)->toBe('https://example.test/yandex.xml');
    expect($page->categoriesLoadedAt)->not->toBeNull();
});

test('yandex market feed import page category select options contain full category tree', function () {
    prepareYandexMarketFeedImportPageTables();

    $service = Mockery::mock(YandexMarketFeedImportService::class);
    $service->shouldReceive('listCategoryNodes')
        ->once()
        ->andReturn([
            ['id' => 10, 'name' => 'Root', 'parent_id' => null],
            ['id' => 20, 'name' => 'Branch', 'parent_id' => 10],
            ['id' => 30, 'name' => 'Leaf A', 'parent_id' => 20],
            ['id' => 40, 'name' => 'Leaf B', 'parent_id' => 10],
        ]);

    app()->instance(YandexMarketFeedImportService::class, $service);

    $page = new YandexMarketFeedImport;
    $page->mount();
    $page->data = array_merge($page->data ?? [], [
        'source_mode' => 'url',
        'source_url' => 'https://example.test/yandex.xml',
    ]);

    $page->loadFeedCategories();

    $method = new ReflectionMethod(YandexMarketFeedImport::class, 'categoryOptions');
    $method->setAccessible(true);

    $options = $method->invoke($page, null, 100);

    expect($options)->toBe([
        '10' => '[10] Root',
        '20' => '— [20] Branch',
        '30' => '— — [30] Leaf A',
        '40' => '— [40] Leaf B',
    ]);
});

test('yandex market feed import page dispatches job with selected category filter', function () {
    prepareYandexMarketFeedImportPageTables();
    Queue::fake();

    $supplier = Supplier::query()->create([
        'name' => 'Yandex Feed Supplier Dispatch',
        'is_active' => true,
    ]);

    $service = Mockery::mock(YandexMarketFeedImportService::class);
    $service->shouldReceive('listCategoryNodes')
        ->once()
        ->andReturn([]);

    app()->instance(YandexMarketFeedImportService::class, $service);

    $page = new YandexMarketFeedImport;
    $page->mount();
    $page->data = array_merge($page->data ?? [], [
        'supplier_id' => $supplier->id,
        'source_mode' => 'url',
        'source_url' => 'https://example.test/yandex-market-feed.xml',
        'category_id' => 22,
    ]);

    $page->doDryRun();

    $run = ImportRun::query()->find($page->lastRunId);

    expect($run?->type)->toBe('yandex_market_feed_products');
    expect(data_get($run?->columns, 'supplier_id'))->toBe($supplier->id);
    expect(data_get($run?->columns, 'source'))->toBe('https://example.test/yandex-market-feed.xml');
    expect(data_get($run?->columns, 'category_id'))->toBe(22);
    expect(data_get($run?->columns, 'finalize_missing'))->toBeFalse();

    Queue::assertPushed(RunYandexMarketFeedImportJob::class, function (RunYandexMarketFeedImportJob $job) use ($page): bool {
        return $job->runId === $page->lastRunId
            && $job->write === false
            && $job->afterCommit === true;
    });
});

test('yandex market feed import page blocks write import when create and update are disabled', function () {
    prepareYandexMarketFeedImportPageTables();
    Queue::fake();

    $supplier = Supplier::query()->create([
        'name' => 'Yandex Feed Supplier Blocked',
        'is_active' => true,
    ]);

    $page = new YandexMarketFeedImport;
    $page->mount();
    $page->data = array_merge($page->data ?? [], [
        'supplier_id' => $supplier->id,
        'source_mode' => 'url',
        'source_url' => 'https://example.test/yandex-market-feed.xml',
        'create_missing' => false,
        'update_existing' => false,
    ]);

    $page->doImport();

    expect($page->lastRunId)->toBeNull();
    expect(ImportRun::query()->where('type', 'yandex_market_feed_products')->count())->toBe(0);
    Queue::assertNothingPushed();
});

function prepareYandexMarketFeedImportPageTables(): void
{
    DatabaseSchema::dropIfExists('import_feed_sources');
    DatabaseSchema::dropIfExists('import_issues');
    DatabaseSchema::dropIfExists('import_runs');
    DatabaseSchema::dropIfExists('suppliers');

    DatabaseSchema::create('suppliers', function (Blueprint $table): void {
        $table->id();
        $table->string('name')->unique();
        $table->string('slug')->unique();
        $table->boolean('is_active')->default(true);
        $table->timestamps();
    });

    DatabaseSchema::create('import_runs', function (Blueprint $table): void {
        $table->id();
        $table->string('type')->default('products');
        $table->string('status')->default('pending');
        $table->json('columns')->nullable();
        $table->json('totals')->nullable();
        $table->string('source_filename')->nullable();
        $table->string('stored_path')->nullable();
        $table->unsignedBigInteger('supplier_id')->nullable();
        $table->unsignedBigInteger('supplier_import_source_id')->nullable();
        $table->unsignedBigInteger('user_id')->nullable();
        $table->timestamp('started_at')->nullable();
        $table->timestamp('finished_at')->nullable();
        $table->timestamps();
    });

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

    DatabaseSchema::create('import_feed_sources', function (Blueprint $table): void {
        $table->id();
        $table->string('supplier', 120);
        $table->string('source_type', 16);
        $table->string('fingerprint', 64);
        $table->string('source_url', 2048)->nullable();
        $table->string('stored_path')->nullable();
        $table->string('original_filename')->nullable();
        $table->string('content_hash', 64)->nullable();
        $table->unsignedBigInteger('size_bytes')->nullable();
        $table->unsignedBigInteger('created_by')->nullable();
        $table->unsignedBigInteger('last_run_id')->nullable();
        $table->timestamp('last_used_at')->nullable();
        $table->timestamp('last_validated_at')->nullable();
        $table->json('meta')->nullable();
        $table->timestamps();
    });
}
