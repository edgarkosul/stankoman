<?php

namespace App\Support\Vactool;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use SimpleXMLElement;

class VactoolSitemapCrawler
{
    public function collectUrls(string $sitemapUrl): array
    {
        $visited = [];

        $urls = $this->collectRecursively($sitemapUrl, $visited);

        return array_values(array_unique($urls));
    }

    private function collectRecursively(string $sitemapUrl, array &$visited): array
    {
        if (isset($visited[$sitemapUrl])) {
            return [];
        }

        $visited[$sitemapUrl] = true;

        $response = Http::timeout(20)
            ->retry(2, 300)
            ->get($sitemapUrl);

        if (! $response->ok()) {
            throw new RuntimeException("Не удалось скачать sitemap: {$sitemapUrl}");
        }

        $payload = $response->body();

        if ($this->isGzipPath($sitemapUrl)) {
            $decoded = @gzdecode($payload);
            if ($decoded !== false) {
                $payload = $decoded;
            }
        }

        $xml = $this->parseXml($payload, $sitemapUrl);
        $root = $xml->getName();

        if ($root === 'urlset') {
            return $this->extractLocValues($xml, 'url');
        }

        if ($root !== 'sitemapindex') {
            return [];
        }

        $urls = [];

        foreach ($this->extractLocValues($xml, 'sitemap') as $childSitemapUrl) {
            $urls = array_merge($urls, $this->collectRecursively($childSitemapUrl, $visited));
        }

        return $urls;
    }

    private function isGzipPath(string $sitemapUrl): bool
    {
        $path = parse_url($sitemapUrl, PHP_URL_PATH) ?? '';

        return Str::endsWith((string) $path, '.gz');
    }

    private function parseXml(string $payload, string $sitemapUrl): SimpleXMLElement
    {
        $xml = @simplexml_load_string($payload);

        if ($xml === false) {
            throw new RuntimeException("Некорректный XML: {$sitemapUrl}");
        }

        return $xml;
    }

    private function extractLocValues(SimpleXMLElement $xml, string $containerNode): array
    {
        $locations = $xml->xpath(
            sprintf('/*[local-name()="%s"]/*[local-name()="%s"]/*[local-name()="loc"]', $xml->getName(), $containerNode)
        );

        if (! is_array($locations)) {
            return [];
        }

        $urls = [];

        foreach ($locations as $location) {
            $value = trim((string) $location);

            if ($value === '') {
                continue;
            }

            $urls[] = $value;
        }

        return $urls;
    }
}
