<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SitemapBucketsCommand extends Command
{
    protected $signature = 'parser:sitemap-buckets
        {--sitemap=https://metalmaster.ru/sitemap.xml}
        {--exclude-news=1}';

    protected $description = 'Build category buckets from sitemap';

    public function handle(): int
    {
        $sitemap = (string) $this->option('sitemap');

        $xmlRaw = Http::timeout(25)->retry(2, 300)->get($sitemap)->body();
        $xml = simplexml_load_string($xmlRaw);

        if (!$xml || $xml->getName() !== 'urlset') {
            $this->error('Unexpected sitemap format (expected urlset).');
            return self::FAILURE;
        }

        $ns = $xml->getNamespaces(true);
        $urls = $xml->children($ns[''] ?? null)->url ?? [];

        $buckets = []; // [bucket => ['category_url' => ?, 'products' => []]]

        foreach ($urls as $u) {
            $loc = trim((string) $u->loc);
            if ($loc === '') continue;

            $path = trim(parse_url($loc, PHP_URL_PATH) ?? '', '/');
            if ($path === '') continue;

            $parts = explode('/', $path);
            $depth = count($parts);

            if ($depth === 1) {
                $bucket = $parts[0];
                $buckets[$bucket] ??= ['category_url' => null, 'products' => []];
                $buckets[$bucket]['category_url'] = $loc;
            } elseif ($depth === 2) {
                $bucket = $parts[0];
                if ((bool) $this->option('exclude-news') && $bucket === 'news') {
                    continue;
                }

                $buckets[$bucket] ??= ['category_url' => null, 'products' => []];
                $buckets[$bucket]['products'][] = $loc;
            }
        }

        // Сохраняем только бакеты, где есть товары
        $buckets = array_filter($buckets, fn($v) => count($v['products']) > 0);

        // Можно сохранить в БД; для примера — в storage/json
        $payload = [];
        foreach ($buckets as $bucket => $data) {
            $payload[] = [
                'bucket' => $bucket,
                'category_url' => $data['category_url'] ?: "https://metalmaster.ru/{$bucket}/",
                'products_count' => count($data['products']),
                'product_urls' => array_values(array_unique($data['products'])),
            ];
        }

        usort($payload, fn($a, $b) => $b['products_count'] <=> $a['products_count']);

        $file = storage_path('app/parser/metalmaster-buckets.json');
        @mkdir(dirname($file), 0777, true);
        file_put_contents($file, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $this->info('Buckets saved: ' . count($payload));
        $this->info('File: ' . $file);

        return self::SUCCESS;
    }
}
