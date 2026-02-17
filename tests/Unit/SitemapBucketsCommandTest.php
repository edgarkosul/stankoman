<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

it('builds metalmaster buckets and writes latest meta and snapshot files', function () {
    Http::preventStrayRequests();

    $sitemap = 'https://metalmaster.ru/sitemap.xml';
    $catalogSitemap = 'https://metalmaster.ru/catalog-sitemap.xml';
    $newsSitemap = 'https://metalmaster.ru/news-sitemap.xml';

    Http::fake([
        $sitemap => Http::response(metalmasterBucketsSitemapIndexXml([
            $catalogSitemap,
            $newsSitemap,
        ]), 200),
        $catalogSitemap => Http::response(metalmasterBucketsSitemapUrlsetXml([
            'https://metalmaster.ru/promyshlennye/',
            'https://metalmaster.ru/promyshlennye/z50100-dro/',
            'https://metalmaster.ru/promyshlennye/z46100/',
            'https://metalmaster.ru/instrument/',
            'https://metalmaster.ru/instrument/nabor-sverl-hss/',
        ]), 200),
        $newsSitemap => Http::response(metalmasterBucketsSitemapUrlsetXml([
            'https://metalmaster.ru/news/',
            'https://metalmaster.ru/news/new-year/',
        ]), 200),
    ]);

    $suffix = Str::lower(Str::random(10));
    $outputFile = storage_path("app/testing/metalmaster-buckets-{$suffix}.json");
    $snapshotDir = storage_path("app/testing/metalmaster-buckets-snapshots-{$suffix}");
    $metaFile = Str::replaceLast('.json', '.meta.json', $outputFile);

    try {
        $this->artisan('parser:sitemap-buckets', [
            '--sitemap' => $sitemap,
            '--exclude-news' => 1,
            '--output-file' => $outputFile,
            '--snapshot-dir' => $snapshotDir,
            '--with-snapshot' => 1,
        ])
            ->expectsOutputToContain('Buckets saved: 2')
            ->expectsOutputToContain("File: {$outputFile}")
            ->assertSuccessful();

        expect(is_file($outputFile))->toBeTrue();
        expect(is_file($metaFile))->toBeTrue();

        $latest = json_decode((string) file_get_contents($outputFile), true);

        expect($latest)->toBeArray()->toHaveCount(2);
        expect($latest[0]['bucket'])->toBe('promyshlennye');
        expect($latest[0]['products_count'])->toBe(2);
        expect($latest[1]['bucket'])->toBe('instrument');
        expect($latest[1]['products_count'])->toBe(1);

        $meta = json_decode((string) file_get_contents($metaFile), true);

        expect($meta)->toBeArray();
        expect($meta['sitemap'])->toBe($sitemap);
        expect($meta['buckets_count'])->toBe(2);
        expect($meta['products_count'])->toBe(3);

        $snapshotFile = $meta['snapshot_file'] ?? null;

        expect(is_string($snapshotFile))->toBeTrue();
        expect(is_file($snapshotFile))->toBeTrue();

        $snapshot = json_decode((string) file_get_contents($snapshotFile), true);

        expect($snapshot)->toBeArray();
        expect($snapshot['meta']['sitemap'])->toBe($sitemap);
        expect($snapshot['buckets'])->toHaveCount(2);
    } finally {
        @unlink($outputFile);
        @unlink($metaFile);

        if (is_dir($snapshotDir)) {
            $files = glob($snapshotDir.'/*');

            if (is_array($files)) {
                foreach ($files as $file) {
                    @unlink($file);
                }
            }

            @rmdir($snapshotDir);
        }
    }
});

function metalmasterBucketsSitemapIndexXml(array $sitemaps): string
{
    $items = array_map(
        static fn (string $url): string => '<sitemap><loc>'.$url.'</loc></sitemap>',
        $sitemaps
    );

    return '<?xml version="1.0" encoding="UTF-8"?>'
        .'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
        .implode('', $items)
        .'</sitemapindex>';
}

function metalmasterBucketsSitemapUrlsetXml(array $urls): string
{
    $items = array_map(
        static fn (string $url): string => '<url><loc>'.$url.'</loc></url>',
        $urls
    );

    return '<?xml version="1.0" encoding="UTF-8"?>'
        .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
        .implode('', $items)
        .'</urlset>';
}
