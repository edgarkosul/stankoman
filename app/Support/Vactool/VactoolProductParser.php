<?php

namespace App\Support\Vactool;

use DOMDocument;
use DOMXPath;
use Symfony\Component\DomCrawler\Crawler;

class VactoolProductParser
{
    public function parse(string $html, string $url): array
    {
        $result = [
            'url' => $url,
            'title' => null,
            'description' => null,
            'category' => null,
            'images' => [],
            'specs' => [],
            'price' => null,
            'currency' => null,
            'availability' => null,
            'stock_qty' => null,
            'brand' => null,
            'breadcrumbs' => [],
            'source' => [
                'jsonld' => false,
                'inertia' => false,
            ],
        ];

        $crawler = new Crawler($html, $url);

        $this->parseJsonLd($crawler, $result);
        $this->parseInertia($crawler, $result);
        $result['specs'] = array_merge($result['specs'], $this->extractSpecsFromHtml($html));

        $result['images'] = $this->uniqueStrings($result['images']);
        $result['specs'] = $this->uniqueSpecs($result['specs']);
        $result['breadcrumbs'] = $this->uniqueStrings($result['breadcrumbs']);

        return $result;
    }

    private function parseJsonLd(Crawler $crawler, array &$result): void
    {
        $scripts = $crawler->filter('script[type="application/ld+json"]');

        foreach ($scripts as $script) {
            $payload = trim((string) $script->textContent);

            if ($payload === '') {
                continue;
            }

            $decoded = json_decode($payload, true);

            if (! is_array($decoded)) {
                continue;
            }

            foreach ($this->flattenJsonLdItems($decoded) as $item) {
                if ($this->hasType($item, 'Product')) {
                    $this->applyProductJsonLd($item, $result);
                }

                if ($this->hasType($item, 'BreadcrumbList')) {
                    $this->applyBreadcrumbJsonLd($item, $result);
                }
            }
        }
    }

    private function parseInertia(Crawler $crawler, array &$result): void
    {
        $appNode = $crawler->filter('#app');

        if ($appNode->count() === 0) {
            return;
        }

        $rawPageData = $appNode->attr('data-page');

        if (! is_string($rawPageData) || $rawPageData === '') {
            return;
        }

        $decodedData = html_entity_decode($rawPageData, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $page = json_decode($decodedData, true);

        if (! is_array($page)) {
            return;
        }

        $product = data_get($page, 'props.product', []);

        if (! is_array($product)) {
            return;
        }

        $result['source']['inertia'] = true;

        $result['title'] = $result['title'] ?? $this->sanitizeString(
            data_get($product, 'title') ?? data_get($product, 'name')
        );

        $result['description'] = $result['description'] ?? $this->sanitizeString(
            data_get($product, 'description') ?? data_get($product, 'content')
        );

        $result['brand'] = $result['brand'] ?? $this->extractBrand(data_get($product, 'brand'));

        $result['category'] = $result['category'] ?? $this->extractCategoryName(
            data_get($product, 'category')
        );

        $offer = data_get($product, 'offer', []);

        if (is_array($offer)) {
            $result['price'] = $result['price'] ?? data_get($offer, 'price.unitValue') ?? data_get($offer, 'price');
            $result['currency'] = $result['currency'] ?? $this->sanitizeString(data_get($offer, 'price.currency'));
            $result['stock_qty'] = $result['stock_qty'] ?? data_get($offer, 'available');
            $result['availability'] = $result['availability'] ?? $this->sanitizeString(
                data_get($offer, 'stock.status') ?? data_get($offer, 'availability')
            );
        }

        foreach (['images', 'gallery'] as $imageKey) {
            $result['images'] = array_merge($result['images'], $this->extractImageCandidates(data_get($product, $imageKey)));
        }

        $specCandidates = [
            data_get($product, 'specs'),
            data_get($product, 'attributes'),
            data_get($product, 'characteristics'),
            data_get($product, 'properties'),
            data_get($page, 'props.specs'),
            data_get($page, 'props.attributes'),
            data_get($page, 'props.characteristics'),
        ];

        foreach ($specCandidates as $candidate) {
            $result['specs'] = array_merge($result['specs'], $this->extractSpecsFromPayload($candidate, 'inertia'));
        }

        $breadcrumbs = data_get($page, 'props.breadcrumbs', []);

        if (is_array($breadcrumbs)) {
            foreach ($breadcrumbs as $breadcrumb) {
                if (! is_array($breadcrumb)) {
                    continue;
                }

                $name = $this->sanitizeString($breadcrumb['name'] ?? $breadcrumb['title'] ?? null);

                if ($name === null) {
                    continue;
                }

                $result['breadcrumbs'][] = $name;
            }
        }
    }

    private function applyProductJsonLd(array $item, array &$result): void
    {
        $result['source']['jsonld'] = true;

        $result['title'] = $result['title'] ?? $this->sanitizeString($item['name'] ?? null);
        $result['description'] = $result['description'] ?? $this->sanitizeString($item['description'] ?? null);
        $result['category'] = $result['category'] ?? $this->extractCategoryName($item['category'] ?? null);
        $result['brand'] = $result['brand'] ?? $this->extractBrand($item['brand'] ?? null);

        $result['images'] = array_merge($result['images'], $this->extractImageCandidates($item['image'] ?? null));

        $result['specs'] = array_merge(
            $result['specs'],
            $this->extractSpecsFromPayload($item['additionalProperty'] ?? null, 'jsonld')
        );

        $offer = $item['offers'] ?? null;

        if (is_array($offer) && array_is_list($offer)) {
            $offer = $offer[0] ?? null;
        }

        if (! is_array($offer)) {
            return;
        }

        $result['price'] = $result['price'] ?? ($offer['price'] ?? null);
        $result['currency'] = $result['currency'] ?? $this->sanitizeString($offer['priceCurrency'] ?? null);
        $result['availability'] = $result['availability'] ?? $this->sanitizeString($offer['availability'] ?? null);
        $result['stock_qty'] = $result['stock_qty'] ?? data_get($offer, 'inventoryLevel.value');
    }

    private function applyBreadcrumbJsonLd(array $item, array &$result): void
    {
        $elements = $item['itemListElement'] ?? [];

        if (! is_array($elements)) {
            return;
        }

        foreach ($elements as $element) {
            if (! is_array($element)) {
                continue;
            }

            $name = $this->sanitizeString(
                data_get($element, 'item.name')
                ?? data_get($element, 'name')
            );

            if ($name === null) {
                continue;
            }

            $result['breadcrumbs'][] = $name;
        }
    }

    private function flattenJsonLdItems(array $payload): array
    {
        if (array_is_list($payload)) {
            $items = [];

            foreach ($payload as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $items = array_merge($items, $this->flattenJsonLdItems($item));
            }

            return $items;
        }

        $items = [$payload];
        $graph = $payload['@graph'] ?? null;

        if (! is_array($graph)) {
            return $items;
        }

        foreach ($graph as $graphItem) {
            if (! is_array($graphItem)) {
                continue;
            }

            $items = array_merge($items, $this->flattenJsonLdItems($graphItem));
        }

        return $items;
    }

    private function hasType(array $item, string $type): bool
    {
        $rawType = $item['@type'] ?? null;

        if (is_string($rawType)) {
            return mb_strtolower($rawType) === mb_strtolower($type);
        }

        if (! is_array($rawType)) {
            return false;
        }

        foreach ($rawType as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            if (mb_strtolower($candidate) === mb_strtolower($type)) {
                return true;
            }
        }

        return false;
    }

    private function extractCategoryName(mixed $category): ?string
    {
        if (is_string($category)) {
            return $this->sanitizeString($category);
        }

        if (! is_array($category)) {
            return null;
        }

        return $this->sanitizeString($category['name'] ?? $category['title'] ?? null);
    }

    private function extractBrand(mixed $brand): ?string
    {
        if (is_string($brand)) {
            return $this->sanitizeString($brand);
        }

        if (! is_array($brand)) {
            return null;
        }

        return $this->sanitizeString($brand['name'] ?? $brand['title'] ?? null);
    }

    private function extractImageCandidates(mixed $payload): array
    {
        if (is_string($payload)) {
            return [$payload];
        }

        if (! is_array($payload)) {
            return [];
        }

        $images = [];

        foreach ($payload as $item) {
            if (is_string($item)) {
                $images[] = $item;

                continue;
            }

            if (! is_array($item)) {
                continue;
            }

            $candidate = $item['url'] ?? $item['src'] ?? $item['image'] ?? null;

            if (is_string($candidate)) {
                $images[] = $candidate;

                continue;
            }

            $images = array_merge($images, $this->extractImageCandidates($item));
        }

        return $images;
    }

    private function extractSpecsFromHtml(string $html): array
    {
        libxml_use_internal_errors(true);

        $dom = new DOMDocument;
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);

        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query("//dt[contains(concat(' ', normalize-space(@class), ' '), ' list-props__title ')]");

        if ($nodes === false) {
            return [];
        }

        $specs = [];

        foreach ($nodes as $titleNode) {
            $name = trim((string) $xpath->evaluate('string(.//span[1])', $titleNode));

            if ($name === '') {
                continue;
            }

            $value = trim((string) $xpath->evaluate(
                "string(following-sibling::dd[contains(concat(' ', normalize-space(@class), ' '), ' list-props__value ')][1])",
                $titleNode
            ));

            if ($value === '') {
                continue;
            }

            $specs[] = [
                'name' => $name,
                'value' => $value,
                'source' => 'dom',
            ];
        }

        return $specs;
    }

    private function extractSpecsFromPayload(mixed $payload, string $source): array
    {
        if (! is_array($payload)) {
            return [];
        }

        $specs = [];
        $ignoredMapKeys = [
            'id',
            'slug',
            'name',
            'title',
            'label',
            'key',
            'value',
            'text',
            'val',
            'unit',
            'url',
            'href',
            'image',
            'icon',
            'code',
            'type',
        ];

        if (! array_is_list($payload)) {
            $name = $this->sanitizeString($payload['name'] ?? $payload['title'] ?? $payload['label'] ?? $payload['key'] ?? null);
            $value = $this->normalizeSpecValue(
                $payload['value'] ?? $payload['text'] ?? $payload['val'] ?? null,
                $payload['unit'] ?? null
            );

            if ($name !== null && $value !== null) {
                $specs[] = [
                    'name' => $name,
                    'value' => $value,
                    'source' => $source,
                ];

                return $specs;
            }

            foreach ($payload as $key => $valuePayload) {
                if (is_array($valuePayload)) {
                    $specs = array_merge($specs, $this->extractSpecsFromPayload($valuePayload, $source));

                    continue;
                }

                if (! is_string($key) || in_array(mb_strtolower($key), $ignoredMapKeys, true)) {
                    continue;
                }

                $nameFromMap = $this->sanitizeString($key);
                $valueFromMap = $this->normalizeSpecValue($valuePayload);

                if ($nameFromMap === null || $valueFromMap === null) {
                    continue;
                }

                $specs[] = [
                    'name' => $nameFromMap,
                    'value' => $valueFromMap,
                    'source' => $source,
                ];
            }

            return $specs;
        }

        foreach ($payload as $item) {
            if (is_array($item)) {
                $specs = array_merge($specs, $this->extractSpecsFromPayload($item, $source));
            }
        }

        return $specs;
    }

    private function normalizeSpecValue(mixed $value, mixed $unit = null): ?string
    {
        if (is_bool($value)) {
            $normalized = $value ? '1' : '0';
        } elseif (is_int($value) || is_float($value)) {
            $normalized = (string) $value;
        } elseif (is_string($value)) {
            $normalized = $this->sanitizeString($value);
        } elseif (is_array($value)) {
            $nested = $this->sanitizeString($value['value'] ?? $value['text'] ?? $value['name'] ?? null);

            if ($nested !== null) {
                $normalized = $nested;
            } elseif (array_is_list($value)) {
                $parts = [];

                foreach ($value as $listItem) {
                    $itemValue = $this->normalizeSpecValue($listItem);

                    if ($itemValue !== null) {
                        $parts[] = $itemValue;
                    }
                }

                $normalized = $parts === [] ? null : implode(', ', array_unique($parts));
            } else {
                $normalized = null;
            }
        } else {
            $normalized = null;
        }

        if ($normalized === null) {
            return null;
        }

        $normalizedUnit = $this->sanitizeString($unit);

        if ($normalizedUnit === null) {
            return $normalized;
        }

        $normalizedLower = mb_strtolower($normalized);
        $unitLower = mb_strtolower($normalizedUnit);

        if (str_ends_with($normalizedLower, $unitLower)) {
            return $normalized;
        }

        return $normalized.' '.$normalizedUnit;
    }

    private function sanitizeString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function uniqueStrings(array $values): array
    {
        $unique = [];

        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }

            $value = trim($value);

            if ($value === '') {
                continue;
            }

            $key = mb_strtolower($value);
            $unique[$key] = $value;
        }

        return array_values($unique);
    }

    private function uniqueSpecs(array $specs): array
    {
        $unique = [];

        foreach ($specs as $spec) {
            if (! is_array($spec)) {
                continue;
            }

            $name = $this->sanitizeString($spec['name'] ?? null);
            $value = $this->sanitizeString($spec['value'] ?? null);
            $source = $this->sanitizeString($spec['source'] ?? null) ?? 'unknown';

            if ($name === null || $value === null) {
                continue;
            }

            $key = mb_strtolower($name.'::'.$value);

            if (! isset($unique[$key])) {
                $unique[$key] = [
                    'name' => $name,
                    'value' => $value,
                    'source' => $source,
                ];
            }
        }

        return array_values($unique);
    }
}
