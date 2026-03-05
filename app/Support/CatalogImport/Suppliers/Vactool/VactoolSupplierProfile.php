<?php

namespace App\Support\CatalogImport\Suppliers\Vactool;

use Illuminate\Support\Str;

final class VactoolSupplierProfile
{
    public function supplierKey(): string
    {
        return 'vactool';
    }

    public function profileKey(): string
    {
        return 'vactool_html';
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
            'external_id' => 'url slug',
            'name' => 'title',
            'description' => 'description',
            'brand' => 'brand',
            'price' => 'price',
            'currency' => 'currency',
            'qty' => 'stock_qty',
            'in_stock' => 'availability/stock_qty',
            'images' => 'images',
            'attributes' => 'specs',
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
            'legacy_match' => 'name_brand',
        ];
    }

    public function resolveExternalId(string $url): string
    {
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
