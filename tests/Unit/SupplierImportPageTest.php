<?php

use App\Filament\Pages\SupplierImport;
use App\Jobs\RunVactoolProductImportJob;
use App\Jobs\RunYandexMarketFeedDeactivationJob;
use App\Models\Category;
use App\Models\ImportRun;
use App\Models\Supplier;
use App\Models\SupplierImportSource;
use App\Support\CatalogImport\Yml\YandexMarketFeedImportService;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema as DatabaseSchema;
use Tests\TestCase;

uses(TestCase::class);

test('supplier import page metadata and route are configured', function () {
    expect(SupplierImport::getNavigationGroup())->toBe('Экспорт/Импорт');
    expect(SupplierImport::getNavigationLabel())->toBe('Импорт поставщиков');
    expect(SupplierImport::getNavigationIcon())->toBe('heroicon-o-adjustments-horizontal');

    $defaults = (new ReflectionClass(SupplierImport::class))->getDefaultProperties();

    expect($defaults['view'])->toBe('filament.pages.supplier-import');
    expect($defaults['title'])->toBe('Единый импорт поставщиков');
    expect(Route::has('filament.admin.pages.supplier-import'))->toBeTrue();
});

test('supplier import form has supplier, source and driver controls', function () {
    prepareSupplierImportPageTables();

    $page = new SupplierImport;
    $page->mount();

    $schema = $page->form(Schema::make($page));

    $supplierField = $schema->getComponent(
        fn ($component) => $component instanceof Select && $component->getName() === 'supplier_id',
    );
    $sourceField = $schema->getComponent(
        fn ($component) => $component instanceof Select && $component->getName() === 'supplier_import_source_id',
    );
    $driverField = $schema->getComponent(
        fn ($component) => $component instanceof Select && $component->getName() === 'driver_key',
    );
    $nameField = $schema->getComponent(
        fn ($component) => $component instanceof TextInput && $component->getName() === 'source_name',
    );
    $sortField = $schema->getComponent(
        fn ($component) => $component instanceof TextInput && $component->getName() === 'source_sort',
    );
    $profileField = $schema->getComponent(
        fn ($component) => $component instanceof TextInput && $component->getName() === 'profile_key',
    );
    $modeField = $schema->getComponent(
        fn ($component) => $component instanceof Select && $component->getName() === 'runtime.mode',
    );
    $finalizeMissingField = $schema->getComponent(
        fn ($component) => $component instanceof Toggle && $component->getName() === 'runtime.finalize_missing',
    );

    expect($supplierField)->not->toBeNull();
    expect($sourceField)->not->toBeNull();
    expect($driverField)->not->toBeNull();
    expect($nameField)->not->toBeNull();
    expect($sortField)->toBeNull();
    expect($profileField)->toBeNull();
    expect($modeField)->toBeNull();
    expect($finalizeMissingField)->toBeNull();
});

test('supplier import page hides internal driver fields and uses bucket select for metalmaster', function () {
    prepareSupplierImportPageTables();

    $supplier = Supplier::query()->create([
        'name' => 'Metalmaster',
        'slug' => 'metalmaster',
        'is_active' => true,
    ]);

    $page = new SupplierImport;
    $page->mount();
    $page->data['supplier_id'] = $supplier->id;

    $vactoolSchema = $page->form(Schema::make($page));
    $vactoolMatchField = $vactoolSchema->getComponent(
        fn ($component) => $component instanceof TextInput && $component->getName() === 'source_settings.match',
    );

    expect($vactoolMatchField)->toBeNull();

    $page->data['driver_key'] = 'metalmaster_html';

    $metalmasterSchema = $page->form(Schema::make($page));
    $bucketsFileField = $metalmasterSchema->getComponent(
        fn ($component) => $component instanceof TextInput && $component->getName() === 'source_settings.buckets_file',
    );
    $bucketField = $metalmasterSchema->getComponent(
        fn ($component) => $component instanceof Select && $component->getName() === 'runtime.scope',
    );

    expect($bucketsFileField)->toBeNull();
    expect($bucketField)->not->toBeNull();
});

test('supplier import page loads yandex feed categories and exposes tree select options', function () {
    prepareSupplierImportPageTables();

    $service = Mockery::mock(YandexMarketFeedImportService::class);
    $service->shouldReceive('listCategoryNodes')
        ->once()
        ->andReturn([
            ['id' => 11, 'name' => 'Компрессоры', 'parent_id' => null],
            ['id' => 22, 'name' => 'Пылесосы', 'parent_id' => 11],
            ['id' => 33, 'name' => 'Промышленные', 'parent_id' => 22],
        ]);

    app()->instance(YandexMarketFeedImportService::class, $service);

    $page = new SupplierImport;
    $page->mount();
    $page->data['driver_key'] = 'yandex_market_feed';
    $page->data['source_settings'] = [
        'source_mode' => 'url',
        'source_url' => 'https://example.test/yandex.xml',
        'source_history_id' => null,
        'timeout' => 25,
        'delay_ms' => 0,
        'download_images' => true,
    ];

    $page->loadYandexFeedCategories();

    expect($page->yandexParsedCategories)->toBe([
        11 => 'Компрессоры',
        22 => 'Пылесосы',
        33 => 'Промышленные',
    ]);
    expect($page->yandexParsedCategoryTree)->toBe([
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
    expect($page->yandexLeafCategoryIds)->toBe([33 => true]);
    expect($page->yandexFeedCategoryOptions())->toBe([
        '11' => '[11] Компрессоры',
        '22' => '— [22] Пылесосы',
        '33' => '— — [33] Промышленные',
    ]);
    expect($page->yandexFeedCategoryOptionLabel(22))->toBe('— [22] Пылесосы');
});

test('supplier import page can create supplier and marks legacy yandex supplier in labels', function () {
    prepareSupplierImportPageTables();

    $page = new SupplierImport;
    $page->mount();
    $page->createSupplier([
        'name' => 'Acme Industrial',
    ]);

    $supplier = Supplier::query()->first();
    $legacySupplier = Supplier::query()->create([
        'name' => 'Yandex Market Feed',
        'slug' => 'yandex-market-feed',
        'is_active' => true,
    ]);

    expect($supplier)->not->toBeNull();
    expect($page->data['supplier_id'])->toEqual($supplier?->id);
    expect($page->data['supplier_import_source_id'])->toBeNull();
    expect(\Livewire\invade($page)->supplierOptionLabel($legacySupplier->id))->toBe('Yandex Market Feed · legacy');
});

test('supplier import page orders saved sources alphabetically', function () {
    prepareSupplierImportPageTables();

    $supplier = Supplier::query()->create([
        'name' => 'Ordered Supplier',
        'is_active' => true,
    ]);

    SupplierImportSource::query()->create([
        'supplier_id' => $supplier->id,
        'name' => 'Zulu Source',
        'driver_key' => 'vactool_html',
        'profile_key' => 'vactool_html',
        'settings' => [],
        'is_active' => true,
        'sort' => 1,
    ]);

    SupplierImportSource::query()->create([
        'supplier_id' => $supplier->id,
        'name' => 'Alpha Source',
        'driver_key' => 'vactool_html',
        'profile_key' => 'vactool_html',
        'settings' => [],
        'is_active' => true,
        'sort' => 99,
    ]);

    $page = new SupplierImport;
    $page->mount();
    $page->data['supplier_id'] = $supplier->id;

    $options = \Livewire\invade($page)->supplierImportSourceOptions();

    expect(array_values($options))->toBe([
        'Alpha Source · Vactool HTML',
        'Zulu Source · Vactool HTML',
    ]);
});

test('supplier import page deletes supplier only when it has no dependencies', function () {
    prepareSupplierImportPageTables();

    $blockedSupplier = Supplier::query()->create([
        'name' => 'Blocked Supplier',
        'is_active' => true,
    ]);
    $safeSupplier = Supplier::query()->create([
        'name' => 'Safe Supplier',
        'is_active' => true,
    ]);

    DB::table('product_supplier_references')->insert([
        'supplier_id' => $blockedSupplier->id,
        'supplier' => 'blocked_supplier',
        'external_id' => 'sku-1',
        'product_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('import_runs')->insert([
        'type' => 'vactool_products',
        'status' => 'completed',
        'supplier_id' => $blockedSupplier->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $page = new SupplierImport;
    $page->mount();
    $page->data['supplier_id'] = $blockedSupplier->id;
    $page->deleteSelectedSupplier();

    expect(Supplier::query()->whereKey($blockedSupplier->id)->exists())->toBeTrue();

    $page->data['supplier_id'] = $safeSupplier->id;
    $page->deleteSelectedSupplier();

    expect(Supplier::query()->whereKey($safeSupplier->id)->exists())->toBeFalse();
    expect(data_get($page->data, 'supplier_id'))->toBeNull();
});

test('supplier import page can sync metalmaster buckets and clears stale scope', function () {
    prepareSupplierImportPageTables();

    $supplier = Supplier::query()->create([
        'name' => 'Metalmaster',
        'slug' => 'metalmaster',
        'is_active' => true,
    ]);

    Artisan::shouldReceive('call')
        ->once()
        ->with('parser:sitemap-buckets', ['--no-interaction' => true])
        ->andReturn(0);

    Artisan::shouldReceive('output')
        ->once()
        ->andReturn('Buckets saved: 12');

    $page = new SupplierImport;
    $page->mount();
    $page->data['supplier_id'] = $supplier->id;
    $page->data['driver_key'] = 'metalmaster_html';
    data_set($page->data, 'runtime.scope', 'stale-bucket');

    $page->syncMetalmasterBuckets();

    expect(data_get($page->data, 'runtime.scope'))->toBe('');
});

test('supplier import page stores running progress in last run summary', function () {
    prepareSupplierImportPageTables();

    $run = ImportRun::query()->create([
        'type' => 'vactool_products',
        'status' => 'running',
        'columns' => [
            'source_label' => 'https://vactool.ru/sitemap.xml',
        ],
        'totals' => [
            'create' => 1,
            'update' => 2,
            'same' => 0,
            'error' => 0,
            'scanned' => 5,
            '_meta' => [
                'mode' => 'write',
                'is_running' => true,
                'found_urls' => 10,
                'no_urls' => false,
            ],
        ],
    ]);

    $page = new SupplierImport;
    $page->mount();
    $page->lastRunId = $run->id;

    $page->refreshLastSavedRun();

    expect($page->lastSavedRun)->toBeArray();
    expect($page->lastSavedRun['processed'])->toBe(5);
    expect($page->lastSavedRun['found_urls'])->toBe(10);
    expect($page->lastSavedRun['progress_percent'])->toBe(50);
    expect($page->lastSavedRun['is_running'])->toBeTrue();
    expect($page->lastSavedRun['no_urls'])->toBeFalse();
});

test('supplier import page restores selected source and run from session on mount', function () {
    prepareSupplierImportPageTables();

    $supplier = Supplier::query()->create([
        'name' => 'Resumable Supplier',
        'is_active' => true,
    ]);
    $source = SupplierImportSource::query()->create([
        'supplier_id' => $supplier->id,
        'name' => 'Основной HTML',
        'driver_key' => 'vactool_html',
        'profile_key' => 'vactool_html',
        'settings' => [
            'sitemap' => 'https://vactool.ru/sitemap.xml',
            'delay_ms' => 250,
            'download_images' => true,
        ],
        'is_active' => true,
        'sort' => 10,
    ]);
    $run = ImportRun::query()->create([
        'type' => 'vactool_products',
        'status' => 'running',
        'supplier_id' => $supplier->id,
        'supplier_import_source_id' => $source->id,
        'columns' => [
            'source_label' => 'https://vactool.ru/sitemap.xml',
        ],
        'totals' => [
            'create' => 0,
            'update' => 1,
            'same' => 0,
            'error' => 0,
            'scanned' => 4,
            '_meta' => [
                'mode' => 'write',
                'is_running' => true,
                'found_urls' => 10,
                'no_urls' => false,
            ],
        ],
    ]);

    session()->put('filament.supplier-import.page-state', [
        'supplier_id' => $supplier->id,
        'supplier_import_source_id' => $source->id,
        'last_run_id' => $run->id,
    ]);

    $page = new SupplierImport;
    $page->mount();

    expect((int) data_get($page->data, 'supplier_id'))->toBe($supplier->id);
    expect((int) data_get($page->data, 'supplier_import_source_id'))->toBe($source->id);
    expect($page->lastRunId)->toBe($run->id);
    expect($page->lastSavedRun['id'])->toBe($run->id);
    expect($page->lastSavedRun['is_running'])->toBeTrue();
    expect($page->lastSavedRun['processed'])->toBe(4);
});

test('supplier import page falls back to latest active run when reopened without session state', function () {
    prepareSupplierImportPageTables();

    session()->forget('filament.supplier-import.page-state');

    $supplier = Supplier::query()->create([
        'name' => 'Fallback Supplier',
        'is_active' => true,
    ]);
    $source = SupplierImportSource::query()->create([
        'supplier_id' => $supplier->id,
        'name' => 'Основной feed',
        'driver_key' => 'yandex_market_feed',
        'profile_key' => 'yandex_market_feed_yml',
        'settings' => [
            'source_mode' => 'url',
            'source_url' => 'https://example.test/feed.xml',
            'timeout' => 25,
            'delay_ms' => 0,
            'download_images' => true,
        ],
        'is_active' => true,
        'sort' => 10,
    ]);
    $run = ImportRun::query()->create([
        'type' => 'yandex_market_feed_products',
        'status' => 'running',
        'supplier_id' => $supplier->id,
        'supplier_import_source_id' => $source->id,
        'columns' => [
            'source_label' => 'https://example.test/feed.xml',
        ],
        'totals' => [
            'create' => 2,
            'update' => 0,
            'same' => 0,
            'error' => 0,
            'scanned' => 2,
            '_meta' => [
                'mode' => 'write',
                'is_running' => true,
                'found_urls' => 5,
                'no_urls' => false,
            ],
        ],
    ]);

    $page = new SupplierImport;
    $page->mount();

    expect((int) data_get($page->data, 'supplier_id'))->toBe($supplier->id);
    expect((int) data_get($page->data, 'supplier_import_source_id'))->toBe($source->id);
    expect($page->lastRunId)->toBe($run->id);
    expect($page->lastSavedRun['id'])->toBe($run->id);
    expect($page->lastSavedRun['found_urls'])->toBe(5);
});

test('supplier import page saves source and dispatches vactool dry run', function () {
    prepareSupplierImportPageTables();
    Queue::fake();

    $supplier = Supplier::query()->create([
        'name' => 'Vactool',
        'slug' => 'vactool',
        'is_active' => true,
    ]);

    $page = new SupplierImport;
    $page->mount();
    $page->data['supplier_id'] = $supplier->id;
    $page->data['supplier_import_source_id'] = null;
    $page->data['source_name'] = 'Основной HTML';
    $page->data['driver_key'] = 'vactool_html';
    $page->data['source_is_active'] = true;
    $page->data['source_settings'] = [
        'sitemap' => 'https://vactool.ru/sitemap.xml',
        'match' => '/catalog/product-',
        'delay_ms' => 250,
        'download_images' => true,
    ];

    $page->doDryRun();

    $source = SupplierImportSource::query()->first();
    $run = ImportRun::query()->find($page->lastRunId);

    expect($source)->not->toBeNull();
    expect($source?->supplier_id)->toBe($supplier->id);
    expect($source?->driver_key)->toBe('vactool_html');
    expect($source?->profile_key)->toBe('vactool_html');
    expect($run?->type)->toBe('vactool_products');
    expect($run?->supplier_id)->toBe($supplier->id);
    expect($run?->supplier_import_source_id)->toBe($source?->id);
    expect(data_get($run?->columns, 'supplier_import_source_name'))->toBe('Основной HTML');
    expect(data_get($run?->columns, 'sitemap'))->toBe('https://vactool.ru/sitemap.xml');

    Queue::assertPushed(RunVactoolProductImportJob::class, function (RunVactoolProductImportJob $job) use ($page): bool {
        return $job->runId === $page->lastRunId
            && $job->write === false
            && ($job->options['mode'] ?? null) === 'partial_import'
            && ($job->options['finalize_missing'] ?? null) === false
            && ($job->options['profile'] ?? null) === 'vactool_html';
    });
});

test('supplier import page filters drivers by supplier and defaults to compatible driver', function () {
    prepareSupplierImportPageTables();

    $genericSupplier = Supplier::query()->create([
        'name' => 'Acme Industrial',
        'slug' => 'acme-industrial',
        'is_active' => true,
    ]);
    $vactoolSupplier = Supplier::query()->create([
        'name' => 'Vactool',
        'slug' => 'vactool',
        'is_active' => true,
    ]);

    $page = new SupplierImport;
    $page->mount();

    expect(\Livewire\invade($page)->availableDriverOptions())->toBe([
        'yandex_market_feed' => 'Yandex Market Feed',
    ]);
    expect(data_get($page->data, 'driver_key'))->toBe('yandex_market_feed');

    $page->data['supplier_id'] = $genericSupplier->id;
    \Livewire\invade($page)->handleSupplierChanged();

    expect(\Livewire\invade($page)->availableDriverOptions())->toBe([
        'yandex_market_feed' => 'Yandex Market Feed',
    ]);
    expect(data_get($page->data, 'driver_key'))->toBe('yandex_market_feed');

    $page->data['supplier_id'] = $vactoolSupplier->id;
    \Livewire\invade($page)->handleSupplierChanged();

    expect(\Livewire\invade($page)->availableDriverOptions())->toBe([
        'vactool_html' => 'Vactool HTML',
        'yandex_market_feed' => 'Yandex Market Feed',
    ]);
    expect(data_get($page->data, 'driver_key'))->toBe('vactool_html');
});

test('supplier import page requires deactivation dry run before apply and dispatches yandex deactivation dry run', function () {
    prepareSupplierImportPageTables();
    Queue::fake();

    $supplier = Supplier::query()->create([
        'name' => 'Yandex Supplier',
        'is_active' => true,
    ]);
    $category = Category::query()->create([
        'name' => 'Компрессоры',
        'slug' => 'kompressory',
        'parent_id' => -1,
        'is_active' => true,
        'order' => 1,
    ]);

    $page = new SupplierImport;
    $page->mount();
    $page->data['supplier_id'] = $supplier->id;
    $page->data['driver_key'] = 'yandex_market_feed';
    $page->data['source_name'] = 'Основной feed';
    $page->data['source_settings'] = [
        'source_mode' => 'url',
        'source_url' => 'https://example.test/feed.xml',
        'source_history_id' => null,
        'timeout' => 25,
        'delay_ms' => 0,
        'download_images' => true,
    ];
    $page->data['deactivation'] = [
        'site_category_id' => $category->id,
        'show_samples' => 20,
    ];

    $page->doDeactivationApply();

    expect(ImportRun::query()->count())->toBe(0);
    Queue::assertNothingPushed();

    $service = Mockery::mock(YandexMarketFeedImportService::class);
    $service->shouldReceive('listCategoryNodes')
        ->once()
        ->andReturn([]);
    app()->instance(YandexMarketFeedImportService::class, $service);

    $page->doDeactivationDryRun();

    $run = ImportRun::query()->find($page->lastRunId);

    expect($run?->type)->toBe('yandex_market_feed_deactivation');
    expect($run?->supplier_id)->toBe($supplier->id);
    expect(data_get($run?->columns, 'site_category_id'))->toBe($category->id);
    expect(data_get($run?->columns, 'source'))->toBe('https://example.test/feed.xml');

    Queue::assertPushed(RunYandexMarketFeedDeactivationJob::class, function (RunYandexMarketFeedDeactivationJob $job) use ($page): bool {
        return $job->runId === $page->lastRunId
            && $job->write === false
            && ($job->options['site_category_id'] ?? null) !== null;
    });
});

test('supplier import page dispatches yandex import with selected feed category', function () {
    prepareSupplierImportPageTables();
    Queue::fake();

    $supplier = Supplier::query()->create([
        'name' => 'Yandex Import Supplier',
        'is_active' => true,
    ]);

    $service = Mockery::mock(YandexMarketFeedImportService::class);
    $service->shouldReceive('listCategoryNodes')
        ->twice()
        ->andReturn([
            ['id' => 11, 'name' => 'Компрессоры', 'parent_id' => null],
            ['id' => 22, 'name' => 'Пылесосы', 'parent_id' => 11],
        ]);

    app()->instance(YandexMarketFeedImportService::class, $service);

    $page = new SupplierImport;
    $page->mount();
    $page->data['supplier_id'] = $supplier->id;
    $page->data['source_name'] = 'Основной feed';
    $page->data['driver_key'] = 'yandex_market_feed';
    $page->data['source_is_active'] = true;
    $page->data['source_settings'] = [
        'source_mode' => 'url',
        'source_url' => 'https://example.test/feed.xml',
        'source_history_id' => null,
        'timeout' => 25,
        'delay_ms' => 0,
        'download_images' => true,
    ];

    $page->loadYandexFeedCategories();
    data_set($page->data, 'runtime.category_id', 22);

    $page->doDryRun();

    $run = ImportRun::query()->find($page->lastRunId);

    expect($run?->type)->toBe('yandex_market_feed_products');
    expect($run?->supplier_id)->toBe($supplier->id);
    expect(data_get($run?->columns, 'source'))->toBe('https://example.test/feed.xml');
    expect(data_get($run?->columns, 'category_id'))->toBe(22);
    expect(data_get($run?->columns, 'finalize_missing'))->toBeFalse();
});

function prepareSupplierImportPageTables(): void
{
    DatabaseSchema::dropIfExists('import_feed_sources');
    DatabaseSchema::dropIfExists('import_issues');
    DatabaseSchema::dropIfExists('import_runs');
    DatabaseSchema::dropIfExists('supplier_import_sources');
    DatabaseSchema::dropIfExists('product_supplier_references');
    DatabaseSchema::dropIfExists('categories');
    DatabaseSchema::dropIfExists('suppliers');

    DatabaseSchema::create('suppliers', function (Blueprint $table): void {
        $table->id();
        $table->string('name')->unique();
        $table->string('slug')->unique()->nullable();
        $table->boolean('is_active')->default(true);
        $table->timestamps();
    });

    DatabaseSchema::create('categories', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('slug')->nullable();
        $table->integer('parent_id')->default(-1);
        $table->boolean('is_active')->default(true);
        $table->integer('order')->default(0);
        $table->timestamps();
    });

    DatabaseSchema::create('supplier_import_sources', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('supplier_id');
        $table->string('name', 160);
        $table->string('driver_key', 120);
        $table->string('profile_key', 120)->nullable();
        $table->json('settings')->nullable();
        $table->boolean('is_active')->default(true);
        $table->unsignedInteger('sort')->default(0);
        $table->timestamp('last_used_at')->nullable();
        $table->timestamps();
    });

    DatabaseSchema::create('product_supplier_references', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('supplier_id')->nullable();
        $table->string('supplier', 120)->nullable();
        $table->string('external_id', 120);
        $table->unsignedBigInteger('product_id')->nullable();
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
