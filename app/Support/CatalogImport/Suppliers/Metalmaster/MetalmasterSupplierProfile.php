<?php

namespace App\Support\CatalogImport\Suppliers\Metalmaster;

use Illuminate\Support\Str;

final class MetalmasterSupplierProfile
{
    public function supplierKey(): string
    {
        return 'metalmaster';
    }

    public function profileKey(): string
    {
        return 'metalmaster_html';
    }

    public function defaultBucketsFile(): string
    {
        return storage_path('app/parser/metalmaster-buckets.json');
    }

    /**
     * @return array<string, mixed>
     */
    public function parserOptions(): array
    {
        return [
            'card_xpath' => '//html',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function fieldMapping(): array
    {
        return [
            'external_id' => 'slug',
            'name' => 'name',
            'title' => 'title',
            'sku' => 'sku',
            'brand' => 'brand',
            'country' => 'country',
            'price' => 'price_amount',
            'discount_price' => 'discount_price',
            'currency' => 'currency',
            'in_stock' => 'in_stock',
            'qty' => 'qty',
            'description' => 'description',
            'short' => 'short',
            'extra_description' => 'extra_description',
            'images' => 'gallery/image/thumb',
            'attributes' => 'specs',
            'meta_title' => 'meta_title',
            'meta_description' => 'meta_description',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function categoryRules(): array
    {
        return [
            'new_products' => 'attach_staged',
            'existing_products' => 'preserve_categories',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return [
            'currency' => 'RUB',
            'legacy_match' => 'slug',
        ];
    }

    public function resolveExternalId(string $url, ?string $parsedSlug = null): string
    {
        $candidate = is_string($parsedSlug) ? trim($parsedSlug) : '';

        if ($candidate !== '') {
            return $candidate;
        }

        $path = parse_url($url, PHP_URL_PATH);
        $slug = is_string($path) ? basename(trim($path, '/')) : '';

        if (is_string($slug) && trim($slug) !== '') {
            return trim($slug);
        }

        return sha1($url);
    }

    public function fallbackName(string $externalId): ?string
    {
        $name = trim(str_replace(['-', '_'], ' ', $externalId));

        if ($name === '') {
            return null;
        }

        return Str::headline($name);
    }
}
