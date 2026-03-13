<?php

use App\Filament\Pages\YandexMarketFeedDeactivate;
use App\Jobs\RunYandexMarketFeedDeactivationJob;
use App\Models\Category;
use App\Models\ImportRun;
use App\Models\Supplier;
use App\Support\CatalogImport\Yml\YandexMarketFeedImportService;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema as DatabaseSchema;
use Tests\TestCase;

uses(TestCase::class);

test('yandex market feed deactivation form has supplier category and source controls', function () {
    prepareYandexMarketFeedDeactivatePageTables();

    $page = new YandexMarketFeedDeactivate;
    $page->mount();

    $schema = $page->form(Schema::make($page));

    $supplierField = $schema->getComponent(
        fn ($component) => $component instanceof Select && $component->getName() === 'supplier_id',
    );
    $siteCategoryField = $schema->getComponent(
        fn ($component) => $component instanceof Select && $component->getName() === 'site_category_id',
    );
    $sourceModeField = $schema->getComponent(
        fn ($component) => $component instanceof Select && $component->getName() === 'source_mode',
    );
    $sourceUrlField = $schema->getComponent(
        fn ($component) => $component instanceof TextInput && $component->getName() === 'source_url',
    );

    expect($supplierField)->not->toBeNull();
    expect($siteCategoryField)->not->toBeNull();
    expect($sourceModeField)->not->toBeNull();
    expect($sourceUrlField)->not->toBeNull();
});

test('yandex market feed deactivation page dispatches dry run with selected supplier and category', function () {
    prepareYandexMarketFeedDeactivatePageTables();
    Queue::fake();

    $supplier = Supplier::query()->create([
        'name' => 'Feed Supplier Page',
        'is_active' => true,
    ]);

    $category = Category::query()->create([
        'name' => 'Компрессоры',
        'slug' => 'kompressory-page',
        'is_active' => true,
        'parent_id' => -1,
        'order' => 1001,
    ]);

    $service = Mockery::mock(YandexMarketFeedImportService::class);
    $service->shouldReceive('listCategoryNodes')
        ->once()
        ->andReturn([]);

    app()->instance(YandexMarketFeedImportService::class, $service);

    $page = new YandexMarketFeedDeactivate;
    $page->mount();
    $page->data = array_merge($page->data ?? [], [
        'supplier_id' => $supplier->id,
        'site_category_id' => $category->id,
        'source_mode' => 'url',
        'source_url' => 'https://example.test/deactivate.xml',
    ]);

    $page->doDryRun();

    $run = ImportRun::query()->find($page->lastRunId);

    expect($run?->type)->toBe('yandex_market_feed_deactivation');
    expect(data_get($run?->columns, 'supplier_id'))->toBe($supplier->id);
    expect(data_get($run?->columns, 'site_category_id'))->toBe($category->id);
    expect(data_get($run?->columns, 'source'))->toBe('https://example.test/deactivate.xml');
    expect(data_get($run?->columns, 'write'))->toBeFalse();

    Queue::assertPushed(RunYandexMarketFeedDeactivationJob::class, function (RunYandexMarketFeedDeactivationJob $job) use ($page, $supplier, $category): bool {
        return $job->runId === $page->lastRunId
            && $job->write === false
            && (int) ($job->options['supplier_id'] ?? 0) === $supplier->id
            && (int) ($job->options['site_category_id'] ?? 0) === $category->id
            && $job->afterCommit === true;
    });
});

function prepareYandexMarketFeedDeactivatePageTables(): void
{
    DatabaseSchema::dropIfExists('import_feed_sources');
    DatabaseSchema::dropIfExists('import_issues');
    DatabaseSchema::dropIfExists('import_runs');
    DatabaseSchema::dropIfExists('categories');
    DatabaseSchema::dropIfExists('suppliers');

    DatabaseSchema::create('suppliers', function (Blueprint $table): void {
        $table->id();
        $table->string('name')->unique();
        $table->string('slug')->unique();
        $table->boolean('is_active')->default(true);
        $table->timestamps();
    });

    DatabaseSchema::create('categories', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('slug');
        $table->string('img')->nullable();
        $table->boolean('is_active')->default(true);
        $table->integer('parent_id')->default(-1)->index();
        $table->integer('order')->default(0)->index();
        $table->json('meta_json')->nullable();
        $table->timestamps();

        $table->unique(['parent_id', 'slug']);
        $table->unique(['parent_id', 'order']);
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
