<?php

namespace App\Support\Metalmaster;

use Illuminate\Support\Str;
use RuntimeException;

class MetalmasterSitemapBucketService
{
    public function __construct(private MetalmasterSitemapCrawler $crawler) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array{
     *     sitemap: string,
     *     latest_file: string,
     *     snapshot_file: string|null,
     *     meta_file: string,
     *     scanned_urls: int,
     *     buckets_count: int,
     *     products_count: int,
     *     payload: array<int, array{bucket: string, category_url: string, products_count: int, product_urls: array<int, string>}>
     * }
     */
    public function build(array $options = []): array
    {
        $normalized = $this->normalizeOptions($options);
        $urls = $this->crawler->collectUrls($normalized['sitemap']);
        $bucketMap = $this->buildBucketMap($urls, $normalized['exclude_news']);
        $payload = $this->buildPayload($bucketMap);

        $productsCount = (int) collect($payload)->sum('products_count');
        $generatedAt = now();
        $timestamp = $generatedAt->format('Ymd-His');

        $snapshotFile = null;

        $meta = [
            'generated_at' => $generatedAt->toIso8601String(),
            'sitemap' => $normalized['sitemap'],
            'exclude_news' => $normalized['exclude_news'],
            'scanned_urls' => count($urls),
            'buckets_count' => count($payload),
            'products_count' => $productsCount,
            'version' => 1,
        ];

        $this->writeJson($normalized['output_file'], $payload);

        if ($normalized['with_snapshot']) {
            $snapshotFile = rtrim($normalized['snapshot_dir'], '/')."/buckets-{$timestamp}.json";

            $this->writeJson($snapshotFile, [
                'meta' => $meta,
                'buckets' => $payload,
            ]);
        }

        $metaFile = $this->resolveMetaFilePath($normalized['output_file']);

        $this->writeJson($metaFile, array_merge($meta, [
            'latest_file' => $normalized['output_file'],
            'snapshot_file' => $snapshotFile,
        ]));

        return [
            'sitemap' => $normalized['sitemap'],
            'latest_file' => $normalized['output_file'],
            'snapshot_file' => $snapshotFile,
            'meta_file' => $metaFile,
            'scanned_urls' => count($urls),
            'buckets_count' => count($payload),
            'products_count' => $productsCount,
            'payload' => $payload,
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{
     *     sitemap: string,
     *     exclude_news: bool,
     *     output_file: string,
     *     snapshot_dir: string,
     *     with_snapshot: bool
     * }
     */
    private function normalizeOptions(array $options): array
    {
        return [
            'sitemap' => trim((string) ($options['sitemap'] ?? 'https://metalmaster.ru/sitemap.xml')),
            'exclude_news' => (bool) ($options['exclude_news'] ?? $options['exclude-news'] ?? true),
            'output_file' => trim((string) (
                $options['output_file']
                ?? $options['output-file']
                ?? storage_path('app/parser/metalmaster-buckets.json')
            )),
            'snapshot_dir' => trim((string) (
                $options['snapshot_dir']
                ?? $options['snapshot-dir']
                ?? storage_path('app/parser/metalmaster')
            )),
            'with_snapshot' => (bool) ($options['with_snapshot'] ?? $options['with-snapshot'] ?? true),
        ];
    }

    /**
     * @param  array<int, string>  $urls
     * @return array<string, array{category_url: string|null, product_urls: array<int, string>}>
     */
    private function buildBucketMap(array $urls, bool $excludeNews): array
    {
        $buckets = [];

        foreach ($urls as $url) {
            if (! filter_var($url, FILTER_VALIDATE_URL)) {
                continue;
            }

            $path = parse_url($url, PHP_URL_PATH);

            if (! is_string($path)) {
                continue;
            }

            $path = trim($path, '/');

            if ($path === '') {
                continue;
            }

            $parts = array_values(array_filter(explode('/', $path), fn (string $part): bool => $part !== ''));
            $depth = count($parts);

            if ($depth === 1) {
                $bucket = $parts[0];
                $buckets[$bucket] ??= ['category_url' => null, 'product_urls' => []];
                $buckets[$bucket]['category_url'] = $url;

                continue;
            }

            if ($depth !== 2) {
                continue;
            }

            $bucket = $parts[0];

            if ($excludeNews && $bucket === 'news') {
                continue;
            }

            $buckets[$bucket] ??= ['category_url' => null, 'product_urls' => []];
            $buckets[$bucket]['product_urls'][] = $url;
        }

        return array_filter(
            $buckets,
            fn (array $bucket): bool => $bucket['product_urls'] !== []
        );
    }

    /**
     * @param  array<string, array{category_url: string|null, product_urls: array<int, string>}>  $bucketMap
     * @return array<int, array{bucket: string, category_url: string, products_count: int, product_urls: array<int, string>}>
     */
    private function buildPayload(array $bucketMap): array
    {
        $payload = [];

        foreach ($bucketMap as $bucket => $data) {
            $productUrls = collect($data['product_urls'])
                ->filter(fn (mixed $url): bool => is_string($url) && $url !== '')
                ->unique()
                ->values()
                ->all();

            if ($productUrls === []) {
                continue;
            }

            $payload[] = [
                'bucket' => $bucket,
                'category_url' => $data['category_url'] ?: "https://metalmaster.ru/{$bucket}/",
                'products_count' => count($productUrls),
                'product_urls' => $productUrls,
            ];
        }

        usort(
            $payload,
            fn (array $left, array $right): int => $right['products_count'] <=> $left['products_count']
        );

        return $payload;
    }

    private function resolveMetaFilePath(string $outputFile): string
    {
        if (Str::endsWith($outputFile, '.json')) {
            return (string) Str::replaceLast('.json', '.meta.json', $outputFile);
        }

        return $outputFile.'.meta.json';
    }

    private function writeJson(string $path, mixed $payload): void
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException("Не удалось создать директорию: {$directory}");
        }

        $encoded = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );

        if (! is_string($encoded)) {
            throw new RuntimeException("Не удалось сериализовать JSON: {$path}");
        }

        if (file_put_contents($path, $encoded) === false) {
            throw new RuntimeException("Не удалось записать файл: {$path}");
        }
    }
}
