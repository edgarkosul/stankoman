<?php

namespace App\Support\CatalogImport\Processing;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductSupplierReference;
use App\Support\CatalogImport\Contracts\ImportProcessorInterface;
use App\Support\CatalogImport\DTO\ImportError;
use App\Support\CatalogImport\DTO\ImportProcessResult;
use App\Support\CatalogImport\DTO\ProductPayload;
use App\Support\CatalogImport\Enums\ImportErrorLevel;
use App\Support\CatalogImport\Media\ProductImportMediaService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

final class ProductImportProcessor implements ImportProcessorInterface
{
    private bool $stagingCategoryResolved = false;

    private ?int $stagingCategoryId = null;

    private ?bool $sourceCategoryReferenceSupported = null;

    public function __construct(
        private readonly ProductPayloadNormalizer $normalizer = new ProductPayloadNormalizer,
        private readonly ProductImportMediaService $mediaService = new ProductImportMediaService,
    ) {}

    public function process(ProductPayload $payload, array $options = []): ImportProcessResult
    {
        $summary = $this->processBatch([$payload], $options);

        return $summary['results'][0] ?? new ImportProcessResult(
            operation: 'skipped',
            errors: [
                new ImportError(
                    code: 'empty_batch',
                    message: 'No payloads were provided for processing.',
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

        $summary = $this->emptySummary();

        foreach (array_chunk($items, $batchSize) as $chunk) {
            $chunkSummary = $this->processChunk($chunk, $supplier, $runId, $options);

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
        $sourceCategoryId = $this->positiveIntOrNull(
            $options['source_category_id'] ?? $options['category_id'] ?? null,
        );

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
            return [
                'checked' => 0,
                'deactivated' => 0,
                'skipped' => true,
            ];
        }

        $referenceQuery = ProductSupplierReference::query()
            ->where('supplier', $normalizedSupplier);

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
            return [
                'checked' => $checked,
                'deactivated' => 0,
                'skipped' => true,
            ];
        }

        if ($checked === 0) {
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
    private function processChunk(array $payloads, string $supplier, ?int $runId, array $options): array
    {
        $normalizedItems = [];
        $summary = $this->emptySummary();
        $queueMedia = ($options['download_media'] ?? false) === true;
        $pendingMediaIds = [];

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
        $references = ProductSupplierReference::query()
            ->with('product')
            ->where('supplier', $supplier)
            ->whereIn('external_id', $externalIds)
            ->get()
            ->keyBy('external_id');

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
            $canCreate,
            $canUpdate,
            $queueMedia,
            $options,
            $supportsSourceCategoryReference,
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
                $mediaQueueStats = [
                    'queued' => 0,
                    'reused' => 0,
                    'deduplicated' => 0,
                ];

                if ($product instanceof Product) {
                    if ($canUpdate) {
                        $product->fill($this->buildProductAttributes($payload, $options, isNew: false));
                        $product->save();

                        $operation = 'updated';
                        $summary['updated']++;
                    } else {
                        $operation = 'skipped';
                        $summary['skipped']++;
                    }
                } elseif ($canCreate) {
                    $product = Product::query()->create(
                        $this->buildProductAttributes($payload, $options, isNew: true),
                    );

                    $this->attachToStagingCategory($product);

                    $operation = 'created';
                    $summary['created']++;
                } else {
                    $operation = 'skipped';
                    $summary['skipped']++;
                }

                if ($product instanceof Product) {
                    if ($queueMedia && $payload->images !== []) {
                        $mediaQueueResult = $this->mediaService->enqueueProductMedia(
                            product: $product,
                            sourceUrls: $payload->images,
                            runId: $runId,
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

                    if ($supportsSourceCategoryReference) {
                        $referenceUpsert['source_category_id'] = $this->resolveSourceCategoryId($payload, $reference);
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
            }

            if ($referenceUpserts !== []) {
                $updateColumns = ['product_id', 'last_seen_run_id', 'last_seen_at', 'updated_at'];

                if ($supportsSourceCategoryReference) {
                    $updateColumns[] = 'source_category_id';
                }

                ProductSupplierReference::query()->upsert(
                    values: $referenceUpserts,
                    uniqueBy: ['supplier', 'external_id'],
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
                message: 'Product payload must contain a non-empty external_id.',
            );
        }

        if ($payload->name === '') {
            $errors[] = new ImportError(
                code: 'missing_name',
                message: 'Product payload must contain a non-empty name.',
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
        } elseif (is_bool($options['publish_updated'] ?? null)) {
            $attributes['is_active'] = (bool) $options['publish_updated'];
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
            message: 'Import processor option "supplier" is required.',
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
