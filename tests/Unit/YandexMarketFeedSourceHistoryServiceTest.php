<?php

use App\Models\ImportFeedSource;
use App\Support\CatalogImport\Yml\YandexMarketFeedSourceHistoryService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema as DatabaseSchema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class);

it('deduplicates remembered valid urls', function () {
    prepareYandexMarketFeedSourceHistoryTables();

    $service = app(YandexMarketFeedSourceHistoryService::class);

    $first = $service->rememberValidUrl('HTTPS://EXAMPLE.TEST/path/feed.xml');
    $second = $service->rememberValidUrl('https://example.test/path/feed.xml');

    expect($first->id)->toBe($second->id);
    expect($first->source_url)->toBe('https://example.test/path/feed.xml');

    $options = $service->historyOptions();
    expect($options)->toHaveKey((string) $first->id);
});

it('deduplicates uploaded files by content hash and resolves absolute path', function () {
    prepareYandexMarketFeedSourceHistoryTables();
    Storage::fake('local');

    $service = app(YandexMarketFeedSourceHistoryService::class);
    $tempDirectory = YandexMarketFeedSourceHistoryService::temporaryUploadDirectory();

    $firstTemp = $tempDirectory.'/first.xml';
    $secondTemp = $tempDirectory.'/second.xml';
    Storage::disk('local')->put($firstTemp, '<xml>same</xml>');
    Storage::disk('local')->put($secondTemp, '<xml>same</xml>');

    $first = $service->rememberValidUploadedPath($firstTemp, 'first.xml');
    $second = $service->rememberValidUploadedPath($secondTemp, 'second.xml');

    expect($first->id)->toBe($second->id);
    expect($first->stored_path)->not->toBeNull();
    expect(Storage::disk('local')->exists((string) $first->stored_path))->toBeTrue();
    expect(Storage::disk('local')->exists($firstTemp))->toBeFalse();
    expect(Storage::disk('local')->exists($secondTemp))->toBeFalse();

    $resolved = $service->resolveFromHistoryId((int) $first->id);

    expect($resolved)->not->toBeNull();
    expect($resolved['source_type'] ?? null)->toBe(YandexMarketFeedSourceHistoryService::SOURCE_TYPE_UPLOAD);
    expect((string) ($resolved['stored_path'] ?? ''))->toBe((string) $first->stored_path);
    expect(is_file((string) ($resolved['source'] ?? '')))->toBeTrue();
});

it('prunes expired upload records and deletes source file', function () {
    prepareYandexMarketFeedSourceHistoryTables();
    Storage::fake('local');

    $storedPath = 'catalog-import/yandex-feed-sources/expired.xml';
    Storage::disk('local')->put($storedPath, '<xml>expired</xml>');

    $expired = ImportFeedSource::query()->create([
        'supplier' => 'yandex_market_feed',
        'source_type' => YandexMarketFeedSourceHistoryService::SOURCE_TYPE_UPLOAD,
        'fingerprint' => hash('sha256', 'expired-upload'),
        'source_url' => null,
        'stored_path' => $storedPath,
        'original_filename' => 'expired.xml',
        'content_hash' => hash('sha256', 'expired-content'),
        'size_bytes' => 15,
        'created_by' => null,
        'last_run_id' => null,
        'last_used_at' => null,
        'last_validated_at' => null,
        'meta' => null,
    ]);

    ImportFeedSource::query()
        ->whereKey($expired->id)
        ->update([
            'created_at' => now()->subDays(366),
            'updated_at' => now()->subDays(366),
        ]);

    app(YandexMarketFeedSourceHistoryService::class)->pruneExpired();

    expect(ImportFeedSource::query()->whereKey($expired->id)->exists())->toBeFalse();
    expect(Storage::disk('local')->exists($storedPath))->toBeFalse();
});

function prepareYandexMarketFeedSourceHistoryTables(): void
{
    if (DatabaseSchema::hasTable('import_feed_sources')) {
        return;
    }

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
