<?php

use App\Filament\Pages\YandexMarketFeedImport;
use App\Jobs\RunYandexMarketFeedImportJob;
use App\Models\ImportRun;
use App\Support\CatalogImport\Yml\YandexMarketFeedImportService;
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
    $page = new YandexMarketFeedImport;
    $page->mount();

    $schema = $page->form(Schema::make($page));

    $sourceField = $schema->getComponent(
        fn ($component) => $component instanceof TextInput && $component->getName() === 'source',
    );
    $categoryField = $schema->getComponent(
        fn ($component) => $component instanceof Select && $component->getName() === 'category_id',
    );
    $modeField = $schema->getComponent(
        fn ($component) => $component instanceof Select && $component->getName() === 'mode',
    );
    $downloadImagesField = $schema->getComponent(
        fn ($component) => $component instanceof Toggle && $component->getName() === 'download_images',
    );

    expect($sourceField)->not->toBeNull();
    expect($categoryField)->not->toBeNull();
    expect($modeField)->not->toBeNull();
    expect($downloadImagesField)->not->toBeNull();
});

test('yandex market feed import page loads categories from feed source', function () {
    $service = \Mockery::mock(YandexMarketFeedImportService::class);
    $service->shouldReceive('listCategories')
        ->once()
        ->andReturn([
            11 => 'Компрессоры',
            22 => 'Пылесосы',
        ]);

    app()->instance(YandexMarketFeedImportService::class, $service);

    $page = new YandexMarketFeedImport;
    $page->mount();
    $page->data = array_merge($page->data ?? [], [
        'source' => 'https://example.test/yandex.xml',
    ]);

    $page->loadFeedCategories();

    expect($page->parsedCategories)->toBe([
        11 => 'Компрессоры',
        22 => 'Пылесосы',
    ]);
    expect($page->categoriesLoadedSource)->toBe('https://example.test/yandex.xml');
    expect($page->categoriesLoadedAt)->not->toBeNull();
});

test('yandex market feed import page dispatches job with selected category filter', function () {
    prepareYandexMarketFeedImportPageTables();
    Queue::fake();

    $page = new YandexMarketFeedImport;
    $page->mount();
    $page->data = array_merge($page->data ?? [], [
        'source' => 'https://example.test/yandex-market-feed.xml',
        'category_id' => 22,
        'mode' => 'partial_import',
    ]);

    $page->doDryRun();

    $run = ImportRun::query()->find($page->lastRunId);

    expect($run?->type)->toBe('yandex_market_feed_products');
    expect(data_get($run?->columns, 'source'))->toBe('https://example.test/yandex-market-feed.xml');
    expect(data_get($run?->columns, 'category_id'))->toBe(22);

    Queue::assertPushed(RunYandexMarketFeedImportJob::class, function (RunYandexMarketFeedImportJob $job) use ($page): bool {
        return $job->runId === $page->lastRunId && $job->write === false;
    });
});

function prepareYandexMarketFeedImportPageTables(): void
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
