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
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

final class ProductImportProcessor implements ImportProcessorInterface
{
    private bool $stagingCategoryResolved = false;

    private ?int $stagingCategoryId = null;

    public function __construct(private readonly ProductPayloadNormalizer $normalizer = new ProductPayloadNormalizer) {}

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

        $productIds = ProductSupplierReference::query()
            ->where('supplier', $normalizedSupplier)
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

        $connection->transaction(function () use (
            $normalizedItems,
            $supplier,
            $runId,
            $references,
            $canCreate,
            $canUpdate,
            $options,
            &$summary,
        ): void {
            $referenceUpserts = [];
            $now = now();

            foreach ($normalizedItems as $payload) {
                $reference = $references->get($payload->externalId);
                $product = $reference?->product;

                if (! $product instanceof Product) {
                    $reference = null;
                    $product = null;
                }

                $operation = 'skipped';
                $errors = [];

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
                    $referenceUpserts[] = [
                        'supplier' => $supplier,
                        'external_id' => $payload->externalId,
                        'product_id' => $product->id,
                        'first_seen_run_id' => $reference?->first_seen_run_id ?? $runId,
                        'last_seen_run_id' => $runId,
                        'last_seen_at' => $now,
                        'created_at' => $reference?->created_at ?? $now,
                        'updated_at' => $now,
                    ];
                }

                $summary['processed']++;
                $summary['errors'] += $this->countErrors($errors);
                $summary['results'][] = new ImportProcessResult(
                    operation: $operation,
                    errors: $errors,
                    meta: [
                        'external_id' => $payload->externalId,
                        'product_id' => $product?->id,
                    ],
                );
            }

            if ($referenceUpserts !== []) {
                ProductSupplierReference::query()->upsert(
                    values: $referenceUpserts,
                    uniqueBy: ['supplier', 'external_id'],
                    update: ['product_id', 'last_seen_run_id', 'last_seen_at', 'updated_at'],
                );
            }
        });

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

        $attributes = [
            'name' => $this->limit($payload->name, 255) ?? 'Imported product',
            'title' => $this->limit($payload->name, 255),
            'brand' => $this->limit($payload->brand, 255),
            'price_amount' => $payload->priceAmount ?? 0,
            'currency' => $payload->currency ?? 'RUB',
            'in_stock' => $this->resolveInStock($payload->inStock, $payload->qty),
            'qty' => $payload->qty,
            'description' => $description,
            'specs' => $this->normalizeSpecs($payload->attributes),
            'image' => $payload->images[0] ?? null,
            'thumb' => $payload->images[0] ?? null,
            'gallery' => $payload->images === [] ? null : $payload->images,
            'meta_title' => $this->limit($payload->name, 255),
            'meta_description' => $this->metaDescriptionFromText($description),
        ];

        if ($isNew) {
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
