<?php

namespace App\Support\CatalogImport\Yml;

use App\Models\ProductSupplierReference;
use App\Support\CatalogImport\Contracts\ImportRunEventLoggerInterface;
use App\Support\CatalogImport\Contracts\SourceResolverInterface;
use App\Support\CatalogImport\DTO\ImportError;
use App\Support\CatalogImport\DTO\ProductPayload;
use App\Support\CatalogImport\DTO\ResolvedSource;
use App\Support\CatalogImport\Processing\ExistingProductUpdateSelection;
use App\Support\CatalogImport\Processing\ProductImportProcessor;
use App\Support\CatalogImport\Runs\DatabaseImportRunEventLogger;
use App\Support\CatalogImport\Runs\ImportRunEventData;
use App\Support\CatalogImport\Sources\SourceResolver;
use Illuminate\Support\Facades\Schema;
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
        $categories = [];

        foreach ($this->listCategoryNodes($options) as $categoryId => $node) {
            $categories[$categoryId] = $node['name'];
        }

        return $categories;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<int, array{id: int, name: string, parent_id: int|null}>
     */
    public function listCategoryNodes(array $options = []): array
    {
        $normalized = $this->normalizeOptions($options);
        $source = $this->resolveSource($normalized);
        $stream = $this->recordParser->open($source->resolvedPath);

        $categoryNodes = [];

        foreach ($stream->categories as $categoryId => $categoryName) {
            if (! is_int($categoryId) || $categoryId <= 0) {
                continue;
            }

            $parentId = $stream->categoryParents[$categoryId] ?? null;

            $categoryNodes[$categoryId] = [
                'id' => $categoryId,
                'name' => trim((string) $categoryName),
                'parent_id' => is_int($parentId) && $parentId > 0 ? $parentId : null,
            ];
        }

        return $categoryNodes;
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
        $eventLogger = $this->makeEventLogger($normalized['run_id']);

        try {
            try {
                $source = $this->resolveSource($normalized);
                [$foundUrls, $prefilteredExternalIds, $categoryFilterIds] = $this->scanFeed($source, $normalized);
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

                $this->logRunEvent(
                    logger: $eventLogger,
                    runId: $normalized['run_id'],
                    stage: 'runtime',
                    result: 'fatal',
                    code: 'source_resolve_failed',
                    message: 'Не удалось подготовить источник импорта: '.$exception->getMessage(),
                    context: [
                        'source' => $normalized['source'],
                    ],
                );

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

                $this->logRunEvent(
                    logger: $eventLogger,
                    runId: $normalized['run_id'],
                    stage: 'runtime',
                    result: 'skipped',
                    code: 'no_matching_offers',
                    message: 'Не найдено YML offer-записей, подходящих под текущие фильтры запуска.',
                );

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

                    if (! $this->passesCategoryFilter($record, $categoryFilterIds)) {
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

                        $this->logRunEvent(
                            logger: $eventLogger,
                            runId: $normalized['run_id'],
                            stage: 'prefilter',
                            result: 'skipped',
                            sourceRef: $errorRow,
                            externalId: $externalId,
                            code: 'existing_reference',
                            message: 'Offer-запись пропущена на предфильтре: ссылка поставщика уже существует.',
                        );

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
                                'message' => $mapping->errors[0]->message ?? 'Не удалось сопоставить запись.',
                            ];

                            foreach ($mapping->errors as $mappingError) {
                                $this->logRunEvent(
                                    logger: $eventLogger,
                                    runId: $normalized['run_id'],
                                    stage: 'mapping',
                                    result: 'error',
                                    sourceRef: $errorRow,
                                    externalId: $externalId !== '' ? $externalId : null,
                                    code: $mappingError->code,
                                    message: $mappingError->message,
                                );
                            }

                            $this->emit($output, 'error', 'ERR: '.$errorRow.' | '.($mapping->errors[0]->message ?? 'Не удалось сопоставить запись.'));

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

                        foreach ($mapping->errors as $mappingError) {
                            $urlErrors[] = [
                                'url' => $errorRow,
                                'message' => $mappingError->message,
                            ];

                            $this->logRunEvent(
                                logger: $eventLogger,
                                runId: $normalized['run_id'],
                                stage: 'mapping',
                                result: 'warning',
                                sourceRef: $errorRow,
                                externalId: $externalId !== '' ? $externalId : null,
                                code: $mappingError->code,
                                message: $mappingError->message,
                            );
                        }

                        if ($normalized['write']) {
                            $processResult = $this->processor->process(
                                $mapping->payload,
                                $this->processorOptions($normalized, $eventLogger, $errorRow),
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

                            $imagesDownloaded += (int) ($processResult->meta['media_queued'] ?? 0);
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

                        $this->logRunEvent(
                            logger: $eventLogger,
                            runId: $normalized['run_id'],
                            stage: 'runtime',
                            result: 'error',
                            sourceRef: $errorRow,
                            externalId: $externalId !== '' ? $externalId : null,
                            code: 'record_exception',
                            message: 'Ошибка обработки offer-записи: '.$exception->getMessage(),
                        );

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
                $this->logRunEvent(
                    logger: $eventLogger,
                    runId: $normalized['run_id'],
                    stage: 'runtime',
                    result: 'fatal',
                    code: 'feed_parse_exception',
                    message: 'Ошибка разбора YML-потока: '.$exception->getMessage(),
                    context: [
                        'source' => $normalized['source'],
                    ],
                );

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
                $this->touchPrefilteredReferences(
                    $touchedPrefilteredExternalIds,
                    $normalized['run_id'],
                    $normalized['supplier_id'],
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
        } finally {
            $eventLogger?->flush();
        }
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{
     *     source: string,
     *     supplier_id: int|null,
     *     category_id: int|null,
     *     limit: int,
     *     timeout: int,
     *     delay_ms: int,
     *     write: bool,
     *     publish: bool,
     *     download_images: bool,
     *     force_media_recheck: bool,
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
            'supplier_id' => $this->normalizeNullableInt($options['supplier_id'] ?? $options['supplier-id'] ?? null),
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
            'force_media_recheck' => $this->normalizeBoolOption(
                $options['force_media_recheck'] ?? $options['force-media-recheck'] ?? false,
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
            'update_existing_mode' => ExistingProductUpdateSelection::normalizeMode(
                $options['update_existing_mode'] ?? $options['update-existing-mode'] ?? null,
            ),
            'update_existing_fields' => ExistingProductUpdateSelection::normalizeFields(
                $options['update_existing_fields'] ?? $options['update-existing-fields'] ?? null,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @return array{0:int,1:array<string, true>,2:array<int, true>}
     */
    private function scanFeed(ResolvedSource $source, array $normalized): array
    {
        $foundUrls = 0;
        $candidateExternalIds = [];
        $stream = $this->recordParser->open($source->resolvedPath);
        $categoryFilterIds = $this->resolveCategoryFilterIds(
            selectedCategoryId: $normalized['category_id'],
            categories: $stream->categories,
            categoryParents: $stream->categoryParents,
        );

        foreach ($stream->offers as $record) {
            if (! $record instanceof YmlOfferRecord) {
                continue;
            }

            if (! $this->passesCategoryFilter($record, $categoryFilterIds)) {
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
            return [$foundUrls, [], $categoryFilterIds];
        }

        $prefiltered = [];
        $useSupplierEntityReference = $this->supportsSupplierEntityReference() && $normalized['supplier_id'] !== null;

        $candidateExternalIdList = array_map(
            static fn (int|string $externalId): string => (string) $externalId,
            array_keys($candidateExternalIds),
        );

        foreach (array_chunk($candidateExternalIdList, 1000) as $chunk) {
            $referenceQuery = ProductSupplierReference::query()
                ->whereIn('external_id', $chunk);

            if ($useSupplierEntityReference) {
                $referenceQuery->where('supplier_id', $normalized['supplier_id']);
            } else {
                $referenceQuery->where('supplier', $this->profile->supplierKey());
            }

            $existing = $referenceQuery->pluck('external_id');

            foreach ($existing as $externalId) {
                if (is_string($externalId) && $externalId !== '') {
                    $prefiltered[$externalId] = true;
                }
            }
        }

        return [$foundUrls, $prefiltered, $categoryFilterIds];
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @return array<string, mixed>
     */
    private function processorOptions(
        array $normalized,
        ?ImportRunEventLoggerInterface $eventLogger = null,
        ?string $sourceRef = null,
    ): array {
        return [
            'supplier' => $this->profile->supplierKey(),
            'supplier_id' => $normalized['supplier_id'],
            'run_id' => $normalized['run_id'],
            'create_missing' => $normalized['create_missing'],
            'update_existing' => $normalized['update_existing'] && ! $normalized['skip_existing'],
            'publish_created' => $normalized['publish'],
            'publish_updated' => $normalized['publish'],
            'download_media' => $normalized['download_images'],
            'force_media_recheck' => $normalized['force_media_recheck'],
            'update_existing_mode' => $normalized['update_existing_mode'],
            'update_existing_fields' => $normalized['update_existing_fields'],
            'use_source_slug' => false,
            'mode' => $normalized['mode'],
            'event_logger' => $eventLogger,
            'source_ref' => $sourceRef,
        ];
    }

    /**
     * @param  array<string, true>  $prefilteredExternalIds
     */
    private function touchPrefilteredReferences(array $prefilteredExternalIds, int $runId, ?int $supplierId = null): void
    {
        if ($prefilteredExternalIds === [] || $runId <= 0) {
            return;
        }

        $externalIds = array_map(
            static fn (int|string $externalId): string => (string) $externalId,
            array_keys($prefilteredExternalIds),
        );

        $query = ProductSupplierReference::query()
            ->whereIn('external_id', $externalIds);

        if ($this->supportsSupplierEntityReference() && $supplierId !== null) {
            $query->where('supplier_id', $supplierId);
        } else {
            $query->where('supplier', $this->profile->supplierKey());
        }

        $query->update([
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

    private function supportsSupplierEntityReference(): bool
    {
        return Schema::hasTable('product_supplier_references')
            && Schema::hasColumn('product_supplier_references', 'supplier_id');
    }

    /**
     * @param  array<int, true>  $categoryFilterIds
     */
    private function passesCategoryFilter(YmlOfferRecord $record, array $categoryFilterIds): bool
    {
        if ($categoryFilterIds === []) {
            return true;
        }

        if ($record->categoryId === null) {
            return false;
        }

        return isset($categoryFilterIds[$record->categoryId]);
    }

    /**
     * @param  array<int, string>  $categories
     * @param  array<int, int|null>  $categoryParents
     * @return array<int, true>
     */
    private function resolveCategoryFilterIds(?int $selectedCategoryId, array $categories, array $categoryParents): array
    {
        if ($selectedCategoryId === null) {
            return [];
        }

        if (! isset($categories[$selectedCategoryId])) {
            return [$selectedCategoryId => true];
        }

        $childrenByParent = [];

        foreach ($categories as $categoryId => $_categoryName) {
            if (! is_int($categoryId) || $categoryId <= 0) {
                continue;
            }

            $parentId = $categoryParents[$categoryId] ?? null;

            if (
                ! is_int($parentId)
                || $parentId <= 0
                || $parentId === $categoryId
                || ! isset($categories[$parentId])
            ) {
                $parentId = null;
            }

            $childrenByParent[$parentId ?? 0][] = $categoryId;
        }

        $filterIds = [];
        $stack = [$selectedCategoryId];

        while ($stack !== []) {
            $currentId = array_pop($stack);

            if (! is_int($currentId) || $currentId <= 0 || isset($filterIds[$currentId])) {
                continue;
            }

            $filterIds[$currentId] = true;

            foreach ($childrenByParent[$currentId] ?? [] as $childId) {
                if (! isset($filterIds[$childId])) {
                    $stack[] = $childId;
                }
            }
        }

        return $filterIds;
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

    private function makeEventLogger(?int $runId): ?ImportRunEventLoggerInterface
    {
        if ($runId === null || $runId <= 0 || ! Schema::hasTable('import_run_events')) {
            return null;
        }

        return new DatabaseImportRunEventLogger;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logRunEvent(
        ?ImportRunEventLoggerInterface $logger,
        ?int $runId,
        string $stage,
        string $result,
        ?string $sourceRef = null,
        ?string $externalId = null,
        ?int $productId = null,
        ?int $sourceCategoryId = null,
        ?string $code = null,
        ?string $message = null,
        array $context = [],
    ): void {
        if (! $logger instanceof ImportRunEventLoggerInterface || $runId === null || $runId <= 0) {
            return;
        }

        $logger->log(new ImportRunEventData(
            runId: $runId,
            supplier: $this->profile->supplierKey(),
            stage: $stage,
            result: $result,
            sourceRef: $sourceRef,
            externalId: $externalId,
            productId: $productId,
            sourceCategoryId: $sourceCategoryId,
            code: $code,
            message: $message,
            context: $context,
        ));
    }

    /**
     * @param  array<int, ImportError>  $errors
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
