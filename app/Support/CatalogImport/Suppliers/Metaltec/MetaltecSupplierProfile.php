<?php

namespace App\Support\CatalogImport\Suppliers\Metaltec;

use Illuminate\Support\Str;

class MetaltecSupplierProfile
{
    public function supplierKey(): string
    {
        return 'metaltec';
    }

    public function profileKey(): string
    {
        return 'metaltec_xml';
    }

    public function defaultSourceUrl(): string
    {
        return 'https://metaltec.com.ru/upload/catalog-metaltec-char.xml';
    }

    public function sourceBaseUrl(): string
    {
        return 'https://metaltec.com.ru';
    }

    public function normalizeSection(?string $section): ?string
    {
        if (! is_string($section)) {
            return null;
        }

        $section = trim($section);
        $section = preg_replace('/\s+/u', ' ', $section) ?? $section;

        return $section !== '' ? $section : null;
    }

    public function categoryIdForSection(?string $section): ?int
    {
        $normalizedSection = $this->normalizeSection($section);

        if ($normalizedSection === null) {
            return null;
        }

        return max(1, (int) sprintf('%u', crc32(mb_strtolower($normalizedSection))));
    }

    /**
     * @return array<string, string>
     */
    public function fieldMapping(): array
    {
        return [
            'external_id' => 'ID',
            'name' => 'Наименование',
            'short' => 'Анонс',
            'description' => 'Описание + КонструктивныеОсобенности',
            'images' => 'Фото/ФотоДоп',
            'attributes' => 'Характеристики',
            'price' => 'ЦенаРуб',
            'discount_price' => 'СтараяЦенаРуб/ЦенаРуб',
            'currency' => 'RUB',
            'status' => 'Статус',
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
            'brand' => 'Metaltec',
            'legacy_match' => 'id',
        ];
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
