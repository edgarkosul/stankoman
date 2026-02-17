<?php

namespace App\Support\Metalmaster;

use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class MetalmasterProductImportService
{
    private bool $stagingCategoryResolved = false;

    private ?int $stagingCategoryId = null;

    public function __construct(private MetalmasterProductParser $productParser) {}

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
            $targets = $this->loadTargets(
                $normalized['buckets_file'],
                $normalized['bucket'],
                $normalized['limit'],
            );
        } catch (Throwable $exception) {
            $this->emitProgress($progress, $this->makeProgressPayload(
                foundUrls: 0,
                processed: 0,
                errors: 0,
                created: 0,
                updated: 0,
                skipped: 0,
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
                'samples' => [],
                'url_errors' => [],
                'fatal_error' => $exception->getMessage(),
                'no_urls' => false,
                'success' => false,
            ];
        }

        if ($targets->isEmpty()) {
            $this->emit($output, 'warn', 'Подходящие URL товаров не найдены.');
            $this->emitProgress($progress, $this->makeProgressPayload(
                foundUrls: 0,
                processed: 0,
                errors: 0,
                created: 0,
                updated: 0,
                skipped: 0,
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
                'samples' => [],
                'url_errors' => [],
                'fatal_error' => null,
                'no_urls' => true,
                'success' => true,
            ];
        }

        $this->emit($output, 'info', 'Найдено URL товаров: '.$targets->count());
        $this->emit($output, 'line', 'Режим: '.($normalized['write'] ? 'write' : 'dry-run'));

        $foundUrls = $targets->count();
        $processed = 0;
        $errors = 0;
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $samples = [];
        $urlErrors = [];

        $this->emitProgress($progress, $this->makeProgressPayload(
            foundUrls: $foundUrls,
            processed: 0,
            errors: 0,
            created: 0,
            updated: 0,
            skipped: 0,
            noUrls: false,
        ));

        foreach ($targets as $target) {
            $url = (string) $target['url'];
            $bucket = (string) $target['bucket'];

            try {
                $response = Http::withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; SitekoParser/1.0; +https://siteko.net)',
                    'Accept-Language' => 'ru-RU,ru;q=0.9,en;q=0.8',
                ])
                    ->timeout($normalized['timeout'])
                    ->retry(2, 300)
                    ->get($url);

                if (! $response->ok()) {
                    throw new RuntimeException('HTTP '.$response->status());
                }

                $parsed = $this->productParser->parse((string) $response->body(), $url, $bucket);
                $parsed['source_url'] = $url;

                if ($normalized['write']) {
                    $result = $this->storeProduct(
                        $parsed,
                        $normalized['publish'],
                        $normalized['skip_existing'],
                    );

                    if ($result['status'] === 'created') {
                        $created++;
                    } elseif ($result['status'] === 'updated') {
                        $updated++;
                    } else {
                        $skipped++;
                    }
                } elseif (count($samples) < $normalized['show_samples']) {
                    $samples[] = $this->sampleRow($parsed, $bucket);
                }

                $processed++;
                $this->emit($output, 'line', 'OK: '.$url);

                if ($normalized['delay_ms'] > 0) {
                    usleep($normalized['delay_ms'] * 1000);
                }
            } catch (Throwable $exception) {
                $errors++;
                $urlErrors[] = [
                    'url' => $url,
                    'message' => $exception->getMessage(),
                ];
                $this->emit($output, 'error', 'ERR: '.$url.' | '.$exception->getMessage());
            }

            $this->emitProgress($progress, $this->makeProgressPayload(
                foundUrls: $foundUrls,
                processed: $processed,
                errors: $errors,
                created: $created,
                updated: $updated,
                skipped: $skipped,
                noUrls: false,
            ));
        }

        $this->emit($output, 'new_line', '');
        $this->emit($output, 'info', 'Итого: processed='.$processed.', errors='.$errors.'.');

        if ($normalized['write']) {
            $this->emit($output, 'line', 'DB: created='.$created.', updated='.$updated.', skipped='.$skipped.'.');
        }

        if (! $normalized['write'] && $samples !== []) {
            $this->emit($output, 'new_line', '');
            $this->emit($output, 'table', [
                'headers' => ['url', 'bucket', 'title', 'price', 'brand', 'images', 'specs'],
                'rows' => $samples,
            ]);
        }

        $this->emitProgress($progress, $this->makeProgressPayload(
            foundUrls: $foundUrls,
            processed: $processed,
            errors: $errors,
            created: $created,
            updated: $updated,
            skipped: $skipped,
            noUrls: false,
        ));

        return [
            'options' => $normalized,
            'write_mode' => $normalized['write'],
            'found_urls' => $foundUrls,
            'processed' => $processed,
            'errors' => $errors,
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
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
     *     buckets_file: string,
     *     bucket: string,
     *     limit: int,
     *     timeout: int,
     *     delay_ms: int,
     *     write: bool,
     *     publish: bool,
     *     skip_existing: bool,
     *     show_samples: int
     * }
     */
    private function normalizeOptions(array $options): array
    {
        return [
            'buckets_file' => trim((string) (
                $options['buckets_file']
                ?? $options['buckets-file']
                ?? storage_path('app/parser/metalmaster-buckets.json')
            )),
            'bucket' => trim((string) ($options['bucket'] ?? '')),
            'limit' => max(0, (int) ($options['limit'] ?? 0)),
            'timeout' => max(1, (int) ($options['timeout'] ?? 25)),
            'delay_ms' => max(0, (int) (
                $options['delay_ms']
                ?? $options['delay-ms']
                ?? $options['sleep']
                ?? 250
            )),
            'write' => (bool) ($options['write'] ?? true),
            'publish' => (bool) ($options['publish'] ?? false),
            'skip_existing' => (bool) ($options['skip_existing'] ?? $options['skip-existing'] ?? false),
            'show_samples' => max(0, (int) ($options['show_samples'] ?? $options['show-samples'] ?? 3)),
        ];
    }

    /**
     * @return Collection<int, array{bucket: string, url: string}>
     */
    private function loadTargets(string $bucketsFile, string $bucketFilter, int $limit): Collection
    {
        if (! is_file($bucketsFile)) {
            throw new RuntimeException("Buckets file not found: {$bucketsFile}");
        }

        $raw = file_get_contents($bucketsFile);

        if (! is_string($raw)) {
            throw new RuntimeException("Unable to read buckets file: {$bucketsFile}");
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            throw new RuntimeException("Invalid buckets JSON: {$bucketsFile}");
        }

        $bucketRows = $this->extractBucketRows($decoded);

        $targets = collect($bucketRows)
            ->filter(function (mixed $row) use ($bucketFilter): bool {
                if (! is_array($row)) {
                    return false;
                }

                if ($bucketFilter === '') {
                    return true;
                }

                return (string) ($row['bucket'] ?? '') === $bucketFilter;
            })
            ->flatMap(function (array $row): Collection {
                $bucket = (string) ($row['bucket'] ?? '');
                $urls = is_array($row['product_urls'] ?? null) ? $row['product_urls'] : [];

                return collect($urls)->map(fn (mixed $url): array => [
                    'bucket' => $bucket,
                    'url' => (string) $url,
                ]);
            })
            ->filter(fn (array $target): bool => filter_var($target['url'], FILTER_VALIDATE_URL) !== false)
            ->unique('url')
            ->values();

        if ($limit > 0) {
            $targets = $targets->take($limit)->values();
        }

        return $targets;
    }

    /**
     * @param  array<string|int, mixed>  $decoded
     * @return array<int, array<string, mixed>>
     */
    private function extractBucketRows(array $decoded): array
    {
        if (array_is_list($decoded)) {
            return array_values(array_filter($decoded, 'is_array'));
        }

        $rows = $decoded['buckets'] ?? null;

        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_filter($rows, 'is_array'));
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return array{status: string}
     */
    private function storeProduct(array $parsed, bool $publish, bool $skipExisting): array
    {
        $slug = $this->limit($parsed['slug'] ?? null, 255);

        if ($slug === null) {
            return ['status' => 'skipped'];
        }

        $product = Product::query()
            ->where('slug', $slug)
            ->first();

        if ($product instanceof Product && $skipExisting) {
            return ['status' => 'skipped'];
        }

        $name = $this->limit($parsed['name'] ?? null, 255)
            ?? Str::headline(str_replace('-', ' ', $slug));
        $description = $this->normalizeDescription($parsed['description'] ?? null);
        $gallery = $this->normalizeImages($parsed['gallery'] ?? []);
        $image = $this->limit($parsed['image'] ?? null, 255);
        $thumb = $this->limit($parsed['thumb'] ?? null, 255) ?? $image;

        $attributes = [
            'name' => $name,
            'title' => $this->limit($parsed['title'] ?? null, 255),
            'sku' => $this->limit($parsed['sku'] ?? null, 255),
            'brand' => $this->limit($parsed['brand'] ?? null, 255),
            'country' => $this->limit($parsed['country'] ?? null, 255),
            'price_amount' => $this->normalizePriceAmount($parsed['price_amount'] ?? null),
            'discount_price' => $this->normalizeNullableInteger($parsed['discount_price'] ?? null),
            'currency' => $this->normalizeCurrency($parsed['currency'] ?? null),
            'in_stock' => $this->normalizeInStock($parsed['in_stock'] ?? null),
            'qty' => $this->normalizeQuantity($parsed['qty'] ?? null),
            'is_active' => $publish,
            'short' => $this->limit($parsed['short'] ?? null, 1000),
            'description' => $description,
            'extra_description' => $this->normalizeDescription($parsed['extra_description'] ?? null),
            'specs' => $this->normalizeSpecs($parsed['specs'] ?? null),
            'promo_info' => $this->limit($parsed['promo_info'] ?? null, 255),
            'image' => $image,
            'thumb' => $thumb,
            'gallery' => $gallery === [] ? null : $gallery,
            'meta_title' => $this->limit($parsed['meta_title'] ?? $name, 255),
            'meta_description' => $this->metaDescriptionFromText($parsed['meta_description'] ?? $description),
        ];

        if ($product instanceof Product) {
            $product->fill($attributes);
            $product->save();
            $this->attachToStagingCategory($product);

            return ['status' => 'updated'];
        }

        $created = Product::query()->create(array_merge($attributes, [
            'slug' => $slug,
            'is_in_yml_feed' => true,
            'with_dns' => true,
        ]));
        $this->attachToStagingCategory($created);

        return ['status' => 'created'];
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

        $stagingSlug = trim((string) config('catalog-export.staging_category_slug'));

        if ($stagingSlug === '') {
            return null;
        }

        if (! Schema::hasTable('categories') || ! Schema::hasTable('product_categories')) {
            return null;
        }

        $categoryId = $this->findStagingCategoryId($stagingSlug);

        if ($categoryId === null) {
            $categoryId = $this->createStagingCategory($stagingSlug);
        }

        if ($categoryId === null) {
            return null;
        }

        $this->stagingCategoryId = $categoryId;

        return $this->stagingCategoryId;
    }

    private function findStagingCategoryId(string $slug): ?int
    {
        try {
            $query = DB::table('categories')
                ->where('slug', $slug);

            if (Schema::hasColumn('categories', 'parent_id')) {
                $query->where('parent_id', -1);
            }

            $categoryId = $query->value('id');
        } catch (Throwable) {
            return null;
        }

        if (! is_numeric($categoryId)) {
            return null;
        }

        return (int) $categoryId;
    }

    private function createStagingCategory(string $slug): ?int
    {
        try {
            $payload = [
                'name' => Str::headline($slug),
                'slug' => $slug,
            ];

            if (Schema::hasColumn('categories', 'parent_id')) {
                $payload['parent_id'] = -1;
            }

            if (Schema::hasColumn('categories', 'order')) {
                $payload['order'] = (int) DB::table('categories')
                    ->when(
                        Schema::hasColumn('categories', 'parent_id'),
                        fn ($query) => $query->where('parent_id', -1)
                    )
                    ->max('order') + 1;
            }

            if (Schema::hasColumn('categories', 'is_active')) {
                $payload['is_active'] = true;
            }

            if (Schema::hasColumn('categories', 'created_at')) {
                $payload['created_at'] = now();
            }

            if (Schema::hasColumn('categories', 'updated_at')) {
                $payload['updated_at'] = now();
            }

            DB::table('categories')->insert($payload);
        } catch (Throwable) {
            return $this->findStagingCategoryId($slug);
        }

        return $this->findStagingCategoryId($slug);
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return array{url: string, bucket: string, title: string, price: string, brand: string, images: string, specs: string}
     */
    private function sampleRow(array $parsed, string $bucket): array
    {
        return [
            'url' => (string) ($parsed['source_url'] ?? ''),
            'bucket' => $bucket,
            'title' => (string) ($parsed['title'] ?? ''),
            'price' => (string) $this->normalizePriceAmount($parsed['price_amount'] ?? null),
            'brand' => (string) ($parsed['brand'] ?? ''),
            'images' => (string) count($this->normalizeImages($parsed['gallery'] ?? [])),
            'specs' => (string) count($this->normalizeSpecs($parsed['specs'] ?? []) ?? []),
        ];
    }

    private function normalizePriceAmount(mixed $rawPrice): int
    {
        if (is_int($rawPrice) || is_float($rawPrice)) {
            return max(0, (int) round($rawPrice));
        }

        if (! is_string($rawPrice)) {
            return 0;
        }

        $price = str_replace(["\xC2\xA0", ' '], '', trim($rawPrice));
        $price = preg_replace('/[^0-9,.-]/u', '', $price) ?? '';

        if ($price === '') {
            return 0;
        }

        if (str_contains($price, ',') && str_contains($price, '.')) {
            $price = str_replace(',', '', $price);
        }

        $price = str_replace(',', '.', $price);

        if (! is_numeric($price)) {
            if (! preg_match('/-?[0-9]+(?:[.,][0-9]+)?/', $rawPrice, $matches)) {
                return 0;
            }

            $price = str_replace(',', '.', $matches[0]);
        }

        return max(0, (int) round((float) $price));
    }

    private function normalizeNullableInteger(mixed $rawValue): ?int
    {
        if ($rawValue === null || $rawValue === '') {
            return null;
        }

        if (is_int($rawValue) || is_float($rawValue)) {
            return max(0, (int) round($rawValue));
        }

        if (! is_string($rawValue)) {
            return null;
        }

        if (! preg_match('/-?[0-9]+(?:[.,][0-9]+)?/', $rawValue, $matches)) {
            return null;
        }

        $value = str_replace(',', '.', $matches[0]);

        return max(0, (int) round((float) $value));
    }

    private function normalizeCurrency(mixed $rawCurrency): string
    {
        if (! is_string($rawCurrency)) {
            return 'RUB';
        }

        $currency = strtoupper($rawCurrency);
        $currency = preg_replace('/[^A-Z]/', '', $currency) ?? '';

        if ($currency === 'RUR') {
            return 'RUB';
        }

        return strlen($currency) >= 3 ? substr($currency, 0, 3) : 'RUB';
    }

    private function normalizeQuantity(mixed $rawQuantity): ?int
    {
        if ($rawQuantity === null || $rawQuantity === '') {
            return null;
        }

        if (is_bool($rawQuantity)) {
            return $rawQuantity ? 1 : 0;
        }

        if (is_int($rawQuantity) || is_float($rawQuantity)) {
            return max(0, (int) round($rawQuantity));
        }

        if (! is_string($rawQuantity)) {
            return null;
        }

        if (! preg_match('/[0-9]+(?:[.,][0-9]+)?/', $rawQuantity, $matches)) {
            return null;
        }

        $value = str_replace(',', '.', $matches[0]);

        return max(0, (int) round((float) $value));
    }

    private function normalizeInStock(mixed $rawInStock): bool
    {
        if (is_bool($rawInStock)) {
            return $rawInStock;
        }

        if (is_int($rawInStock)) {
            return $rawInStock > 0;
        }

        if (is_string($rawInStock)) {
            $value = mb_strtolower(trim($rawInStock));

            if (in_array($value, ['1', 'true', 'yes', 'y', 'instock', 'в наличии'], true)) {
                return true;
            }

            if (in_array($value, ['0', 'false', 'no', 'n', 'outofstock', 'нет в наличии'], true)) {
                return false;
            }
        }

        return false;
    }

    private function normalizeDescription(mixed $rawDescription): ?string
    {
        if (! is_string($rawDescription)) {
            return null;
        }

        $description = trim($rawDescription);

        if ($description === '') {
            return null;
        }

        if (Str::startsWith($description, '<')) {
            return $description;
        }

        $escaped = htmlspecialchars($description, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<p>'.$escaped.'</p>';
    }

    /**
     * @return array<int, array{name: string, value: string, source: string}>|null
     */
    private function normalizeSpecs(mixed $rawSpecs): ?array
    {
        if (is_string($rawSpecs)) {
            $decoded = json_decode($rawSpecs, true);

            if (is_array($decoded)) {
                $rawSpecs = $decoded;
            }
        }

        if (! is_array($rawSpecs)) {
            return null;
        }

        $normalized = [];

        if (! array_is_list($rawSpecs)) {
            foreach ($rawSpecs as $nameRaw => $valueRaw) {
                $name = $this->limit(is_string($nameRaw) ? $nameRaw : null, 255);
                $value = $this->limit(is_scalar($valueRaw) ? (string) $valueRaw : null, 1000);

                if ($name === null || $value === null) {
                    continue;
                }

                $key = mb_strtolower($name.'::'.$value);

                if (! isset($normalized[$key])) {
                    $normalized[$key] = [
                        'name' => $name,
                        'value' => $value,
                        'source' => 'import',
                    ];
                }
            }

            return $normalized === [] ? null : array_values($normalized);
        }

        foreach ($rawSpecs as $spec) {
            if (! is_array($spec)) {
                continue;
            }

            $name = $this->limit($spec['name'] ?? null, 255);
            $value = $this->limit($spec['value'] ?? null, 1000);
            $source = $this->limit($spec['source'] ?? null, 64) ?? 'import';

            if ($name === null || $value === null) {
                continue;
            }

            $key = mb_strtolower($name.'::'.$value);

            if (! isset($normalized[$key])) {
                $normalized[$key] = [
                    'name' => $name,
                    'value' => $value,
                    'source' => $source,
                ];
            }
        }

        return $normalized === [] ? null : array_values($normalized);
    }

    /**
     * @return array<int, string>
     */
    private function normalizeImages(mixed $rawImages): array
    {
        if (is_string($rawImages)) {
            $decoded = json_decode($rawImages, true);

            if (is_array($decoded)) {
                $rawImages = $decoded;
            } else {
                $rawImages = [$rawImages];
            }
        }

        if (! is_array($rawImages)) {
            return [];
        }

        $images = [];

        foreach ($rawImages as $image) {
            if (! is_string($image)) {
                continue;
            }

            $image = trim($image);

            if ($image === '') {
                continue;
            }

            $key = mb_strtolower($image);
            $images[$key] = $image;
        }

        return array_values($images);
    }

    private function metaDescriptionFromText(mixed $rawDescription): ?string
    {
        if (! is_string($rawDescription) || trim($rawDescription) === '') {
            return null;
        }

        $plain = strip_tags($rawDescription);
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
        bool $noUrls,
    ): array {
        return [
            'found_urls' => $foundUrls,
            'processed' => $processed,
            'errors' => $errors,
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'no_urls' => $noUrls,
        ];
    }
}
