<?php

namespace App\Support\CatalogImport\Yml;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductSupplierReference;
use App\Support\CatalogImport\Contracts\SourceResolverInterface;
use App\Support\CatalogImport\DTO\ResolvedSource;
use App\Support\CatalogImport\Sources\SourceResolver;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;
use Throwable;

class YandexMarketFeedDeactivationService
{
    public function __construct(
        private readonly YmlStreamParser $recordParser,
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
     *     candidates: int,
     *     deactivated: int,
     *     samples: array<int, array<string, string|int>>,
     *     fatal_error: string|null,
     *     no_urls: bool,
     *     success: bool
     * }
     */
    public function run(array $options = [], ?callable $output = null, ?callable $progress = null): array
    {
        $normalized = $this->normalizeOptions($options);

        try {
            $this->validateNormalizedOptions($normalized);
            $source = $this->resolveSource($normalized);
            [$foundUrls, $feedExternalIds] = $this->collectFeedExternalIds($source, $normalized, $output, $progress);
        } catch (Throwable $exception) {
            $this->emitProgress($progress, [
                'found_urls' => 0,
                'processed' => 0,
                'errors' => 1,
                'candidates' => 0,
                'deactivated' => 0,
                'no_urls' => false,
            ]);

            return [
                'options' => $normalized,
                'write_mode' => $normalized['write'],
                'found_urls' => 0,
                'processed' => 0,
                'errors' => 1,
                'candidates' => 0,
                'deactivated' => 0,
                'samples' => [],
                'fatal_error' => $exception->getMessage(),
                'no_urls' => false,
                'success' => false,
            ];
        }

        if ($foundUrls === 0) {
            $this->emit($output, 'warn', 'В выбранном feed не найдено ни одного offer с external_id.');
            $this->emitProgress($progress, [
                'found_urls' => 0,
                'processed' => 0,
                'errors' => 0,
                'candidates' => 0,
                'deactivated' => 0,
                'no_urls' => true,
            ]);

            return [
                'options' => $normalized,
                'write_mode' => $normalized['write'],
                'found_urls' => 0,
                'processed' => 0,
                'errors' => 0,
                'candidates' => 0,
                'deactivated' => 0,
                'samples' => [],
                'fatal_error' => null,
                'no_urls' => true,
                'success' => true,
            ];
        }

        $scopeCategoryIds = $this->resolveCategoryScopeIds($normalized['site_category_id']);
        $processed = 0;
        $errors = 0;
        $candidates = 0;
        $deactivated = 0;
        $samples = [];
        $candidateProductIds = [];

        $query = ProductSupplierReference::query()
            ->with([
                'product',
                'product.categories',
            ])
            ->where('supplier_id', $normalized['supplier_id'])
            ->whereHas('product', function (Builder $builder) use ($scopeCategoryIds): void {
                $builder
                    ->where('is_active', true)
                    ->whereHas('categories', function (Builder $categoryQuery) use ($scopeCategoryIds): void {
                        $categoryQuery->whereIn('categories.id', $scopeCategoryIds);
                    });
            })
            ->orderBy('id');

        $this->emit($output, 'info', 'Feed parsed: found_external_ids='.$foundUrls.'.');

        $query->chunkById(250, function ($references) use (
            $feedExternalIds,
            &$processed,
            &$candidates,
            &$samples,
            &$candidateProductIds,
            $normalized,
            $progress,
        ): void {
            foreach ($references as $reference) {
                $processed++;
                $externalId = trim((string) $reference->external_id);
                $productId = (int) $reference->product_id;

                if ($externalId === '' || isset($feedExternalIds[$externalId]) || isset($candidateProductIds[$productId])) {
                    $this->emitProgress($progress, [
                        'found_urls' => count($feedExternalIds),
                        'processed' => $processed,
                        'errors' => 0,
                        'candidates' => $candidates,
                        'deactivated' => 0,
                        'no_urls' => false,
                    ]);

                    continue;
                }

                $candidateProductIds[$productId] = true;
                $candidates++;

                if (count($samples) < $normalized['show_samples']) {
                    $samples[] = $this->sampleRow($reference);
                }

                $this->emitProgress($progress, [
                    'found_urls' => count($feedExternalIds),
                    'processed' => $processed,
                    'errors' => 0,
                    'candidates' => $candidates,
                    'deactivated' => 0,
                    'no_urls' => false,
                ]);
            }
        });

        if ($normalized['write'] && $candidateProductIds !== []) {
            $deactivated = Product::query()
                ->whereIn('id', array_keys($candidateProductIds))
                ->update([
                    'is_active' => false,
                    'in_stock' => false,
                    'qty' => 0,
                    'updated_at' => now(),
                ]);
        }

        $this->emitProgress($progress, [
            'found_urls' => $foundUrls,
            'processed' => $processed,
            'errors' => $errors,
            'candidates' => $candidates,
            'deactivated' => $deactivated,
            'no_urls' => false,
        ]);

        return [
            'options' => $normalized,
            'write_mode' => $normalized['write'],
            'found_urls' => $foundUrls,
            'processed' => $processed,
            'errors' => $errors,
            'candidates' => $candidates,
            'deactivated' => $deactivated,
            'samples' => $samples,
            'fatal_error' => null,
            'no_urls' => false,
            'success' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{
     *     source: string,
     *     supplier_id: int|null,
     *     supplier_name: string,
     *     site_category_id: int|null,
     *     site_category_name: string,
     *     timeout: int,
     *     show_samples: int,
     *     write: bool
     * }
     */
    private function normalizeOptions(array $options): array
    {
        $supplierId = $this->normalizeNullableInt($options['supplier_id'] ?? $options['supplier-id'] ?? null);
        $siteCategoryId = $this->normalizeNullableInt($options['site_category_id'] ?? $options['site-category-id'] ?? null);

        return [
            'source' => trim((string) ($options['source'] ?? $options['feed'] ?? '')),
            'supplier_id' => $supplierId,
            'supplier_name' => trim((string) ($options['supplier_name'] ?? '')),
            'site_category_id' => $siteCategoryId,
            'site_category_name' => trim((string) ($options['site_category_name'] ?? '')),
            'timeout' => max(1, (int) ($options['timeout'] ?? 25)),
            'show_samples' => max(0, (int) ($options['show_samples'] ?? $options['show-samples'] ?? 20)),
            'write' => $this->normalizeBoolOption($options['write'] ?? false, false),
        ];
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function validateNormalizedOptions(array $normalized): void
    {
        if ($normalized['source'] === '') {
            throw new RuntimeException('Укажите XML/YML feed для сверки.');
        }

        if (! is_int($normalized['supplier_id']) || $normalized['supplier_id'] <= 0) {
            throw new RuntimeException('Выберите поставщика для сценария деактивации.');
        }

        if (! is_int($normalized['site_category_id']) || $normalized['site_category_id'] <= 0) {
            throw new RuntimeException('Выберите категорию сайта для области деактивации.');
        }
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function resolveSource(array $normalized): ResolvedSource
    {
        return $this->sourceResolver->resolve($normalized['source'], [
            'cache_key' => 'yandex_market_feed_deactivation_'.sha1($normalized['source']),
            'timeout' => max(1, (float) $normalized['timeout']),
            'connect_timeout' => min(10.0, max(1.0, (float) $normalized['timeout'])),
            'retry_times' => 2,
            'retry_sleep_ms' => 300,
        ]);
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @return array{0:int,1:array<string, true>}
     */
    private function collectFeedExternalIds(
        ResolvedSource $source,
        array $normalized,
        ?callable $output = null,
        ?callable $progress = null,
    ): array {
        $externalIds = [];
        $foundUrls = 0;

        foreach ($this->recordParser->parse($source, []) as $record) {
            if (! $record instanceof YmlOfferRecord) {
                continue;
            }

            $externalId = trim($record->id);

            if ($externalId === '') {
                continue;
            }

            $externalIds[$externalId] = true;
            $foundUrls = count($externalIds);

            $this->emitProgress($progress, [
                'found_urls' => $foundUrls,
                'processed' => 0,
                'errors' => 0,
                'candidates' => 0,
                'deactivated' => 0,
                'no_urls' => false,
            ]);
        }

        $this->emit($output, 'line', 'Loaded feed external IDs: '.$foundUrls.'.');

        return [$foundUrls, $externalIds];
    }

    /**
     * @return array<int, int>
     */
    private function resolveCategoryScopeIds(?int $selectedCategoryId): array
    {
        if ($selectedCategoryId === null) {
            return [];
        }

        $categories = Category::query()
            ->select(['id', 'parent_id'])
            ->orderBy('id')
            ->get();

        $categoryIds = $categories
            ->pluck('id')
            ->filter(fn (mixed $id): bool => is_int($id) && $id > 0)
            ->values()
            ->all();

        if (! in_array($selectedCategoryId, $categoryIds, true)) {
            throw new RuntimeException('Выбранная категория сайта не найдена.');
        }

        $childrenByParent = [];

        foreach ($categories as $category) {
            $childrenByParent[(int) $category->parent_id][] = (int) $category->id;
        }

        $scopeIds = [];
        $stack = [$selectedCategoryId];

        while ($stack !== []) {
            $currentId = array_pop($stack);

            if (! is_int($currentId) || $currentId <= 0 || isset($scopeIds[$currentId])) {
                continue;
            }

            $scopeIds[$currentId] = $currentId;

            foreach ($childrenByParent[$currentId] ?? [] as $childId) {
                if (! isset($scopeIds[$childId])) {
                    $stack[] = $childId;
                }
            }
        }

        return array_values($scopeIds);
    }

    /**
     * @return array<string, string|int>
     */
    private function sampleRow(ProductSupplierReference $reference): array
    {
        $product = $reference->product;
        $categories = $product?->categories
            ->pluck('name')
            ->filter(fn (mixed $name): bool => is_string($name) && $name !== '')
            ->implode(', ') ?? '';

        return [
            'product_id' => (int) ($product?->getKey() ?? 0),
            'name' => (string) ($product?->name ?? ''),
            'external_id' => (string) $reference->external_id,
            'price' => (int) ($product?->price_amount ?? 0),
            'categories' => $categories,
        ];
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

    private function normalizeBoolOption(mixed $value, bool $default): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value !== 0;
        }

        if (is_string($value)) {
            $normalized = mb_strtolower(trim($value));

            if (in_array($normalized, ['1', 'true', 'yes', 'y', 'on'], true)) {
                return true;
            }

            if (in_array($normalized, ['0', 'false', 'no', 'n', 'off'], true)) {
                return false;
            }
        }

        return $default;
    }

    private function emit(?callable $output, string $type, string|array $payload): void
    {
        if ($output !== null) {
            $output($type, $payload);
        }
    }

    /**
     * @param  array<string, int|bool>  $payload
     */
    private function emitProgress(?callable $progress, array $payload): void
    {
        if ($progress !== null) {
            $progress($payload);
        }
    }
}
