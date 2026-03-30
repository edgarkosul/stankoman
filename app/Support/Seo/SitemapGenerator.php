<?php

namespace App\Support\Seo;

use App\Models\Category;
use App\Models\Page;
use App\Models\Product;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

class SitemapGenerator
{
    private const STATIC_FILENAME = 'sitemap-static.xml';

    private const CATEGORIES_FILENAME = 'sitemap-categories.xml';

    private const INDEX_FILENAME = 'sitemap.xml';

    private const PRODUCTS_PREFIX = 'sitemap-products-';

    private const PRODUCTS_PER_FILE = 45000;

    private const PRODUCT_DB_CHUNK = 5000;

    private const ROBOTS_FILENAME = 'robots.txt';

    /**
     * @return array{
     *     index: string,
     *     static: string,
     *     categories: string,
     *     robots: string,
     *     product_sitemaps: int,
     *     product_urls: int
     * }
     */
    public function generate(): array
    {
        $baseUrl = $this->baseUrl();
        $publicPath = public_path();
        $paths = $this->buildPaths($publicPath);

        File::ensureDirectoryExists($paths['dir']);

        $this->writeStaticSitemap($paths['static'], $baseUrl);
        $this->writeCategoriesSitemap($paths['categories'], $baseUrl);

        $existingProductFiles = $this->listExistingProductSitemaps($paths['dir']);
        $productsResult = $this->writeProductsSitemaps($paths['dir'], $baseUrl);

        $this->writeSitemapIndex(
            $paths['index'],
            [
                $this->absoluteUrl(self::STATIC_FILENAME, $baseUrl),
                $this->absoluteUrl(self::CATEGORIES_FILENAME, $baseUrl),
                ...$productsResult['urls'],
            ],
        );

        $this->writeRobotsFile($paths['robots'], $baseUrl);
        $this->cleanupStaleProductSitemaps($existingProductFiles, $productsResult['files']);

        return [
            'index' => $paths['index'],
            'static' => $paths['static'],
            'categories' => $paths['categories'],
            'robots' => $paths['robots'],
            'product_sitemaps' => count($productsResult['files']),
            'product_urls' => $productsResult['urls_count'],
        ];
    }

    /**
     * @return array{dir: string, static: string, categories: string, index: string, robots: string}
     */
    private function buildPaths(string $publicPath): array
    {
        $base = rtrim($publicPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return [
            'dir' => $base,
            'static' => $this->joinPath($base, self::STATIC_FILENAME),
            'categories' => $this->joinPath($base, self::CATEGORIES_FILENAME),
            'index' => $this->joinPath($base, self::INDEX_FILENAME),
            'robots' => $this->joinPath($base, self::ROBOTS_FILENAME),
        ];
    }

    private function writeStaticSitemap(string $targetPath, string $baseUrl): void
    {
        $homePage = Page::query()
            ->where('slug', 'home')
            ->where('is_published', true)
            ->first(['updated_at']);

        $fallbackDate = Carbon::now();
        $pages = Page::query()
            ->where('is_published', true)
            ->where('slug', '!=', 'home')
            ->whereNotNull('slug')
            ->orderBy('slug')
            ->get(['slug', 'updated_at']);

        $this->writeUrlset($targetPath, function (\XMLWriter $xml) use ($baseUrl, $homePage, $fallbackDate, $pages): void {
            $this->writeUrlEntry($xml, $this->absoluteUrl('/', $baseUrl), $homePage?->updated_at ?? $fallbackDate);

            foreach ($pages as $page) {
                $slug = trim((string) $page->slug);

                if ($slug === '') {
                    continue;
                }

                $this->writeUrlEntry(
                    $xml,
                    $this->absoluteUrl('/page/'.$slug, $baseUrl),
                    $page->updated_at ?? $fallbackDate,
                );
            }
        });
    }

    private function writeCategoriesSitemap(string $targetPath, string $baseUrl): void
    {
        $rows = Category::query()
            ->active()
            ->withoutStaging()
            ->select(['id', 'parent_id', 'slug', 'updated_at'])
            ->orderBy('id')
            ->get();

        $byId = $rows->keyBy('id');
        $rootId = Category::defaultParentKey();
        $cache = [];
        $fallbackDate = Carbon::now();

        $resolvePath = static function (Category $category) use (&$cache, $byId, $rootId): string {
            if (isset($cache[$category->id])) {
                return $cache[$category->id];
            }

            $segments = [];
            $node = $category;

            while ($node) {
                $segments[] = $node->slug;

                if ($node->parent_id === $rootId) {
                    break;
                }

                $node = $byId->get($node->parent_id);
            }

            return $cache[$category->id] = implode('/', array_reverse($segments));
        };

        $this->writeUrlset($targetPath, function (\XMLWriter $xml) use ($baseUrl, $rows, $resolvePath, $fallbackDate): void {
            foreach ($rows as $category) {
                $path = trim((string) $resolvePath($category), '/');

                if ($path === '') {
                    continue;
                }

                $this->writeUrlEntry(
                    $xml,
                    $this->absoluteUrl('/catalog/'.$path, $baseUrl),
                    $category->updated_at ?? $fallbackDate,
                );
            }
        });
    }

    /**
     * @return array{files: list<string>, urls: list<string>, urls_count: int}
     */
    private function writeProductsSitemaps(string $directory, string $baseUrl): array
    {
        $files = [];
        $urls = [];
        $currentWriter = null;
        $currentPath = null;
        $currentCount = 0;
        $fileNumber = 1;
        $urlsCount = 0;
        $fallbackDate = Carbon::now();

        $openFile = function () use ($directory, &$currentWriter, &$currentPath, &$fileNumber): void {
            $filename = self::PRODUCTS_PREFIX.$fileNumber.'.xml';
            $currentPath = $this->joinPath($directory, $filename);
            $currentWriter = $this->startUrlsetWriter($currentPath);
        };

        $flushFile = function () use (
            &$currentWriter,
            &$currentPath,
            &$currentCount,
            &$fileNumber,
            &$files,
            &$urls,
            $baseUrl
        ): void {
            if (! $currentWriter instanceof \XMLWriter || ! is_string($currentPath) || $currentCount === 0) {
                return;
            }

            $this->finishUrlsetWriter($currentWriter, $currentPath);

            $files[] = $currentPath;
            $urls[] = $this->absoluteUrl(basename($currentPath), $baseUrl);

            $currentWriter = null;
            $currentPath = null;
            $currentCount = 0;
            $fileNumber++;
        };

        Product::query()
            ->select(['id', 'slug', 'updated_at'])
            ->where('is_active', true)
            ->whereNotNull('slug')
            ->orderBy('id')
            ->chunkById(self::PRODUCT_DB_CHUNK, function ($products) use (
                &$currentWriter,
                &$currentCount,
                &$urlsCount,
                $fallbackDate,
                $baseUrl,
                $openFile,
                $flushFile
            ): void {
                foreach ($products as $product) {
                    $slug = trim((string) $product->slug);

                    if ($slug === '') {
                        continue;
                    }

                    if (! $currentWriter instanceof \XMLWriter) {
                        $openFile();
                    }

                    $this->writeUrlEntry(
                        $currentWriter,
                        $this->absoluteUrl('/product/'.$slug, $baseUrl),
                        $product->updated_at ?? $fallbackDate,
                    );

                    $currentCount++;
                    $urlsCount++;

                    if ($currentCount >= self::PRODUCTS_PER_FILE) {
                        $flushFile();
                    }
                }
            });

        $flushFile();

        return [
            'files' => $files,
            'urls' => $urls,
            'urls_count' => $urlsCount,
        ];
    }

    /**
     * @param  iterable<string>  $sitemapUrls
     */
    private function writeSitemapIndex(string $targetPath, iterable $sitemapUrls): void
    {
        $tmpPath = $targetPath.'.tmp';
        $xml = new \XMLWriter;
        $xml->openUri($tmpPath);
        $xml->startDocument('1.0', 'UTF-8');
        $xml->setIndent(true);
        $xml->startElement('sitemapindex');
        $xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');

        foreach ($sitemapUrls as $sitemapUrl) {
            $xml->startElement('sitemap');
            $xml->writeElement('loc', $sitemapUrl);
            $xml->endElement();
        }

        $xml->endElement();
        $xml->endDocument();
        $xml->flush();

        $this->moveTempFile($tmpPath, $targetPath);
    }

    /**
     * @param  callable(\XMLWriter): void  $writer
     */
    private function writeUrlset(string $targetPath, callable $writer): void
    {
        $xml = $this->startUrlsetWriter($targetPath);
        $writer($xml);
        $this->finishUrlsetWriter($xml, $targetPath);
    }

    private function startUrlsetWriter(string $targetPath): \XMLWriter
    {
        File::ensureDirectoryExists(dirname($targetPath));

        $xml = new \XMLWriter;
        $xml->openUri($targetPath.'.tmp');
        $xml->startDocument('1.0', 'UTF-8');
        $xml->setIndent(true);
        $xml->startElement('urlset');
        $xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');

        return $xml;
    }

    private function finishUrlsetWriter(\XMLWriter $xml, string $targetPath): void
    {
        $xml->endElement();
        $xml->endDocument();
        $xml->flush();

        $this->moveTempFile($targetPath.'.tmp', $targetPath);
    }

    private function writeUrlEntry(\XMLWriter $xml, string $url, CarbonInterface $lastModificationDate): void
    {
        $xml->startElement('url');
        $xml->writeElement('loc', $url);
        $xml->writeElement('lastmod', $lastModificationDate->toAtomString());
        $xml->endElement();
    }

    private function writeRobotsFile(string $targetPath, string $baseUrl): void
    {
        $content = $this->shouldAllowIndexing()
            ? implode("\n", [
                'User-agent: *',
                'Disallow: /admin/',
                'Disallow: /login',
                'Disallow: /register',
                'Disallow: /password/',
                'Disallow: /user/',
                'Disallow: /cart',
                'Disallow: /compare',
                'Disallow: /favorites',
                'Disallow: /checkout',
                'Disallow: /livewire/',
                'Disallow: /api/',
                'Sitemap: '.$this->absoluteUrl(self::INDEX_FILENAME, $baseUrl),
                '',
            ])
            : "User-agent: *\nDisallow: /\n";

        File::ensureDirectoryExists(dirname($targetPath));
        File::put($targetPath.'.tmp', $content);

        $this->moveTempFile($targetPath.'.tmp', $targetPath);
    }

    private function shouldAllowIndexing(): bool
    {
        $value = config('app.robots_allow_indexing');

        if ($value === null) {
            return ! app()->environment(['local', 'testing']);
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        return $parsed ?? (bool) $value;
    }

    /**
     * @return list<string>
     */
    private function listExistingProductSitemaps(string $directory): array
    {
        $directory = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return array_values(array_unique([
            ...(File::glob($directory.self::PRODUCTS_PREFIX.'*.xml') ?: []),
            ...(File::glob($directory.self::PRODUCTS_PREFIX.'*.xml.tmp') ?: []),
        ]));
    }

    /**
     * @param  list<string>  $existingFiles
     * @param  list<string>  $keepFiles
     */
    private function cleanupStaleProductSitemaps(array $existingFiles, array $keepFiles): void
    {
        $keep = array_fill_keys(array_map('strval', $keepFiles), true);

        foreach ($keepFiles as $file) {
            $keep[$file.'.tmp'] = true;
        }

        foreach ($existingFiles as $file) {
            if (! isset($keep[$file]) && is_file($file)) {
                @unlink($file);
            }
        }
    }

    private function moveTempFile(string $tmpPath, string $targetPath): void
    {
        if (! rename($tmpPath, $targetPath)) {
            throw new \RuntimeException("Unable to move [{$tmpPath}] to [{$targetPath}].");
        }
    }

    private function baseUrl(): string
    {
        $configured = trim((string) config('company.site_url', ''));

        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        return rtrim((string) config('app.url'), '/');
    }

    private function absoluteUrl(string $path, string $baseUrl): string
    {
        return rtrim($baseUrl, '/').'/'.ltrim($path, '/');
    }

    private function joinPath(string $base, string $filename): string
    {
        return rtrim($base, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.ltrim($filename, DIRECTORY_SEPARATOR);
    }
}
