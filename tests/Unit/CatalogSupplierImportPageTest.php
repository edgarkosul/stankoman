<?php

use App\Filament\Pages\CatalogSupplierImport;
use App\Filament\Resources\ImportRuns\ImportRunResource;
use App\Jobs\RunMetalmasterProductImportJob;
use App\Jobs\RunVactoolProductImportJob;
use App\Models\ImportRun;
use App\Support\Filament\HelpCenter;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
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
    $page->data = array_merge($page->data ?? [], [
        'supplier' => 'metalmaster',
    ]);

    $schema = $page->form(Schema::make($page));

    $supplierField = $schema->getComponent(
        fn ($component) => $component instanceof Select && $component->getName() === 'supplier',
    );
    $modeField = $schema->getComponent(
        fn ($component) => $component instanceof Select && $component->getName() === 'mode',
    );
    $syncScenarioField = $schema->getComponent(
        fn ($component) => $component instanceof Select && $component->getName() === 'sync_scenario',
    );
    $scopeField = $schema->getComponent(
        fn ($component) => $component instanceof Select && $component->getName() === 'scope',
    );
    $forceMediaRecheckField = $schema->getComponent(
        fn ($component) => $component instanceof Toggle && $component->getName() === 'force_media_recheck',
    );
    $skipExistingField = $schema->getComponent(
        fn ($component) => $component instanceof Toggle && $component->getName() === 'skip_existing',
    );

    expect($supplierField)->not->toBeNull();
    expect($modeField)->not->toBeNull();
    expect($syncScenarioField)->not->toBeNull();
    expect($scopeField)->not->toBeNull();
    expect($forceMediaRecheckField)->toBeInstanceOf(Toggle::class)
        ->and($forceMediaRecheckField->getLabel())->toBe('Обновлять картинки, даже если ссылка не изменилась')
        ->and($forceMediaRecheckField->getChildComponents(Field::BELOW_CONTENT_SCHEMA_KEY))->toHaveCount(1)
        ->and($forceMediaRecheckField->getChildComponents(Field::BELOW_CONTENT_SCHEMA_KEY)[0])->toBeInstanceOf(Text::class)
        ->and($forceMediaRecheckField->getChildComponents(Field::BELOW_CONTENT_SCHEMA_KEY)[0]->getContent())
        ->toBe('Используйте это, если поставщик может заменить изображение по старой ссылке. Может немного замедлить импорт.');
    expect($skipExistingField)->not->toBeNull();
});

test('catalog supplier import page keeps history action and is mapped to help page', function () {
    $page = new CatalogSupplierImport;

    $method = new ReflectionMethod(CatalogSupplierImport::class, 'getHeaderActions');
    $method->setAccessible(true);

    $actions = $method->invoke($page);

    expect($actions)->toHaveCount(1);
    expect($actions[0]->getName())->toBe('history');
    expect($actions[0]->getUrl())->toBe(ImportRunResource::getUrl());
    expect(HelpCenter::urlForRouteName('filament.admin.pages.catalog-supplier-import'))
        ->toBe('https://help.stankoman.ru/import/supplier-import/');
});

test('catalog supplier import page applies sync scenario to technical flags', function () {
    $page = new CatalogSupplierImport;

    $page->data = array_merge($page->data ?? [], [
        'sync_scenario' => 'new_only',
    ]);

    $page->updatedDataSyncScenario('new_only');

    expect(data_get($page->data, 'mode'))->toBe('partial_import');
    expect(data_get($page->data, 'finalize_missing'))->toBeFalse();
    expect(data_get($page->data, 'create_missing'))->toBeTrue();
    expect(data_get($page->data, 'update_existing'))->toBeFalse();
    expect(data_get($page->data, 'skip_existing'))->toBeTrue();
});

test('catalog supplier import page switches scenario to custom for manual technical flags', function () {
    $page = new CatalogSupplierImport;

    $page->data = array_merge($page->data ?? [], [
        'create_missing' => false,
        'update_existing' => false,
    ]);

    $page->updatedDataUpdateExisting();

    expect(data_get($page->data, 'sync_scenario'))->toBe('custom');
});

test('catalog supplier import page dispatches vactool and metalmaster jobs', function () {
    prepareCatalogSupplierImportPageTables();
    Queue::fake();

    $page = new CatalogSupplierImport;
    $page->mount();

    $page->data = array_merge($page->data ?? [], [
        'supplier' => 'vactool',
        'mode' => 'partial_import',
    ]);
    $page->doDryRun();

    $vactoolRun = ImportRun::query()->find($page->lastRunId);
    expect($vactoolRun?->type)->toBe('vactool_products');

    Queue::assertPushed(RunVactoolProductImportJob::class, function (RunVactoolProductImportJob $job) use ($page): bool {
        return $job->runId === $page->lastRunId
            && ($job->options['sitemap'] ?? null) === 'https://vactool.ru/sitemap.xml'
            && ($job->options['match'] ?? null) === '/catalog/product-'
            && ($job->options['profile'] ?? null) === 'vactool_html';
    });

    $page->data = array_merge($page->data ?? [], [
        'supplier' => 'metalmaster',
        'scope' => 'promyshlennye',
        'mode' => 'full_sync_authoritative',
    ]);
    $page->doImport();

    $metalmasterRun = ImportRun::query()->find($page->lastRunId);
    expect($metalmasterRun?->type)->toBe('metalmaster_products');
    expect(data_get($metalmasterRun?->columns, 'mode'))->toBe('full_sync_authoritative');

    Queue::assertPushed(RunMetalmasterProductImportJob::class, function (RunMetalmasterProductImportJob $job) use ($page): bool {
        return $job->runId === $page->lastRunId
            && $job->write === true
            && ($job->options['buckets_file'] ?? null) === storage_path('app/parser/metalmaster-buckets.json')
            && ($job->options['bucket'] ?? null) === 'promyshlennye'
            && ($job->options['profile'] ?? null) === 'metalmaster_html';
    });
});

test('catalog supplier import page loads supplier scopes from metalmaster buckets file', function () {
    $bucketsPath = storage_path('app/parser/metalmaster-buckets.json');
    $originalBuckets = is_file($bucketsPath) ? file_get_contents($bucketsPath) : null;

    try {
        if (! is_dir(dirname($bucketsPath))) {
            mkdir(dirname($bucketsPath), 0777, true);
        }

        file_put_contents($bucketsPath, json_encode([
            [
                'bucket' => 'promyshlennye',
                'category_url' => 'https://metalmaster.ru/promyshlennye/',
                'products_count' => 20,
            ],
            [
                'bucket' => 'instrument',
                'category_url' => 'https://metalmaster.ru/instrument/',
                'products_count' => 5,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        $page = new CatalogSupplierImport;
        $page->data = array_merge($page->data ?? [], [
            'supplier' => 'metalmaster',
            'scope' => 'unknown-scope',
        ]);

        $page->loadSupplierScopes();

        expect($page->parsedScopeTree)->toHaveCount(2);
        expect(array_key_exists('promyshlennye', $page->parsedScopeTree))->toBeTrue();
        expect(data_get($page->parsedScopeTree, 'promyshlennye.items_count'))->toBe(20);
        expect(data_get($page->data, 'scope'))->toBe('');
        expect($page->scopesLoadedSource)->toBe($bucketsPath);
        expect($page->scopesLoadedAt)->not->toBeNull();
    } finally {
        if (is_string($originalBuckets)) {
            file_put_contents($bucketsPath, $originalBuckets);
        } elseif (is_file($bucketsPath)) {
            @unlink($bucketsPath);
        }
    }
});

test('catalog supplier import page can regenerate supplier scopes for metalmaster', function () {
    Artisan::shouldReceive('call')
        ->once()
        ->with('parser:sitemap-buckets', ['--no-interaction' => true])
        ->andReturn(0);

    Artisan::shouldReceive('output')
        ->once()
        ->andReturn('Buckets saved: 12');

    $page = new CatalogSupplierImport;
    $page->data = array_merge($page->data ?? [], [
        'supplier' => 'metalmaster',
        'scope' => 'promyshlennye',
    ]);

    $page->regenerateSupplierScopes();

    expect($page->data)->toBeArray();
    expect($page->data['scope'])->toBe('');
});

test('catalog supplier import page stores metalmaster run summary extras', function () {
    prepareCatalogSupplierImportPageTables();

    $run = ImportRun::query()->create([
        'type' => 'metalmaster_products',
        'status' => 'completed',
        'columns' => [
            'bucket' => 'promyshlennye',
            'buckets_file' => storage_path('app/parser/metalmaster-buckets.json'),
        ],
        'totals' => [
            'create' => 1,
            'update' => 2,
            'same' => 3,
            'error' => 0,
            'scanned' => 6,
            '_meta' => [
                'mode' => 'dry-run',
                'is_running' => false,
                'found_urls' => 6,
                'no_urls' => true,
            ],
            '_samples' => [],
        ],
    ]);

    $page = new CatalogSupplierImport;
    $page->data = array_merge($page->data ?? [], [
        'supplier' => 'metalmaster',
    ]);
    $page->lastRunId = $run->id;

    $page->refreshLastSavedRun();

    expect($page->lastSavedRun)->toBeArray();
    expect($page->lastSavedRun['supplier_label'])->toBe('Metalmaster');
    expect($page->lastSavedRun['no_urls'])->toBeTrue();
    expect($page->lastSavedRun['buckets_file'])->toBe(storage_path('app/parser/metalmaster-buckets.json'));
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
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->unsignedBigInteger('supplier_import_source_id')->nullable();
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
