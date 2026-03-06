<?php

namespace App\Support\CatalogImport\Yml;

use App\Models\ProductSupplierReference;
use App\Support\CatalogImport\Contracts\SourceResolverInterface;
use App\Support\CatalogImport\DTO\ProductPayload;
use App\Support\CatalogImport\DTO\ResolvedSource;
use App\Support\CatalogImport\Processing\ProductImportProcessor;
use App\Support\CatalogImport\Sources\SourceResolver;
use Throwable;

class YandexMarketFeedImportService
{
    public function __construct(
        private YandexMarketFeedAdapter $adapter,
        private YandexMarketFeedProfile $profile,
        private YmlStreamParser $recordParser,
        private ProductImportProcessor $processor,
        private SourceResolverInterface $sourceResolver = new SourceResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<int, string>
     */
    public function listCategories(array $options = []): array
    {
        $normalized = $this->normalizeOptions($options);
        $source = $this->resolveSource($normalized);
        $stream = $this->recordParser->open($source->resolvedPath);

        return $stream->categories;
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  null|callable(string, string|array<string, mixed>): void  $output
     * @param  null|callable(array<string, int|bool>): void  $progress
     * @return array{
     *     options: array<string, mixed>,
     *     write_mode: bool,
     *     found_urls: int,
     *     processed: int,
     *     errors: int,
     *     created: int,
     *     updated: int,
     *     skipped: int,
     *     images_downloaded: int,
     *     image_download_failed: int,
     *     derivatives_queued: int,
     *     samples: array<int, array<string, string>>,
     *     url_errors: array<int, array{url: string, message: string}>,
     *     fatal_error: string|null,
     *     no_urls: bool,
     *     success: bool
     * }
     */
    public function run(array $options = [], ?callable $output = null, ?callable $progress = null): array
    {
        $normalized = $this->normalizeOptions($options);

        try {
            $source = $this->resolveSource($normalized);
            [$foundUrls, $prefilteredExternalIds] = $this->scanFeed($source, $normalized);
        } catch (Throwable $exception) {
            $this->emitProgress($progress, $this->makeProgressPayload(
                foundUrls: 0,
                processed: 0,
                errors: 0,
                created: 0,
                updated: 0,
                skipped: 0,
                imagesDownloaded: 0,
                imageDownloadFailed: 0,
                derivativesQueued: 0,
                noUrls: false,
            ));

            return [
                'options' => $normalized,
                'write_mode' => $normalized['write'],
                'found_urls' => 0,
                'processed' => 0,
                'errors' => 0,
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'images_downloaded' => 0,
                'image_download_failed' => 0,
                'derivatives_queued' => 0,
                'samples' => [],
                'url_errors' => [],
                'fatal_error' => $exception->getMessage(),
                'no_urls' => false,
                'success' => false,
            ];
        }

        if ($foundUrls === 0) {
            $this->emit($output, 'warn', 'Подходящие offer-записи не найдены.');
            $this->emitProgress($progress, $this->makeProgressPayload(
                foundUrls: 0,
                processed: 0,
                errors: 0,
                created: 0,
                updated: 0,
                skipped: 0,
                imagesDownloaded: 0,
                imageDownloadFailed: 0,
                derivativesQueued: 0,
                noUrls: true,
            ));

            return [
                'options' => $normalized,
                'write_mode' => $normalized['write'],
                'found_urls' => 0,
                'processed' => 0,
                'errors' => 0,
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'images_downloaded' => 0,
                'image_download_failed' => 0,
                'derivatives_queued' => 0,
                'samples' => [],
                'url_errors' => [],
                'fatal_error' => null,
                'no_urls' => true,
                'success' => true,
            ];
        }

        $this->emit($output, 'info', 'Найдено offer-записей: '.$foundUrls);
        $this->emit($output, 'line', 'Режим: '.($normalized['write'] ? 'write' : 'dry-run'));

        $processed = 0;
        $errors = 0;
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $imagesDownloaded = 0;
        $imageDownloadFailed = 0;
        $derivativesQueued = 0;
        $samples = [];
        $urlErrors = [];
        $processedOffers = 0;
        $touchedPrefilteredExternalIds = [];

        $this->emitProgress($progress, $this->makeProgressPayload(
            foundUrls: $foundUrls,
            processed: $processed,
            errors: $errors,
            created: $created,
            updated: $updated,
            skipped: $skipped,
            imagesDownloaded: $imagesDownloaded,
            imageDownloadFailed: $imageDownloadFailed,
            derivativesQueued: $derivativesQueued,
            noUrls: false,
        ));

        try {
            foreach ($this->recordParser->parse($source, []) as $record) {
                if (! $record instanceof YmlOfferRecord) {
                    continue;
                }

                if (! $this->passesCategoryFilter($record, $normalized['category_id'])) {
                    continue;
                }

                if ($normalized['limit'] > 0 && $processedOffers >= $normalized['limit']) {
                    break;
                }

                $processedOffers++;
                $externalId = trim($record->id);
                $errorRow = $externalId !== '' ? 'offer:'.$externalId : 'offer#'.$processedOffers;

                if (
                    $normalized['write']
                    && $normalized['skip_existing']
                    && $externalId !== ''
                    && isset($prefilteredExternalIds[$externalId])
                ) {
                    $processed++;
                    $skipped++;
                    $touchedPrefilteredExternalIds[$externalId] = true;

                    $this->emit($output, 'line', 'SKIP: '.$errorRow.' | existing product reference.');

                    $this->emitProgress($progress, $this->makeProgressPayload(
                        foundUrls: $foundUrls,
                        processed: $processed,
                        errors: $errors,
                        created: $created,
                        updated: $updated,
                        skipped: $skipped,
                        imagesDownloaded: $imagesDownloaded,
                        imageDownloadFailed: $imageDownloadFailed,
                        derivativesQueued: $derivativesQueued,
                        noUrls: false,
                    ));

                    continue;
                }

                try {
                    $mapping = $this->adapter->mapRecord($record);

                    if ($mapping->payload === null) {
                        $errors += count($mapping->errors);
                        $urlErrors[] = [
                            'url' => $errorRow,
                            'message' => $mapping->errors[0]->message ?? 'Record mapping failed.',
                        ];

                        $this->emit($output, 'error', 'ERR: '.$errorRow.' | '.($mapping->errors[0]->message ?? 'Record mapping failed.'));

                        $this->emitProgress($progress, $this->makeProgressPayload(
                            foundUrls: $foundUrls,
                            processed: $processed,
                            errors: $errors,
                            created: $created,
                            updated: $updated,
                            skipped: $skipped,
                            imagesDownloaded: $imagesDownloaded,
                            imageDownloadFailed: $imageDownloadFailed,
                            derivativesQueued: $derivativesQueued,
                            noUrls: false,
                        ));

                        continue;
                    }

                    $errors += count($mapping->errors);

                    if ($normalized['write']) {
                        $processResult = $this->processor->process(
                            $mapping->payload,
                            $this->processorOptions($normalized),
                        );

                        if ($processResult->operation === 'created') {
                            $created++;
                        } elseif ($processResult->operation === 'updated') {
                            $updated++;
                        } else {
                            $skipped++;
                        }

                        $processed++;
                        $errors += count($processResult->errors);

                        $imagesDownloaded += (int) ($processResult->meta['media_reused'] ?? 0);
                        $derivativesQueued += (int) ($processResult->meta['media_queued'] ?? 0);
                        $imageDownloadFailed += $this->countMediaErrors($processResult->errors);
                    } elseif (count($samples) < $normalized['show_samples']) {
                        $samples[] = $this->sampleRow($mapping->payload, $record->type);
                        $processed++;
                    } else {
                        $processed++;
                    }

                    $this->emit($output, 'line', 'OK: '.$errorRow);

                    if ($normalized['delay_ms'] > 0) {
                        usleep($normalized['delay_ms'] * 1000);
                    }
                } catch (Throwable $exception) {
                    $errors++;
                    $urlErrors[] = [
                        'url' => $errorRow,
                        'message' => $exception->getMessage(),
                    ];

                    $this->emit($output, 'error', 'ERR: '.$errorRow.' | '.$exception->getMessage());
                }

                $this->emitProgress($progress, $this->makeProgressPayload(
                    foundUrls: $foundUrls,
                    processed: $processed,
                    errors: $errors,
                    created: $created,
                    updated: $updated,
                    skipped: $skipped,
                    imagesDownloaded: $imagesDownloaded,
                    imageDownloadFailed: $imageDownloadFailed,
                    derivativesQueued: $derivativesQueued,
                    noUrls: false,
                ));
            }
        } catch (Throwable $exception) {
            return [
                'options' => $normalized,
                'write_mode' => $normalized['write'],
                'found_urls' => $foundUrls,
                'processed' => $processed,
                'errors' => $errors + 1,
                'created' => $created,
                'updated' => $updated,
                'skipped' => $skipped,
                'images_downloaded' => $imagesDownloaded,
                'image_download_failed' => $imageDownloadFailed,
                'derivatives_queued' => $derivativesQueued,
                'samples' => $samples,
                'url_errors' => array_merge($urlErrors, [[
                    'url' => 'feed',
                    'message' => $exception->getMessage(),
                ]]),
                'fatal_error' => $exception->getMessage(),
                'no_urls' => false,
                'success' => false,
            ];
        }

        if ($normalized['write'] && $normalized['run_id'] !== null) {
            $this->touchPrefilteredReferences($touchedPrefilteredExternalIds, $normalized['run_id']);

            $this->processor->finalizeMissing(
                supplier: $this->profile->supplierKey(),
                runId: $normalized['run_id'],
                options: [
                    'mode' => $normalized['mode'],
                    'finalize_missing' => $normalized['finalize_missing'],
                    'source_category_id' => $normalized['category_id'],
                ],
            );
        }

        $this->emit($output, 'new_line', '');
        $this->emit($output, 'info', 'Итого: processed='.$processed.', errors='.$errors.'.');

        if ($normalized['write']) {
            $this->emit($output, 'line', 'DB: created='.$created.', updated='.$updated.', skipped='.$skipped.'.');

            if ($normalized['download_images']) {
                $this->emit(
                    $output,
                    'line',
                    'Images: downloaded='.$imagesDownloaded
                    .', failed='.$imageDownloadFailed
                    .', derivatives_queued='.$derivativesQueued
                    .'.'
                );
            }
        }

        if (! $normalized['write'] && $samples !== []) {
            $this->emit($output, 'new_line', '');
            $this->emit($output, 'table', [
                'headers' => ['external_id', 'name', 'price', 'currency', 'offer_type'],
                'rows' => $samples,
            ]);
        }

        return [
            'options' => $normalized,
            'write_mode' => $normalized['write'],
            'found_urls' => $foundUrls,
            'processed' => $processed,
            'errors' => $errors,
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'images_downloaded' => $imagesDownloaded,
            'image_download_failed' => $imageDownloadFailed,
            'derivatives_queued' => $derivativesQueued,
            'samples' => $samples,
            'url_errors' => $urlErrors,
            'fatal_error' => null,
            'no_urls' => false,
            'success' => $processed > 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{
     *     source: string,
     *     category_id: int|null,
     *     limit: int,
     *     timeout: int,
     *     delay_ms: int,
     *     write: bool,
     *     publish: bool,
     *     download_images: bool,
     *     skip_existing: bool,
     *     show_samples: int,
     *     run_id: int|null,
     *     mode: string,
     *     finalize_missing: bool,
     *     create_missing: bool,
     *     update_existing: bool
     * }
     */
    private function normalizeOptions(array $options): array
    {
        $runId = $options['run_id'] ?? null;
        $skipExisting = $this->normalizeBoolOption(
            $options['skip_existing'] ?? $options['skip-existing'] ?? false,
            false,
        );
        $mode = $this->normalizeMode($options['mode'] ?? null);

        return [
            'source' => trim((string) ($options['source'] ?? $options['feed'] ?? '')),
            'category_id' => $this->normalizeNullableInt($options['category_id'] ?? $options['category-id'] ?? null),
            'limit' => max(0, (int) ($options['limit'] ?? 0)),
            'timeout' => max(1, (int) ($options['timeout'] ?? 25)),
            'delay_ms' => max(0, (int) ($options['delay_ms'] ?? $options['delay-ms'] ?? 0)),
            'write' => $this->normalizeBoolOption($options['write'] ?? false, false),
            'publish' => $this->normalizeBoolOption($options['publish'] ?? false, false),
            'download_images' => $this->normalizeBoolOption(
                $options['download_images'] ?? $options['download-images'] ?? false,
                false,
            ),
            'skip_existing' => $skipExisting,
            'show_samples' => max(0, (int) ($options['show_samples'] ?? $options['show-samples'] ?? 3)),
            'run_id' => is_numeric($runId) ? (int) $runId : null,
            'mode' => $mode,
            'finalize_missing' => $this->normalizeBoolOption(
                $options['finalize_missing'] ?? $options['finalize-missing'] ?? null,
                $mode === 'full_sync_authoritative',
            ),
            'create_missing' => $this->normalizeBoolOption(
                $options['create_missing'] ?? $options['create-missing'] ?? null,
                true,
            ),
            'update_existing' => $this->normalizeBoolOption(
                $options['update_existing'] ?? $options['update-existing'] ?? null,
                ! $skipExisting,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @return array{0:int,1:array<string, true>}
     */
    private function scanFeed(ResolvedSource $source, array $normalized): array
    {
        $foundUrls = 0;
        $candidateExternalIds = [];

        foreach ($this->recordParser->parse($source, []) as $record) {
            if (! $record instanceof YmlOfferRecord) {
                continue;
            }

            if (! $this->passesCategoryFilter($record, $normalized['category_id'])) {
                continue;
            }

            if ($normalized['limit'] > 0 && $foundUrls >= $normalized['limit']) {
                break;
            }

            $foundUrls++;

            if (! $normalized['write'] || ! $normalized['skip_existing']) {
                continue;
            }

            $externalId = trim($record->id);

            if ($externalId === '') {
                continue;
            }

            $candidateExternalIds[$externalId] = true;
        }

        if (! $normalized['write'] || ! $normalized['skip_existing'] || $candidateExternalIds === []) {
            return [$foundUrls, []];
        }

        $prefiltered = [];

        foreach (array_chunk(array_keys($candidateExternalIds), 1000) as $chunk) {
            $existing = ProductSupplierReference::query()
                ->where('supplier', $this->profile->supplierKey())
                ->whereIn('external_id', $chunk)
                ->pluck('external_id');

            foreach ($existing as $externalId) {
                if (is_string($externalId) && $externalId !== '') {
                    $prefiltered[$externalId] = true;
                }
            }
        }

        return [$foundUrls, $prefiltered];
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @return array<string, mixed>
     */
    private function processorOptions(array $normalized): array
    {
        return [
            'supplier' => $this->profile->supplierKey(),
            'run_id' => $normalized['run_id'],
            'create_missing' => $normalized['create_missing'],
            'update_existing' => $normalized['update_existing'] && ! $normalized['skip_existing'],
            'publish_created' => $normalized['publish'],
            'publish_updated' => $normalized['publish'],
            'download_media' => $normalized['download_images'],
            'use_source_slug' => false,
            'mode' => $normalized['mode'],
        ];
    }

    /**
     * @param  array<string, true>  $prefilteredExternalIds
     */
    private function touchPrefilteredReferences(array $prefilteredExternalIds, int $runId): void
    {
        if ($prefilteredExternalIds === [] || $runId <= 0) {
            return;
        }

        ProductSupplierReference::query()
            ->where('supplier', $this->profile->supplierKey())
            ->whereIn('external_id', array_keys($prefilteredExternalIds))
            ->update([
                'last_seen_run_id' => $runId,
                'last_seen_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function resolveSource(array $normalized): ResolvedSource
    {
        return $this->sourceResolver->resolve($normalized['source'], [
            'cache_key' => $this->profile->supplierKey().'_'.sha1($normalized['source']),
            'timeout' => max(1, (float) $normalized['timeout']),
            'connect_timeout' => min(10.0, max(1.0, (float) $normalized['timeout'])),
            'retry_times' => 2,
            'retry_sleep_ms' => 300,
        ]);
    }

    private function passesCategoryFilter(YmlOfferRecord $record, ?int $categoryId): bool
    {
        if ($categoryId === null) {
            return true;
        }

        return $record->categoryId === $categoryId;
    }

    private function normalizeNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
            $parsed = (int) trim($value);

            return $parsed > 0 ? $parsed : null;
        }

        return null;
    }

    private function normalizeMode(mixed $mode): string
    {
        if (! is_string($mode)) {
            return 'partial_import';
        }

        $mode = trim($mode);

        if (in_array($mode, ['partial_import', 'full_sync_authoritative'], true)) {
            return $mode;
        }

        return 'partial_import';
    }

    private function normalizeBoolOption(mixed $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        if (is_string($value)) {
            $normalized = mb_strtolower(trim($value));

            if ($normalized === '') {
                return $default;
            }

            if (in_array($normalized, ['1', 'true', 'yes', 'y', 'on'], true)) {
                return true;
            }

            if (in_array($normalized, ['0', 'false', 'no', 'n', 'off'], true)) {
                return false;
            }
        }

        return (bool) $value;
    }

    /**
     * @param  array<int, \App\Support\CatalogImport\DTO\ImportError>  $errors
     */
    private function countMediaErrors(array $errors): int
    {
        return collect($errors)
            ->filter(fn ($error): bool => str_starts_with((string) ($error->code ?? ''), 'media_'))
            ->count();
    }

    /**
     * @return array{external_id: string, name: string, price: string, currency: string, offer_type: string}
     */
    private function sampleRow(ProductPayload $payload, ?string $offerType): array
    {
        return [
            'external_id' => $payload->externalId,
            'name' => $payload->name,
            'price' => (string) ($payload->priceAmount ?? 0),
            'currency' => (string) ($payload->currency ?? ''),
            'offer_type' => $offerType ?? 'simple',
        ];
    }

    /**
     * @param  null|callable(string, string|array<string, mixed>): void  $output
     */
    private function emit(?callable $output, string $type, string|array $payload): void
    {
        if ($output === null) {
            return;
        }

        $output($type, $payload);
    }

    /**
     * @param  null|callable(array<string, int|bool>): void  $progress
     * @param  array<string, int|bool>  $payload
     */
    private function emitProgress(?callable $progress, array $payload): void
    {
        if ($progress === null) {
            return;
        }

        $progress($payload);
    }

    /**
     * @return array<string, int|bool>
     */
    private function makeProgressPayload(
        int $foundUrls,
        int $processed,
        int $errors,
        int $created,
        int $updated,
        int $skipped,
        int $imagesDownloaded,
        int $imageDownloadFailed,
        int $derivativesQueued,
        bool $noUrls,
    ): array {
        return [
            'found_urls' => $foundUrls,
            'processed' => $processed,
            'errors' => $errors,
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'images_downloaded' => $imagesDownloaded,
            'image_download_failed' => $imageDownloadFailed,
            'derivatives_queued' => $derivativesQueued,
            'no_urls' => $noUrls,
        ];
    }
}
