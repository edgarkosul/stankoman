<?php

namespace App\Support\CatalogImport\Processing;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductSupplierReference;
use App\Support\CatalogImport\Contracts\ImportProcessorInterface;
use App\Support\CatalogImport\Contracts\ImportRunEventLoggerInterface;
use App\Support\CatalogImport\DTO\ImportError;
use App\Support\CatalogImport\DTO\ImportProcessResult;
use App\Support\CatalogImport\DTO\ProductPayload;
use App\Support\CatalogImport\Enums\ImportErrorLevel;
use App\Support\CatalogImport\Media\ProductImportMediaService;
use App\Support\CatalogImport\Runs\ImportRunEventData;
use App\Support\CatalogImport\Suppliers\SupplierEntityResolver;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

final class ProductImportProcessor implements ImportProcessorInterface
{
    /**
     * @var array<int, string>
     */
    private const EVENT_CONTEXT_PRODUCT_FIELDS = [
        'name',
        'slug',
        'sku',
        'brand',
        'country',
        'price_amount',
        'discount_price',
        'currency',
        'in_stock',
        'qty',
        'is_active',
        'is_in_yml_feed',
        'with_dns',
        'image',
        'thumb',
        'gallery',
        'promo_info',
    ];

    /**
     * @var array<int, string>
     */
    private const EVENT_CONTEXT_JSON_FIELDS = [
        'gallery',
        'specs',
    ];

    /**
     * @var array<int, string>
     */
    private const DEFERRED_MEDIA_EVENT_FIELDS = [
        'image',
        'thumb',
        'gallery',
    ];

    private bool $stagingCategoryResolved = false;

    private ?int $stagingCategoryId = null;

    private ?bool $sourceCategoryReferenceSupported = null;

    private ?bool $supplierEntityReferenceSupported = null;

    public function __construct(
        private readonly ProductPayloadNormalizer $normalizer = new ProductPayloadNormalizer,
        private readonly ProductImportMediaService $mediaService = new ProductImportMediaService,
        private readonly SupplierEntityResolver $supplierResolver = new SupplierEntityResolver,
    ) {}

    public function process(ProductPayload $payload, array $options = []): ImportProcessResult
    {
        $summary = $this->processBatch([$payload], $options);

        return $summary['results'][0] ?? new ImportProcessResult(
            operation: 'skipped',
            errors: [
                new ImportError(
                    code: 'empty_batch',
                    message: 'Не переданы данные товаров для обработки.',
                    level: ImportErrorLevel::Fatal,
                ),
            ],
        );
    }

    /**
     * @param  iterable<int, ProductPayload>  $payloads
     * @param  array<string, mixed>  $options
     * @return array{processed:int,created:int,updated:int,skipped:int,errors:int,results:array<int, ImportProcessResult>}
     */
    public function processBatch(iterable $payloads, array $options = []): array
    {
        $items = [];

        foreach ($payloads as $payload) {
            if ($payload instanceof ProductPayload) {
                $items[] = $payload;
            }
        }

        if ($items === []) {
            return $this->emptySummary();
        }

        $supplier = $this->normalizeSupplier($options['supplier'] ?? null);

        if ($supplier === null) {
            return $this->summaryFromFatalSupplierError(count($items));
        }

        $runId = $this->normalizeRunId($options['run_id'] ?? null);
        $batchSize = $this->normalizeBatchSize($options['batch_size'] ?? null);
        $resolvedSupplierId = $this->resolveReferenceSupplierId($options, $supplier);

        $summary = $this->emptySummary();

        foreach (array_chunk($items, $batchSize) as $chunk) {
            $chunkSummary = $this->processChunk($chunk, $supplier, $runId, $options, $resolvedSupplierId);

            $summary['processed'] += $chunkSummary['processed'];
            $summary['created'] += $chunkSummary['created'];
            $summary['updated'] += $chunkSummary['updated'];
            $summary['skipped'] += $chunkSummary['skipped'];
            $summary['errors'] += $chunkSummary['errors'];
            $summary['results'] = array_merge($summary['results'], $chunkSummary['results']);
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{checked:int,deactivated:int,skipped:bool}
     */
    public function finalizeMissing(string $supplier, int $runId, array $options = []): array
    {
        $normalizedSupplier = $this->normalizeSupplier($supplier);
        $eventLogger = $this->resolveEventLogger($options);
        $sourceCategoryId = $this->positiveIntOrNull(
            $options['source_category_id'] ?? $options['category_id'] ?? null,
        );
        $resolvedSupplierId = $this->resolveReferenceSupplierId($options, $normalizedSupplier);
        $useSupplierEntityReference = $this->supportsSupplierEntityReference() && $resolvedSupplierId !== null;

        if ($normalizedSupplier === null || $runId <= 0) {
            return [
                'checked' => 0,
                'deactivated' => 0,
                'skipped' => true,
            ];
        }

        $isFullSync = ($options['mode'] ?? 'partial_import') === 'full_sync_authoritative';
        $finalizeMissing = ($options['finalize_missing'] ?? true) === true;
        $missingStrategy = (string) ($options['missing_strategy'] ?? 'deactivate');

        if ($sourceCategoryId !== null && ! $this->supportsSourceCategoryReference()) {
            $this->logEvent(
                logger: $eventLogger,
                runId: $runId,
                supplier: $normalizedSupplier,
                stage: 'finalize',
                result: 'skipped',
                sourceCategoryId: $sourceCategoryId,
                code: 'source_category_not_supported',
                message: 'Ограничение по категории источника не поддерживается текущей схемой.',
            );

            return [
                'checked' => 0,
                'deactivated' => 0,
                'skipped' => true,
            ];
        }

        $referenceQuery = ProductSupplierReference::query();

        if ($useSupplierEntityReference) {
            $referenceQuery->where('supplier_id', $resolvedSupplierId);
        } else {
            $referenceQuery->where('supplier', $normalizedSupplier);
        }

        if ($sourceCategoryId !== null) {
            $referenceQuery->where('source_category_id', $sourceCategoryId);
        }

        $productIds = $referenceQuery
            ->where(function ($query) use ($runId): void {
                $query->whereNull('last_seen_run_id')
                    ->orWhere('last_seen_run_id', '!=', $runId);
            })
            ->pluck('product_id')
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        $checked = $productIds->count();

        if (! $isFullSync || ! $finalizeMissing || $missingStrategy !== 'deactivate') {
            $this->logEvent(
                logger: $eventLogger,
                runId: $runId,
                supplier: $normalizedSupplier,
                stage: 'finalize',
                result: 'skipped',
                sourceCategoryId: $sourceCategoryId,
                code: ! $isFullSync
                    ? 'mode_not_full_sync'
                    : (! $finalizeMissing ? 'finalize_missing_disabled' : 'missing_strategy_not_supported'),
                message: 'Финализация отсутствующих товаров не активна для текущих параметров синхронизации.',
                context: [
                    'checked' => $checked,
                    'mode' => $options['mode'] ?? null,
                    'missing_strategy' => $missingStrategy,
                ],
            );

            return [
                'checked' => $checked,
                'deactivated' => 0,
                'skipped' => true,
            ];
        }

        if ($checked === 0) {
            $this->logEvent(
                logger: $eventLogger,
                runId: $runId,
                supplier: $normalizedSupplier,
                stage: 'finalize',
                result: 'skipped',
                sourceCategoryId: $sourceCategoryId,
                code: 'nothing_to_finalize',
                message: 'В текущей области запуска нет ранее известных отсутствующих товаров.',
            );

            return [
                'checked' => 0,
                'deactivated' => 0,
                'skipped' => false,
            ];
        }

        $deactivated = Product::query()
            ->whereIn('id', $productIds)
            ->update([
                'is_active' => false,
                'in_stock' => false,
                'qty' => 0,
                'updated_at' => now(),
            ]);

        if ($deactivated > 0) {
            $events = $productIds
                ->map(fn (int $productId): ImportRunEventData => new ImportRunEventData(
                    runId: $runId,
                    supplier: $normalizedSupplier,
                    stage: 'finalize',
                    result: 'deactivated',
                    productId: $productId,
                    sourceCategoryId: $sourceCategoryId,
                    code: 'missing_in_feed',
                    message: 'Товар деактивирован, так как отсутствует в текущем полном синхронизируемом фиде.',
                ))
                ->all();

            $eventLogger?->logMany($events);
        }

        return [
            'checked' => $checked,
            'deactivated' => $deactivated,
            'skipped' => false,
        ];
    }

    /**
     * @param  array<int, ProductPayload>  $payloads
     * @param  array<string, mixed>  $options
     * @return array{processed:int,created:int,updated:int,skipped:int,errors:int,results:array<int, ImportProcessResult>}
     */
    private function processChunk(array $payloads, string $supplier, ?int $runId, array $options, ?int $supplierId = null): array
    {
        $normalizedItems = [];
        $summary = $this->emptySummary();
        $eventLogger = $this->resolveEventLogger($options);
        $defaultSourceRef = $this->normalizeSourceRef($options['source_ref'] ?? null);
        $queueMedia = ($options['download_media'] ?? false) === true;
        $forceMediaRecheck = ($options['force_media_recheck'] ?? false) === true;
        $pendingMediaIds = [];
        $useSupplierEntityReference = $this->supportsSupplierEntityReference() && $supplierId !== null;

        foreach ($payloads as $payload) {
            $normalized = $this->normalizer->normalize($payload);
            $errors = $this->validatePayload($normalized);

            if ($errors !== []) {
                $summary['processed']++;
                $summary['skipped']++;
                $summary['errors'] += $this->countErrors($errors);
                $summary['results'][] = new ImportProcessResult(
                    operation: 'skipped',
                    errors: $errors,
                    meta: ['external_id' => $normalized->externalId],
                );

                foreach ($errors as $error) {
                    $this->logEvent(
                        logger: $eventLogger,
                        runId: $runId,
                        supplier: $supplier,
                        stage: 'processing',
                        result: 'error',
                        sourceRef: $this->sourceString($normalized, 'source_ref')
                            ?? $this->sourceString($normalized, 'url')
                            ?? $defaultSourceRef,
                        externalId: $normalized->externalId,
                        sourceCategoryId: $this->positiveIntOrNull($normalized->source['category_id'] ?? null),
                        code: $error->code,
                        message: $error->message,
                    );
                }

                continue;
            }

            $normalizedItems[] = $normalized;
        }

        if ($normalizedItems === []) {
            return $summary;
        }

        $externalIds = array_values(array_unique(array_map(
            fn (ProductPayload $payload): string => $payload->externalId,
            $normalizedItems,
        )));

        /** @var Collection<string, ProductSupplierReference> $references */
        $referenceQuery = ProductSupplierReference::query()
            ->with('product')
            ->whereIn('external_id', $externalIds);

        if ($useSupplierEntityReference) {
            $referenceQuery->where('supplier_id', $supplierId);
        } else {
            $referenceQuery->where('supplier', $supplier);
        }

        $references = $referenceQuery->get()->keyBy('external_id');

        $canCreate = ($options['create_missing'] ?? true) === true;
        $canUpdate = ($options['update_existing'] ?? true) === true;

        /** @var ConnectionInterface $connection */
        $connection = Product::query()->getConnection();
        $supportsSourceCategoryReference = $this->supportsSourceCategoryReference();

        $connection->transaction(function () use (
            $normalizedItems,
            $supplier,
            $runId,
            $references,
            $supplierId,
            $useSupplierEntityReference,
            $canCreate,
            $canUpdate,
            $queueMedia,
            $forceMediaRecheck,
            $options,
            $supportsSourceCategoryReference,
            $eventLogger,
            $defaultSourceRef,
            &$pendingMediaIds,
            &$summary,
        ): void {
            $referenceUpserts = [];
            $now = now();

            foreach ($normalizedItems as $payload) {
                $reference = $references->get($payload->externalId);
                $product = $reference?->product;

                if (! $product instanceof Product) {
                    $reference = null;
                    $product = $this->resolveLegacyProduct($payload, $options);
                }

                $operation = 'skipped';
                $errors = [];
                $skipCode = null;
                $mediaQueueStats = [
                    'queued' => 0,
                    'reused' => 0,
                    'deduplicated' => 0,
                ];
                $changedAttributes = [];
                $otherChangedFields = [];
                $deferredChangedFields = [];
                $createdSnapshot = null;

                if ($product instanceof Product) {
                    if ($canUpdate) {
                        $attributes = $this->buildProductAttributes($payload, $options, isNew: false);
                        $attributes = $this->sanitizeExistingProductUpdateAttributes(
                            attributes: $attributes,
                            payload: $payload,
                            queueMedia: $queueMedia,
                            hasPayloadImages: $payload->images !== [],
                            preserveMissingPrice: ($options['preserve_missing_price_on_update'] ?? false) === true,
                        );

                        $product->fill($attributes);

                        if ($product->isDirty()) {
                            $dirtyAttributes = $product->getDirty();
                            $changeContext = $this->buildChangedAttributesContext($product, $dirtyAttributes);
                            $changedAttributes = $changeContext['changes'];
                            $otherChangedFields = $changeContext['other_changed_fields'];

                            $deferredChangedFields = $this->resolveDeferredMediaEventFields(
                                changedAttributes: $changedAttributes,
                                queueMedia: $queueMedia,
                                hasPayloadImages: $payload->images !== [],
                            );

                            if ($deferredChangedFields !== []) {
                                $changedAttributes = array_diff_key(
                                    $changedAttributes,
                                    array_flip($deferredChangedFields),
                                );
                            }

                            $product->save();

                            $operation = 'updated';
                            $summary['updated']++;
                        } else {
                            $operation = 'unchanged';
                            $skipCode = 'unchanged';
                            $summary['skipped']++;
                        }
                    } else {
                        $operation = 'skipped';
                        $skipCode = 'update_disabled';
                        $summary['skipped']++;
                    }
                } elseif ($canCreate) {
                    $product = Product::query()->create(
                        $this->buildProductAttributes($payload, $options, isNew: true),
                    );

                    $this->attachToStagingCategory($product);
                    $createdSnapshot = $this->buildProductContextSnapshot($product);

                    $operation = 'created';
                    $summary['created']++;
                } else {
                    $operation = 'skipped';
                    $skipCode = 'create_disabled';
                    $summary['skipped']++;
                }

                $resolvedSourceCategoryId = null;

                if ($product instanceof Product) {
                    if ($queueMedia && $payload->images !== []) {
                        $mediaQueueResult = $this->mediaService->enqueueProductMedia(
                            product: $product,
                            sourceUrls: $payload->images,
                            runId: $runId,
                            forceRecheck: $forceMediaRecheck,
                        );

                        $pendingMediaIds = array_merge($pendingMediaIds, $mediaQueueResult['pending_media_ids']);

                        $mediaQueueStats = [
                            'queued' => (int) ($mediaQueueResult['queued'] ?? 0),
                            'reused' => (int) ($mediaQueueResult['reused'] ?? 0),
                            'deduplicated' => (int) ($mediaQueueResult['deduplicated'] ?? 0),
                        ];
                    }

                    $referenceUpsert = [
                        'supplier' => $supplier,
                        'external_id' => $payload->externalId,
                        'product_id' => $product->id,
                        'first_seen_run_id' => $reference?->first_seen_run_id ?? $runId,
                        'last_seen_run_id' => $runId,
                        'last_seen_at' => $now,
                        'created_at' => $reference?->created_at ?? $now,
                        'updated_at' => $now,
                    ];

                    if ($useSupplierEntityReference) {
                        $referenceUpsert['supplier_id'] = $supplierId;
                    }

                    if ($supportsSourceCategoryReference) {
                        $resolvedSourceCategoryId = $this->resolveSourceCategoryId($payload, $reference);
                        $referenceUpsert['source_category_id'] = $resolvedSourceCategoryId;
                    }

                    $referenceUpserts[] = $referenceUpsert;
                }

                $summary['processed']++;
                $summary['errors'] += $this->countErrors($errors);
                $summary['results'][] = new ImportProcessResult(
                    operation: $operation,
                    errors: $errors,
                    meta: [
                        'external_id' => $payload->externalId,
                        'product_id' => $product?->id,
                        'media_queued' => $mediaQueueStats['queued'],
                        'media_reused' => $mediaQueueStats['reused'],
                        'media_deduplicated' => $mediaQueueStats['deduplicated'],
                    ],
                );

                $this->logEvent(
                    logger: $eventLogger,
                    runId: $runId,
                    supplier: $supplier,
                    stage: 'processing',
                    result: $operation,
                    sourceRef: $this->sourceString($payload, 'source_ref')
                        ?? $this->sourceString($payload, 'url')
                        ?? $defaultSourceRef,
                    externalId: $payload->externalId,
                    productId: $product?->id,
                    sourceCategoryId: $resolvedSourceCategoryId,
                    code: in_array($operation, ['skipped', 'unchanged'], true) ? $skipCode : null,
                    message: in_array($operation, ['skipped', 'unchanged'], true) ? $this->skippedMessage($skipCode) : null,
                    context: $this->buildProcessingEventContext(
                        operation: $operation,
                        mediaQueueStats: $mediaQueueStats,
                        createdSnapshot: $createdSnapshot,
                        changedAttributes: $changedAttributes,
                        otherChangedFields: $otherChangedFields,
                        deferredChangedFields: $deferredChangedFields,
                    ),
                );
            }

            if ($referenceUpserts !== []) {
                $updateColumns = ['product_id', 'last_seen_run_id', 'last_seen_at', 'updated_at'];

                if ($useSupplierEntityReference) {
                    $updateColumns[] = 'supplier_id';
                }

                if ($supportsSourceCategoryReference) {
                    $updateColumns[] = 'source_category_id';
                }

                ProductSupplierReference::query()->upsert(
                    values: $referenceUpserts,
                    uniqueBy: $useSupplierEntityReference
                        ? ['supplier_id', 'external_id']
                        : ['supplier', 'external_id'],
                    update: $updateColumns,
                );
            }
        });

        if ($queueMedia && $pendingMediaIds !== []) {
            $this->mediaService->dispatchPendingMedia($pendingMediaIds);
        }

        return $summary;
    }

    /**
     * @return array<int, ImportError>
     */
    private function validatePayload(ProductPayload $payload): array
    {
        $errors = [];

        if ($payload->externalId === '') {
            $errors[] = new ImportError(
                code: 'missing_external_id',
                message: 'Поле external_id в данных товара должно быть непустым.',
            );
        }

        if ($payload->name === '') {
            $errors[] = new ImportError(
                code: 'missing_name',
                message: 'Поле name в данных товара должно быть непустым.',
            );
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function buildProductAttributes(ProductPayload $payload, array $options, bool $isNew): array
    {
        $description = $this->normalizeDescription($payload->description);
        $sourceSlug = $this->sourceString($payload, 'slug');

        $attributes = [
            'name' => $this->limit($payload->name, 255) ?? 'Imported product',
            'title' => $this->limit($payload->title, 255) ?? $this->limit($payload->name, 255),
            'sku' => $this->limit($payload->sku, 255),
            'brand' => $this->limit($payload->brand, 255),
            'country' => $this->limit($payload->country, 255),
            'price_amount' => $payload->priceAmount ?? 0,
            'discount_price' => $payload->discountPrice,
            'currency' => $payload->currency ?? 'RUB',
            'in_stock' => $this->resolveInStock($payload->inStock, $payload->qty),
            'qty' => $payload->qty,
            'short' => $this->limit($payload->short, 1000),
            'description' => $description,
            'extra_description' => $this->normalizeDescription($payload->extraDescription),
            'specs' => $this->normalizeSpecs($payload->attributes),
            'promo_info' => $this->limit($payload->promoInfo, 255),
            'image' => $payload->images[0] ?? null,
            'thumb' => $payload->images[0] ?? null,
            'gallery' => $payload->images === [] ? null : $payload->images,
            'meta_title' => $this->limit($payload->metaTitle, 255) ?? $this->limit($payload->name, 255),
            'meta_description' => $this->limit($payload->metaDescription, 255) ?? $this->metaDescriptionFromText($description),
        ];

        if ($isNew) {
            if ($sourceSlug !== null && ($options['use_source_slug'] ?? false) === true) {
                $attributes['slug'] = $sourceSlug;
            }

            $attributes['is_active'] = ($options['publish_created'] ?? true) === true;
            $attributes['is_in_yml_feed'] = ($options['is_in_yml_feed'] ?? true) === true;
            $attributes['with_dns'] = ($options['with_dns'] ?? true) === true;
        }

        return $attributes;
    }

    private function resolveInStock(?bool $inStock, ?int $qty): bool
    {
        if ($inStock !== null) {
            return $inStock;
        }

        if ($qty !== null) {
            return $qty > 0;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<int, array{name:string,value:string,source:string}>|null
     */
    private function normalizeSpecs(array $attributes): ?array
    {
        if ($attributes === []) {
            return null;
        }

        $normalized = [];

        if (! array_is_list($attributes)) {
            foreach ($attributes as $name => $value) {
                if (! is_string($name)) {
                    continue;
                }

                if (! is_scalar($value)) {
                    continue;
                }

                $specName = $this->limit($name, 255);
                $specValue = $this->limit((string) $value, 1000);

                if ($specName === null || $specValue === null) {
                    continue;
                }

                $key = mb_strtolower($specName.'::'.$specValue);

                if (isset($normalized[$key])) {
                    continue;
                }

                $normalized[$key] = [
                    'name' => $specName,
                    'value' => $specValue,
                    'source' => 'import',
                ];
            }

            return $normalized === [] ? null : array_values($normalized);
        }

        foreach ($attributes as $row) {
            if (! is_array($row)) {
                continue;
            }

            $specName = $this->limit($row['name'] ?? null, 255);
            $specValue = $this->limit($row['value'] ?? null, 1000);
            $specSource = $this->limit($row['source'] ?? null, 64) ?? 'import';

            if ($specName === null || $specValue === null) {
                continue;
            }

            $key = mb_strtolower($specName.'::'.$specValue);

            if (isset($normalized[$key])) {
                continue;
            }

            $normalized[$key] = [
                'name' => $specName,
                'value' => $specValue,
                'source' => $specSource,
            ];
        }

        return $normalized === [] ? null : array_values($normalized);
    }

    private function normalizeDescription(?string $description): ?string
    {
        if (! is_string($description)) {
            return null;
        }

        $description = trim($description);

        if ($description === '') {
            return null;
        }

        if (Str::startsWith($description, '<')) {
            return $description;
        }

        $escaped = htmlspecialchars($description, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<p>'.$escaped.'</p>';
    }

    private function metaDescriptionFromText(?string $description): ?string
    {
        if (! is_string($description) || trim($description) === '') {
            return null;
        }

        $plain = strip_tags($description);
        $plain = preg_replace('/\s+/u', ' ', $plain) ?? $plain;
        $plain = trim($plain);

        if ($plain === '') {
            return null;
        }

        return (string) Str::limit($plain, 255, '');
    }

    private function limit(mixed $value, int $length): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        return (string) Str::limit($value, $length, '');
    }

    private function attachToStagingCategory(Product $product): void
    {
        $categoryId = $this->resolveStagingCategoryId();

        if ($categoryId === null) {
            return;
        }

        try {
            $product->categories()->syncWithoutDetaching([$categoryId]);
        } catch (Throwable) {
            return;
        }
    }

    private function resolveStagingCategoryId(): ?int
    {
        if ($this->stagingCategoryResolved) {
            return $this->stagingCategoryId;
        }

        $this->stagingCategoryResolved = true;

        if (! Schema::hasTable('categories') || ! Schema::hasTable('product_categories')) {
            return null;
        }

        $slug = Category::stagingSlug();
        $categoryId = $this->findStagingCategoryId($slug);

        if ($categoryId === null) {
            $categoryId = $this->createStagingCategory($slug);
        }

        $this->stagingCategoryId = $categoryId;

        return $this->stagingCategoryId;
    }

    private function findStagingCategoryId(string $slug): ?int
    {
        $query = Category::query()->where('slug', $slug);

        if (Schema::hasColumn('categories', 'parent_id')) {
            $query->where('parent_id', Category::defaultParentKey());
        }

        $id = $query->value('id');

        if (! is_numeric($id)) {
            return null;
        }

        return (int) $id;
    }

    private function createStagingCategory(string $slug): ?int
    {
        try {
            $payload = [
                'name' => Category::stagingName(),
                'slug' => $slug,
            ];

            if (Schema::hasColumn('categories', 'parent_id')) {
                $payload['parent_id'] = Category::defaultParentKey();
            }

            if (Schema::hasColumn('categories', 'order')) {
                $maxOrder = Category::query()
                    ->when(
                        Schema::hasColumn('categories', 'parent_id'),
                        fn ($query) => $query->where('parent_id', Category::defaultParentKey()),
                    )
                    ->max('order');

                $payload['order'] = (int) $maxOrder + 1;
            }

            if (Schema::hasColumn('categories', 'is_active')) {
                $payload['is_active'] = true;
            }

            Category::query()->create($payload);
        } catch (Throwable) {
            return $this->findStagingCategoryId($slug);
        }

        return $this->findStagingCategoryId($slug);
    }

    private function supportsSourceCategoryReference(): bool
    {
        if ($this->sourceCategoryReferenceSupported !== null) {
            return $this->sourceCategoryReferenceSupported;
        }

        $this->sourceCategoryReferenceSupported = Schema::hasTable('product_supplier_references')
            && Schema::hasColumn('product_supplier_references', 'source_category_id');

        return $this->sourceCategoryReferenceSupported;
    }

    private function supportsSupplierEntityReference(): bool
    {
        if ($this->supplierEntityReferenceSupported !== null) {
            return $this->supplierEntityReferenceSupported;
        }

        $this->supplierEntityReferenceSupported = Schema::hasTable('product_supplier_references')
            && Schema::hasColumn('product_supplier_references', 'supplier_id');

        return $this->supplierEntityReferenceSupported;
    }

    private function resolveReferenceSupplierId(array $options, ?string $supplier): ?int
    {
        if (! $this->supportsSupplierEntityReference()) {
            return null;
        }

        return $this->supplierResolver->resolveId(
            supplierId: $options['supplier_id'] ?? null,
            importSupplier: $supplier,
        );
    }

    private function resolveSourceCategoryId(ProductPayload $payload, ?ProductSupplierReference $reference): ?int
    {
        $fromPayload = $this->positiveIntOrNull($payload->source['category_id'] ?? null);

        if ($fromPayload !== null) {
            return $fromPayload;
        }

        return $this->positiveIntOrNull($reference?->getAttribute('source_category_id'));
    }

    private function positiveIntOrNull(mixed $value): ?int
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

    private function normalizeSupplier(mixed $supplier): ?string
    {
        if (! is_string($supplier)) {
            return null;
        }

        $supplier = trim($supplier);

        if ($supplier === '') {
            return null;
        }

        $supplier = preg_replace('/\s+/u', '_', mb_strtolower($supplier)) ?? $supplier;

        return $supplier;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function resolveLegacyProduct(ProductPayload $payload, array $options): ?Product
    {
        $legacyMatch = $this->legacyMatchMode($options['legacy_match'] ?? null);

        if ($legacyMatch === null) {
            return null;
        }

        if ($legacyMatch === 'slug') {
            $slug = $this->sourceString($payload, 'slug');

            if ($slug === null) {
                return null;
            }

            return Product::query()->where('slug', $slug)->first();
        }

        $name = $this->limit($payload->name, 255);

        if ($name === null) {
            return null;
        }

        $brand = $this->limit($payload->brand, 255);

        return Product::query()
            ->where('name', $name)
            ->when(
                $brand !== null,
                fn ($query) => $query->where('brand', $brand),
                fn ($query) => $query->whereNull('brand')
            )
            ->first();
    }

    private function legacyMatchMode(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = mb_strtolower(trim($value));

        return in_array($normalized, ['name_brand', 'slug'], true) ? $normalized : null;
    }

    private function sourceString(ProductPayload $payload, string $key): ?string
    {
        $value = $payload->source[$key] ?? null;

        return $this->limit($value, 255);
    }

    private function normalizeRunId(mixed $runId): ?int
    {
        if (is_int($runId)) {
            return $runId > 0 ? $runId : null;
        }

        if (is_string($runId) && preg_match('/^[0-9]+$/', $runId) === 1) {
            $parsed = (int) $runId;

            return $parsed > 0 ? $parsed : null;
        }

        return null;
    }

    private function normalizeBatchSize(mixed $batchSize): int
    {
        if (is_int($batchSize)) {
            return $batchSize > 0 ? $batchSize : 250;
        }

        if (is_string($batchSize) && preg_match('/^[0-9]+$/', $batchSize) === 1) {
            $parsed = (int) $batchSize;

            return $parsed > 0 ? $parsed : 250;
        }

        return 250;
    }

    private function skippedMessage(?string $skipCode): ?string
    {
        return match ($skipCode) {
            'update_disabled' => 'Обновление существующих товаров отключено параметрами импорта.',
            'create_disabled' => 'Создание отсутствующих товаров отключено параметрами импорта.',
            'unchanged' => 'Изменений атрибутов товара не обнаружено.',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function resolveEventLogger(array $options): ?ImportRunEventLoggerInterface
    {
        $candidate = $options['event_logger'] ?? null;

        return $candidate instanceof ImportRunEventLoggerInterface ? $candidate : null;
    }

    private function normalizeSourceRef(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    /**
     * @param  array{queued:int,reused:int,deduplicated:int}  $mediaQueueStats
     * @param  array<string, mixed>|null  $createdSnapshot
     * @param  array<string, array{before:mixed,after:mixed}>  $changedAttributes
     * @param  array<int, string>  $otherChangedFields
     * @param  array<int, string>  $deferredChangedFields
     * @return array<string, mixed>
     */
    private function buildProcessingEventContext(
        string $operation,
        array $mediaQueueStats,
        ?array $createdSnapshot = null,
        array $changedAttributes = [],
        array $otherChangedFields = [],
        array $deferredChangedFields = [],
    ): array {
        $context = [
            'media' => [
                'queued' => $mediaQueueStats['queued'],
                'reused' => $mediaQueueStats['reused'],
                'deduplicated' => $mediaQueueStats['deduplicated'],
            ],
            'media_queued' => $mediaQueueStats['queued'],
            'media_reused' => $mediaQueueStats['reused'],
            'media_deduplicated' => $mediaQueueStats['deduplicated'],
        ];

        if ($operation === 'created' && is_array($createdSnapshot) && $createdSnapshot !== []) {
            $context['created'] = $createdSnapshot;
        }

        if ($operation === 'updated' && $changedAttributes !== []) {
            $context['changes'] = $changedAttributes;
        }

        if ($operation === 'updated' && $otherChangedFields !== []) {
            $context['other_changed_fields'] = array_values($otherChangedFields);
        }

        if ($operation === 'updated' && $deferredChangedFields !== []) {
            $context['deferred_changes'] = array_values($deferredChangedFields);
        }

        return $context;
    }

    /**
     * @param  array<string, array{before:mixed,after:mixed}>  $changedAttributes
     * @return array<int, string>
     */
    private function resolveDeferredMediaEventFields(
        array $changedAttributes,
        bool $queueMedia,
        bool $hasPayloadImages,
    ): array {
        if (! $queueMedia || ! $hasPayloadImages || $changedAttributes === []) {
            return [];
        }

        $deferredFields = array_values(array_intersect(
            array_keys($changedAttributes),
            self::DEFERRED_MEDIA_EVENT_FIELDS,
        ));

        return array_values(array_unique($deferredFields));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildProductContextSnapshot(Product $product): array
    {
        $snapshot = [];

        foreach (self::EVENT_CONTEXT_PRODUCT_FIELDS as $field) {
            $snapshot[$field] = $this->normalizeEventContextValue($product->getAttribute($field));
        }

        return $snapshot;
    }

    /**
     * @param  array<string, mixed>  $dirtyAttributes
     * @return array{changes:array<string, array{before:mixed,after:mixed}>,other_changed_fields:array<int, string>}
     */
    private function buildChangedAttributesContext(Product $product, array $dirtyAttributes): array
    {
        $changes = [];
        $otherChangedFields = [];

        foreach (array_keys($dirtyAttributes) as $attribute) {
            if (! in_array($attribute, self::EVENT_CONTEXT_PRODUCT_FIELDS, true)) {
                $otherChangedFields[] = $attribute;

                continue;
            }

            $beforeValue = $product->getOriginal($attribute);
            $afterValue = $dirtyAttributes[$attribute];

            if ($this->isEventContextJsonField($attribute)) {
                $beforeValue = $this->normalizeEventContextJsonValue($beforeValue);
                $afterValue = $this->normalizeEventContextJsonValue($afterValue);
            }

            $changes[$attribute] = [
                'before' => $this->normalizeEventContextValue($beforeValue),
                'after' => $this->normalizeEventContextValue($afterValue),
            ];
        }

        return [
            'changes' => $changes,
            'other_changed_fields' => $otherChangedFields,
        ];
    }

    private function normalizeEventContextValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return Str::limit($value, 300, '...');
        }

        if (is_array($value)) {
            $normalized = [];

            foreach ($value as $key => $item) {
                $normalized[$key] = $this->normalizeEventContextValue($item);
            }

            return $normalized;
        }

        return $value;
    }

    private function isEventContextJsonField(string $attribute): bool
    {
        return in_array($attribute, self::EVENT_CONTEXT_JSON_FIELDS, true);
    }

    private function normalizeEventContextJsonValue(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return $value;
        }

        $decoded = json_decode($trimmed, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function sanitizeExistingProductUpdateAttributes(
        array $attributes,
        ProductPayload $payload,
        bool $queueMedia,
        bool $hasPayloadImages,
        bool $preserveMissingPrice,
    ): array {
        if ($preserveMissingPrice && $payload->priceAmount === null && $payload->discountPrice === null) {
            $attributes = array_diff_key($attributes, array_flip(['price_amount', 'discount_price', 'currency']));
        }

        if (! $queueMedia || ! $hasPayloadImages) {
            return $attributes;
        }

        return array_diff_key($attributes, array_flip(self::DEFERRED_MEDIA_EVENT_FIELDS));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logEvent(
        ?ImportRunEventLoggerInterface $logger,
        ?int $runId,
        string $supplier,
        string $stage,
        string $result,
        ?string $sourceRef = null,
        ?string $externalId = null,
        ?int $productId = null,
        ?int $sourceCategoryId = null,
        ?int $rowIndex = null,
        ?string $code = null,
        ?string $message = null,
        array $context = [],
    ): void {
        if (! $logger instanceof ImportRunEventLoggerInterface || $runId === null || $runId <= 0) {
            return;
        }

        $logger->log(new ImportRunEventData(
            runId: $runId,
            supplier: $supplier,
            stage: $stage,
            result: $result,
            sourceRef: $sourceRef,
            externalId: $externalId,
            productId: $productId,
            sourceCategoryId: $sourceCategoryId,
            rowIndex: $rowIndex,
            code: $code,
            message: $message,
            context: $context,
        ));
    }

    /**
     * @param  array<int, ImportError>  $errors
     */
    private function countErrors(array $errors): int
    {
        return count($errors);
    }

    /**
     * @return array{processed:int,created:int,updated:int,skipped:int,errors:int,results:array<int, ImportProcessResult>}
     */
    private function emptySummary(): array
    {
        return [
            'processed' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'results' => [],
        ];
    }

    /**
     * @return array{processed:int,created:int,updated:int,skipped:int,errors:int,results:array<int, ImportProcessResult>}
     */
    private function summaryFromFatalSupplierError(int $itemsCount): array
    {
        $error = new ImportError(
            code: 'missing_supplier',
            message: 'Для процессора импорта обязателен параметр "supplier".',
            level: ImportErrorLevel::Fatal,
        );

        $summary = $this->emptySummary();

        for ($index = 0; $index < $itemsCount; $index++) {
            $summary['processed']++;
            $summary['skipped']++;
            $summary['errors']++;
            $summary['results'][] = new ImportProcessResult(
                operation: 'failed',
                errors: [$error],
            );
        }

        return $summary;
    }
}
