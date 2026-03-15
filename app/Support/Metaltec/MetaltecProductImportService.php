<?php

namespace App\Support\Metaltec;

use App\Models\ProductSupplierReference;
use App\Support\CatalogImport\Contracts\ImportRunEventLoggerInterface;
use App\Support\CatalogImport\Contracts\SourceResolverInterface;
use App\Support\CatalogImport\DTO\ImportError;
use App\Support\CatalogImport\DTO\ProductPayload;
use App\Support\CatalogImport\DTO\ResolvedSource;
use App\Support\CatalogImport\Processing\ProductImportProcessor;
use App\Support\CatalogImport\Runs\DatabaseImportRunEventLogger;
use App\Support\CatalogImport\Runs\ImportRunEventData;
use App\Support\CatalogImport\Sources\SourceResolver;
use App\Support\CatalogImport\Suppliers\Metaltec\MetaltecSupplierAdapter;
use App\Support\CatalogImport\Suppliers\Metaltec\MetaltecSupplierProfile;
use App\Support\CatalogImport\Xml\XmlRecord;
use App\Support\CatalogImport\Xml\XmlStreamParser;
use Illuminate\Support\Facades\Schema;
use SimpleXMLElement;
use Throwable;

class MetaltecProductImportService
{
    public function __construct(
        private readonly MetaltecSupplierAdapter $adapter,
        private readonly MetaltecSupplierProfile $profile,
        private readonly XmlStreamParser $recordParser,
        private readonly ProductImportProcessor $processor,
        private readonly SourceResolverInterface $sourceResolver = new SourceResolver,
    ) {}

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
                [$foundUrls, $prefilteredExternalIds] = $this->scanFeed($source->resolvedPath, $normalized);
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
                $this->emit($output, 'warn', 'Подходящие XML-записи не найдены.');
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
                    code: 'no_matching_items',
                    message: 'Не найдено XML item-записей, подходящих под текущие фильтры запуска.',
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

            $this->emit($output, 'info', 'Найдено XML-записей: '.$foundUrls);
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

            if ($prefilteredExternalIds !== [] && $normalized['run_id'] !== null) {
                $this->touchPrefilteredReferences($prefilteredExternalIds, $normalized['run_id'], $normalized['supplier_id']);
            }

            $this->emitProgress($progress, $this->makeProgressPayload(
                foundUrls: $foundUrls,
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

            $processedItems = 0;

            foreach ($this->recordParser->parse($source, ['record_node' => 'Item']) as $record) {
                if (! $record instanceof XmlRecord) {
                    continue;
                }

                if (! $this->passesCategoryFilter($record, $normalized['category_id'])) {
                    continue;
                }

                if ($normalized['limit'] > 0 && $processedItems >= $normalized['limit']) {
                    break;
                }

                $processedItems++;
                $externalId = $this->extractExternalId($record);
                $sourceRef = $this->sourceRef($externalId, $record);

                if (
                    $normalized['write']
                    && $normalized['skip_existing']
                    && $externalId !== null
                    && isset($prefilteredExternalIds[$externalId])
                ) {
                    $processed++;
                    $skipped++;

                    $this->logRunEvent(
                        logger: $eventLogger,
                        runId: $normalized['run_id'],
                        stage: 'prefilter',
                        result: 'skipped',
                        sourceRef: $sourceRef,
                        externalId: $externalId,
                        code: 'existing_reference',
                        message: 'XML item-запись пропущена на предфильтре: ссылка поставщика уже существует.',
                    );

                    $this->emit($output, 'line', 'SKIP: '.$sourceRef.' | existing product reference.');

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
                            'url' => $sourceRef,
                            'message' => $mapping->errors[0]->message ?? 'Не удалось сопоставить запись.',
                        ];

                        foreach ($mapping->errors as $mappingError) {
                            $this->logRunEvent(
                                logger: $eventLogger,
                                runId: $normalized['run_id'],
                                stage: 'mapping',
                                result: 'error',
                                sourceRef: $sourceRef,
                                externalId: $externalId,
                                code: $mappingError->code,
                                message: $mappingError->message,
                            );
                        }

                        $this->emit($output, 'error', 'ERR: '.$sourceRef.' | '.($mapping->errors[0]->message ?? 'Не удалось сопоставить запись.'));

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
                            $this->processorOptions($normalized, $eventLogger, $sourceRef),
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
                        $samples[] = $this->sampleRow($mapping->payload);
                        $processed++;
                    } else {
                        $processed++;
                    }

                    $this->emit($output, 'line', 'OK: '.$sourceRef);

                    if ($normalized['delay_ms'] > 0) {
                        usleep($normalized['delay_ms'] * 1000);
                    }
                } catch (Throwable $exception) {
                    $errors++;
                    $urlErrors[] = [
                        'url' => $sourceRef,
                        'message' => $exception->getMessage(),
                    ];

                    $this->logRunEvent(
                        logger: $eventLogger,
                        runId: $normalized['run_id'],
                        stage: 'runtime',
                        result: 'error',
                        sourceRef: $sourceRef,
                        externalId: $externalId,
                        code: 'record_exception',
                        message: 'Ошибка обработки XML item-записи: '.$exception->getMessage(),
                    );

                    $this->emit($output, 'error', 'ERR: '.$sourceRef.' | '.$exception->getMessage());
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

            if ($normalized['write'] && $normalized['run_id'] !== null) {
                $this->processor->finalizeMissing(
                    supplier: $this->profile->supplierKey(),
                    runId: $normalized['run_id'],
                    options: [
                        'supplier_id' => $normalized['supplier_id'],
                        'mode' => $normalized['mode'],
                        'finalize_missing' => $normalized['finalize_missing'],
                        'event_logger' => $eventLogger,
                    ],
                );
            }

            $this->emit($output, 'new_line', '');
            $this->emit($output, 'info', 'Итого: processed='.$processed.', errors='.$errors.'.');

            if (! $normalized['write'] && $samples !== []) {
                $this->emit($output, 'new_line', '');
                $this->emit($output, 'table', [
                    'headers' => ['external_id', 'name', 'price', 'currency', 'section'],
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
     * @return array<int, array{id: int, name: string, parent_id: int|null}>
     */
    public function listCategoryNodes(array $options = []): array
    {
        $normalized = $this->normalizeOptions($options);
        $source = $this->resolveSource($normalized);
        $categories = [];

        foreach ($this->recordParser->parse($source, ['record_node' => 'Item']) as $record) {
            if (! $record instanceof XmlRecord) {
                continue;
            }

            $section = $this->extractSection($record);
            $categoryId = $this->profile->categoryIdForSection($section);

            if ($section === null || $categoryId === null || isset($categories[$categoryId])) {
                continue;
            }

            $categories[$categoryId] = [
                'id' => $categoryId,
                'name' => $section,
                'parent_id' => null,
            ];
        }

        return array_values($categories);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{
     *     source: string,
     *     supplier_id: int|null,
     *     category_id: int|null,
     *     timeout: int,
     *     limit: int,
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
        $runId = $options['run_id'] ?? $options['run-id'] ?? null;
        $skipExisting = $this->normalizeBoolOption(
            $options['skip_existing'] ?? $options['skip-existing'] ?? false,
            false,
        );
        $mode = $this->normalizeMode($options['mode'] ?? null);

        return [
            'source' => trim((string) ($options['source'] ?? $this->profile->defaultSourceUrl())),
            'supplier_id' => $this->normalizeNullableInt($options['supplier_id'] ?? $options['supplier-id'] ?? null),
            'category_id' => $this->normalizeNullableInt($options['category_id'] ?? $options['category-id'] ?? null),
            'timeout' => max(1, (int) ($options['timeout'] ?? 25)),
            'limit' => max(0, (int) ($options['limit'] ?? 0)),
            'delay_ms' => max(0, (int) ($options['delay_ms'] ?? $options['delay-ms'] ?? 0)),
            'write' => $this->normalizeBoolOption($options['write'] ?? false, false),
            'publish' => $this->normalizeBoolOption($options['publish'] ?? false, false),
            'download_images' => $this->normalizeBoolOption(
                $options['download_images'] ?? $options['download-images'] ?? true,
                true,
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
        ];
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

    /**
     * @param  array<string, mixed>  $normalized
     * @return array{0: int, 1: array<string, true>}
     */
    private function scanFeed(string $sourcePath, array $normalized): array
    {
        $foundUrls = 0;
        $candidateExternalIds = [];

        foreach ($this->recordParser->parse(
            new ResolvedSource(source: $sourcePath, resolvedPath: $sourcePath),
            ['record_node' => 'Item'],
        ) as $record) {
            if (! $record instanceof XmlRecord) {
                continue;
            }

            if (! $this->passesCategoryFilter($record, $normalized['category_id'])) {
                continue;
            }

            if ($normalized['limit'] > 0 && $foundUrls >= $normalized['limit']) {
                break;
            }

            $foundUrls++;

            $externalId = $this->extractExternalId($record);

            if ($externalId !== null) {
                $candidateExternalIds[$externalId] = true;
            }
        }

        if (! $normalized['write'] || ! $normalized['skip_existing'] || $candidateExternalIds === []) {
            return [$foundUrls, []];
        }

        $prefiltered = [];
        $useSupplierEntityReference = $this->supportsSupplierEntityReference() && $normalized['supplier_id'] !== null;

        foreach (array_chunk(array_keys($candidateExternalIds), 1000) as $chunk) {
            $query = ProductSupplierReference::query()
                ->whereIn('external_id', $chunk);

            if ($useSupplierEntityReference) {
                $query->where('supplier_id', $normalized['supplier_id']);
            } else {
                $query->where('supplier', $this->profile->supplierKey());
            }

            foreach ($query->pluck('external_id') as $externalId) {
                if (is_string($externalId) && $externalId !== '') {
                    $prefiltered[$externalId] = true;
                }
            }
        }

        return [$foundUrls, $prefiltered];
    }

    private function extractExternalId(XmlRecord $record): ?string
    {
        if (preg_match('/<ID>\s*([^<]+?)\s*<\/ID>/u', $record->xml, $matches) !== 1) {
            return null;
        }

        $externalId = trim(html_entity_decode((string) ($matches[1] ?? ''), ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8'));

        return $externalId !== '' ? $externalId : null;
    }

    private function sourceRef(?string $externalId, XmlRecord $record): string
    {
        return $externalId !== null
            ? 'item:'.$externalId
            : 'item#'.($record->index + 1);
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
            'category_id' => $normalized['category_id'],
            'run_id' => $normalized['run_id'],
            'create_missing' => $normalized['create_missing'],
            'update_existing' => $normalized['update_existing'] && ! $normalized['skip_existing'],
            'publish_created' => $normalized['publish'],
            'publish_updated' => $normalized['publish'],
            'download_media' => $normalized['download_images'],
            'force_media_recheck' => $normalized['force_media_recheck'],
            'legacy_match' => $this->profile->defaults()['legacy_match'] ?? null,
            'use_source_slug' => false,
            'mode' => $normalized['mode'],
            'event_logger' => $eventLogger,
            'source_ref' => $sourceRef,
            'preserve_missing_price_on_update' => true,
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

        $query = ProductSupplierReference::query()
            ->whereIn('external_id', array_keys($prefilteredExternalIds));

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

    private function passesCategoryFilter(XmlRecord $record, ?int $selectedCategoryId): bool
    {
        if ($selectedCategoryId === null) {
            return true;
        }

        return $this->extractCategoryId($record) === $selectedCategoryId;
    }

    private function extractCategoryId(XmlRecord $record): ?int
    {
        return $this->profile->categoryIdForSection($this->extractSection($record));
    }

    private function extractSection(XmlRecord $record): ?string
    {
        $item = $this->loadItemXml($record->xml);

        if (! $item instanceof SimpleXMLElement) {
            return null;
        }

        return $this->profile->normalizeSection($this->xmlText($item->{'Раздел'} ?? null));
    }

    private function loadItemXml(string $xml): ?SimpleXMLElement
    {
        $xml = trim($xml);

        if ($xml === '') {
            return null;
        }

        $previousState = libxml_use_internal_errors(true);

        try {
            $item = simplexml_load_string($xml);

            return $item instanceof SimpleXMLElement ? $item : null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousState);
        }
    }

    private function xmlText(mixed $value): ?string
    {
        if ($value instanceof SimpleXMLElement) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function supportsSupplierEntityReference(): bool
    {
        return Schema::hasTable('product_supplier_references')
            && Schema::hasColumn('product_supplier_references', 'supplier_id');
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

    private function normalizeNullableInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
            $parsed = (int) trim($value);

            return $parsed > 0 ? $parsed : null;
        }

        return null;
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
     * @return array{external_id: string, name: string, price: string, currency: string, section: string}
     */
    private function sampleRow(ProductPayload $payload): array
    {
        return [
            'external_id' => $payload->externalId,
            'name' => $payload->name,
            'price' => (string) ($payload->priceAmount ?? 0),
            'currency' => (string) ($payload->currency ?? ''),
            'section' => (string) ($payload->source['section'] ?? ''),
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
