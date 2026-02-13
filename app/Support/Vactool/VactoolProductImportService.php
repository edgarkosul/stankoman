<?php

namespace App\Support\Vactool;

use App\Jobs\GenerateImageDerivativesJob;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class VactoolProductImportService
{
    private bool $stagingCategoryResolved = false;

    private ?int $stagingCategoryId = null;

    public function __construct(
        private VactoolSitemapCrawler $sitemapCrawler,
        private VactoolProductParser $productParser,
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

        try {
            $allUrls = $this->sitemapCrawler->collectUrls($normalized['sitemap']);
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

        $productUrls = collect($allUrls)
            ->filter(function (mixed $url) use ($normalized): bool {
                if (! is_string($url)) {
                    return false;
                }

                return $normalized['match'] === '' || Str::contains($url, $normalized['match']);
            })
            ->unique()
            ->values();

        if ($normalized['limit'] > 0) {
            $productUrls = $productUrls->take($normalized['limit'])->values();
        }

        if ($productUrls->isEmpty()) {
            $this->emit($output, 'warn', 'Подходящие URL товаров не найдены.');
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

        $this->emit($output, 'info', 'Найдено URL товаров: '.$productUrls->count());
        $this->emit($output, 'line', 'Режим: '.($normalized['write'] ? 'write' : 'dry-run'));

        $foundUrls = $productUrls->count();
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

        foreach ($productUrls as $url) {
            try {
                $response = Http::timeout(20)
                    ->retry(2, 300)
                    ->get($url);

                if (! $response->ok()) {
                    throw new RuntimeException('HTTP '.$response->status());
                }

                $parsed = $this->productParser->parse((string) $response->body(), $url);

                if ($normalized['write']) {
                    $result = $this->storeProduct(
                        $parsed,
                        $normalized['publish'],
                        $normalized['download_images'],
                        $normalized['skip_existing'],
                    );

                    $status = $result['status'];

                    if ($status === 'created') {
                        $created++;
                    } elseif ($status === 'updated') {
                        $updated++;
                    } else {
                        $skipped++;
                    }

                    $imagesDownloaded += $result['images_downloaded'];
                    $imageDownloadFailed += $result['image_download_failed'];
                    $derivativesQueued += $result['derivatives_queued'];
                } elseif (count($samples) < $normalized['show_samples']) {
                    $samples[] = $this->sampleRow($parsed);
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
                imagesDownloaded: $imagesDownloaded,
                imageDownloadFailed: $imageDownloadFailed,
                derivativesQueued: $derivativesQueued,
                noUrls: false,
            ));
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
                'headers' => ['url', 'title', 'price', 'brand', 'images', 'specs'],
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
            imagesDownloaded: $imagesDownloaded,
            imageDownloadFailed: $imageDownloadFailed,
            derivativesQueued: $derivativesQueued,
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
     *     sitemap: string,
     *     match: string,
     *     limit: int,
     *     delay_ms: int,
     *     write: bool,
     *     publish: bool,
     *     download_images: bool,
     *     skip_existing: bool,
     *     show_samples: int
     * }
     */
    private function normalizeOptions(array $options): array
    {
        return [
            'sitemap' => (string) ($options['sitemap'] ?? 'https://vactool.ru/sitemap.xml'),
            'match' => (string) ($options['match'] ?? '/catalog/product-'),
            'limit' => max(0, (int) ($options['limit'] ?? 0)),
            'delay_ms' => max(0, (int) ($options['delay_ms'] ?? $options['delay-ms'] ?? 250)),
            'write' => (bool) ($options['write'] ?? false),
            'publish' => (bool) ($options['publish'] ?? false),
            'download_images' => (bool) ($options['download_images'] ?? $options['download-images'] ?? false),
            'skip_existing' => (bool) ($options['skip_existing'] ?? $options['skip-existing'] ?? false),
            'show_samples' => max(0, (int) ($options['show_samples'] ?? $options['show-samples'] ?? 3)),
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

    /**
     * @param  array<string, mixed>  $parsed
     * @return array{
     *     status: string,
     *     images_downloaded: int,
     *     image_download_failed: int,
     *     derivatives_queued: int
     * }
     */
    private function storeProduct(array $parsed, bool $publish, bool $downloadImages, bool $skipExisting): array
    {
        $name = $this->normalizeName($parsed['title'] ?? null);
        $stats = [
            'images_downloaded' => 0,
            'image_download_failed' => 0,
            'derivatives_queued' => 0,
        ];

        if ($name === null) {
            return array_merge($stats, ['status' => 'skipped']);
        }

        $brand = $this->limit($parsed['brand'] ?? null, 255);
        $product = $this->findExistingProduct($name, $brand);

        if ($product instanceof Product && $skipExisting) {
            return array_merge($stats, ['status' => 'skipped']);
        }

        $images = $this->normalizeImages($parsed['images'] ?? []);

        if ($downloadImages) {
            $imageResult = $this->downloadImagesToPublicDisk($images, (string) ($parsed['url'] ?? ''));
            $images = $imageResult['paths'];
            $stats['images_downloaded'] += $imageResult['downloaded'];
            $stats['image_download_failed'] += $imageResult['failed'];
            $stats['derivatives_queued'] += $imageResult['queued_derivatives'];
        }

        $quantity = $this->normalizeQuantity($parsed['stock_qty'] ?? null);
        $description = $this->normalizeDescription($parsed['description'] ?? null);

        $attributes = [
            'name' => $name,
            'title' => $this->limit($parsed['title'] ?? null, 255),
            'brand' => $brand,
            'price_amount' => $this->normalizePriceAmount($parsed['price'] ?? null),
            'currency' => $this->normalizeCurrency($parsed['currency'] ?? null),
            'in_stock' => $this->resolveInStock($parsed['availability'] ?? null, $quantity),
            'qty' => $quantity,
            'is_active' => $publish,
            'description' => $description,
            'specs' => $this->normalizeSpecs($parsed['specs'] ?? []),
            'image' => $images[0] ?? null,
            'thumb' => $images[0] ?? null,
            'gallery' => $images,
            'meta_title' => $this->limit($parsed['title'] ?? $name, 255),
            'meta_description' => $this->metaDescriptionFromText($description),
        ];

        if ($product instanceof Product) {
            $product->fill($attributes);
            $product->save();
            $this->attachToStagingCategory($product);

            return array_merge($stats, ['status' => 'updated']);
        }

        $created = Product::query()->create($attributes);
        $this->attachToStagingCategory($created);

        return array_merge($stats, ['status' => 'created']);
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

    private function findExistingProduct(string $name, ?string $brand): ?Product
    {
        return Product::query()
            ->where('name', $name)
            ->when(
                $brand !== null,
                fn ($query) => $query->where('brand', $brand),
                fn ($query) => $query->whereNull('brand')
            )
            ->first();
    }

    /**
     * @param  array<int, mixed>  $images
     * @return array{paths: array<int, string>, downloaded: int, failed: int, queued_derivatives: int}
     */
    private function downloadImagesToPublicDisk(array $images, string $pageUrl): array
    {
        $disk = Storage::disk('public');
        $downloaded = 0;
        $failed = 0;
        $queuedDerivatives = 0;
        $paths = [];
        $seen = [];

        foreach ($images as $image) {
            if (! is_string($image)) {
                continue;
            }

            $image = trim($image);

            if ($image === '') {
                continue;
            }

            $localPath = $this->extractLocalPublicPath($image);

            if ($localPath !== null) {
                if (! $disk->exists($localPath)) {
                    $failed++;

                    continue;
                }

                if (! isset($seen[$localPath])) {
                    $seen[$localPath] = true;
                    $paths[] = $localPath;
                    GenerateImageDerivativesJob::dispatch($localPath, false);
                    $queuedDerivatives++;
                }

                continue;
            }

            $remoteUrl = $this->resolveRemoteImageUrl($image, $pageUrl);

            if ($remoteUrl === null) {
                $failed++;

                continue;
            }

            try {
                $response = Http::timeout(20)
                    ->retry(2, 300)
                    ->accept('image/*')
                    ->get($remoteUrl);

                if (! $response->ok()) {
                    $failed++;

                    continue;
                }

                $body = (string) $response->body();

                if ($body === '') {
                    $failed++;

                    continue;
                }

                $extension = $this->imageExtensionFromResponse($response->header('Content-Type'), $body);

                if ($extension === null) {
                    $failed++;

                    continue;
                }

                $path = $this->buildStorageImagePath($remoteUrl, $extension);

                if (! $disk->exists($path)) {
                    if (! $disk->put($path, $body)) {
                        $failed++;

                        continue;
                    }

                    $downloaded++;
                }

                if (! isset($seen[$path])) {
                    $seen[$path] = true;
                    $paths[] = $path;
                    GenerateImageDerivativesJob::dispatch($path, false);
                    $queuedDerivatives++;
                }
            } catch (Throwable) {
                $failed++;
            }
        }

        return [
            'paths' => $paths,
            'downloaded' => $downloaded,
            'failed' => $failed,
            'queued_derivatives' => $queuedDerivatives,
        ];
    }

    private function extractLocalPublicPath(string $image): ?string
    {
        if (Str::startsWith($image, 'pics/')) {
            return $image;
        }

        if (Str::startsWith($image, '/storage/pics/')) {
            return Str::after($image, '/storage/');
        }

        if (Str::startsWith($image, 'storage/pics/')) {
            return Str::after($image, 'storage/');
        }

        return null;
    }

    private function resolveRemoteImageUrl(string $image, string $pageUrl): ?string
    {
        if (Str::startsWith($image, ['http://', 'https://'])) {
            return $image;
        }

        if (Str::startsWith($image, '//')) {
            $scheme = parse_url($pageUrl, PHP_URL_SCHEME);

            if (! is_string($scheme) || $scheme === '') {
                $scheme = 'https';
            }

            return $scheme.':'.$image;
        }

        $origin = $this->originFromUrl($pageUrl);

        if ($origin === null) {
            return null;
        }

        if (Str::startsWith($image, '/')) {
            return $origin.$image;
        }

        return $origin.'/'.ltrim($image, '/');
    }

    private function originFromUrl(string $url): ?string
    {
        $parts = parse_url($url);

        if (! is_array($parts)) {
            return null;
        }

        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;
        $port = $parts['port'] ?? null;

        if (! is_string($scheme) || ! is_string($host)) {
            return null;
        }

        $origin = $scheme.'://'.$host;

        if (is_int($port)) {
            $origin .= ':'.$port;
        }

        return $origin;
    }

    private function imageExtensionFromResponse(mixed $contentType, string $body): ?string
    {
        $extension = $this->imageExtensionFromContentType($contentType);

        if ($extension !== null) {
            return $extension;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            return null;
        }

        try {
            $mime = finfo_buffer($finfo, $body);
        } finally {
            finfo_close($finfo);
        }

        return $this->imageExtensionFromContentType($mime);
    }

    private function imageExtensionFromContentType(mixed $contentType): ?string
    {
        if (is_array($contentType)) {
            $contentType = $contentType[0] ?? null;
        }

        if (! is_string($contentType)) {
            return null;
        }

        $mime = strtolower(trim((string) strtok($contentType, ';')));

        return match ($mime) {
            'image/jpeg', 'image/jpg', 'image/pjpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'image/avif' => 'avif',
            default => null,
        };
    }

    private function buildStorageImagePath(string $remoteUrl, string $extension): string
    {
        $path = parse_url($remoteUrl, PHP_URL_PATH);
        $filename = is_string($path) ? pathinfo($path, PATHINFO_FILENAME) : '';
        $filename = trim((string) $filename);

        if ($filename === '') {
            $filename = substr(sha1($remoteUrl), 0, 24);
        }

        $filename = preg_replace('/[^a-z0-9_-]+/i', '_', $filename) ?? '';
        $filename = trim($filename, '_');

        if ($filename === '') {
            $filename = substr(sha1($remoteUrl), 0, 24);
        }

        if (is_string(parse_url($remoteUrl, PHP_URL_QUERY))) {
            $filename .= '_'.substr(sha1($remoteUrl), 0, 8);
        }

        return 'pics/'.$filename.'.'.$extension;
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return array{url: string, title: string, price: string, brand: string, images: string, specs: string}
     */
    private function sampleRow(array $parsed): array
    {
        return [
            'url' => (string) ($parsed['url'] ?? ''),
            'title' => (string) ($parsed['title'] ?? ''),
            'price' => (string) $this->normalizePriceAmount($parsed['price'] ?? null),
            'brand' => (string) ($parsed['brand'] ?? ''),
            'images' => (string) count($this->normalizeImages($parsed['images'] ?? [])),
            'specs' => (string) count($parsed['specs'] ?? []),
        ];
    }

    private function normalizeName(mixed $rawTitle): ?string
    {
        if (is_string($rawTitle) && trim($rawTitle) !== '') {
            return (string) Str::limit(trim($rawTitle), 255, '');
        }

        return null;
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

    private function resolveInStock(mixed $rawAvailability, ?int $quantity): bool
    {
        if ($quantity !== null) {
            return $quantity > 0;
        }

        if (! is_string($rawAvailability)) {
            return false;
        }

        $availability = mb_strtolower($rawAvailability);
        $availability = preg_replace('/\s+/u', '', $availability) ?? $availability;

        if (
            Str::contains($availability, [
                'outofstock',
                'out_of_stock',
                'out-of-stock',
                'unavailable',
                'нетвналичии',
                'soldout',
            ])
        ) {
            return false;
        }

        return Str::contains($availability, [
            'instock',
            'in_stock',
            'in-stock',
            'available',
            'вналичии',
        ]);
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
}
