<?php

namespace App\Console\Commands;

use App\Jobs\GenerateImageDerivativesJob;
use App\Models\Product;
use App\Support\Vactool\VactoolProductParser;
use App\Support\Vactool\VactoolSitemapCrawler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ParseVactoolProducts extends Command
{
    protected $signature = 'products:parse-vactool
                            {--sitemap=https://vactool.ru/sitemap.xml : Sitemap URL}
                            {--match=/catalog/product- : URL fragment used for product pages}
                            {--limit=0 : Max product URLs to process (0 = all)}
                            {--delay-ms=250 : Delay between product requests in milliseconds}
                            {--write : Save parsed products into the local DB}
                            {--publish : Set imported products as active}
                            {--download-images : Download image URLs into storage/app/public/pics and use local paths}
                            {--skip-existing : Skip existing products by local key (name + brand)}
                            {--show-samples=3 : Max number of sample rows in dry-run mode}';

    protected $description = 'Parse product pages from vactool sitemap';

    public function __construct(
        private VactoolSitemapCrawler $sitemapCrawler,
        private VactoolProductParser $productParser,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $sitemap = (string) $this->option('sitemap');
        $match = (string) $this->option('match');
        $limit = max(0, (int) $this->option('limit'));
        $delayMs = max(0, (int) $this->option('delay-ms'));
        $writeMode = (bool) $this->option('write');
        $publish = (bool) $this->option('publish');
        $downloadImages = (bool) $this->option('download-images');
        $skipExisting = (bool) $this->option('skip-existing');
        $sampleLimit = max(0, (int) $this->option('show-samples'));

        try {
            $allUrls = $this->sitemapCrawler->collectUrls($sitemap);
        } catch (Throwable $exception) {
            $this->error('Не удалось прочитать sitemap: '.$exception->getMessage());

            return self::FAILURE;
        }

        $productUrls = collect($allUrls)
            ->filter(function (mixed $url) use ($match): bool {
                if (! is_string($url)) {
                    return false;
                }

                return $match === '' || Str::contains($url, $match);
            })
            ->unique()
            ->values();

        if ($limit > 0) {
            $productUrls = $productUrls->take($limit)->values();
        }

        if ($productUrls->isEmpty()) {
            $this->warn('Подходящие URL товаров не найдены.');

            return self::SUCCESS;
        }

        $this->info('Найдено URL товаров: '.$productUrls->count());
        $this->line('Режим: '.($writeMode ? 'write' : 'dry-run'));

        $processed = 0;
        $errors = 0;
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $imagesDownloaded = 0;
        $imageDownloadFailed = 0;
        $derivativesQueued = 0;
        $samples = [];

        foreach ($productUrls as $url) {
            try {
                $response = Http::timeout(20)
                    ->retry(2, 300)
                    ->get($url);

                if (! $response->ok()) {
                    throw new RuntimeException('HTTP '.$response->status());
                }

                $parsed = $this->productParser->parse((string) $response->body(), $url);

                if ($writeMode) {
                    $result = $this->storeProduct(
                        $parsed,
                        $publish,
                        $downloadImages,
                        $skipExisting,
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
                } elseif (count($samples) < $sampleLimit) {
                    $samples[] = $this->sampleRow($parsed);
                }

                $processed++;
                $this->line('OK: '.$url);

                if ($delayMs > 0) {
                    usleep($delayMs * 1000);
                }
            } catch (Throwable $exception) {
                $errors++;
                $this->error('ERR: '.$url.' | '.$exception->getMessage());
            }
        }

        $this->newLine();
        $this->info('Итого: processed='.$processed.', errors='.$errors.'.');

        if ($writeMode) {
            $this->line('DB: created='.$created.', updated='.$updated.', skipped='.$skipped.'.');

            if ($downloadImages) {
                $this->line(
                    'Images: downloaded='.$imagesDownloaded
                    .', failed='.$imageDownloadFailed
                    .', derivatives_queued='.$derivativesQueued
                    .'.'
                );
            }
        }

        if (! $writeMode && $samples !== []) {
            $this->newLine();
            $this->table(['url', 'title', 'price', 'brand', 'images', 'specs'], $samples);
        }

        return $processed > 0 ? self::SUCCESS : self::FAILURE;
    }

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
            'specs' => $this->formatSpecs($parsed['specs'] ?? []),
            'image' => $images[0] ?? null,
            'thumb' => $images[0] ?? null,
            'gallery' => $images,
            'meta_title' => $this->limit($parsed['title'] ?? $name, 255),
            'meta_description' => $this->metaDescriptionFromText($description),
        ];

        if ($product instanceof Product) {
            $product->fill($attributes);
            $product->save();

            return array_merge($stats, ['status' => 'updated']);
        }

        Product::query()->create($attributes);

        return array_merge($stats, ['status' => 'created']);
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

    private function formatSpecs(mixed $rawSpecs): ?string
    {
        if (! is_array($rawSpecs)) {
            return null;
        }

        $lines = [];

        foreach ($rawSpecs as $spec) {
            if (! is_array($spec)) {
                continue;
            }

            $name = $this->limit($spec['name'] ?? null, 255);
            $value = $this->limit($spec['value'] ?? null, 1000);

            if ($name === null || $value === null) {
                continue;
            }

            $lines[] = $name.': '.$value;
        }

        if ($lines === []) {
            return null;
        }

        return implode(PHP_EOL, $lines);
    }

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
