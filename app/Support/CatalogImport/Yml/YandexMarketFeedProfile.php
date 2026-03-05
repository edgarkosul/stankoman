<?php

namespace App\Support\CatalogImport\Yml;

final class YandexMarketFeedProfile
{
    public function profileName(): string
    {
        return 'Yandex Market Feed';
    }

    public function supplierKey(): string
    {
        return 'yandex_market_feed';
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return [
            'currency' => 'RUB',
            'strict_required_fields' => true,
            'offer_types' => ['simple', 'vendor.model'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function fieldMapping(): array
    {
        return [
            'external_id' => '@id',
            'name(simple)' => 'name',
            'name(vendor.model)' => 'typePrefix? + vendor + model',
            'brand' => 'vendor',
            'description' => 'description',
            'price' => 'price',
            'currency' => 'currencyId',
            'category_id' => 'categoryId',
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
            'source_category' => 'categoryId is copied into payload.source',
        ];
    }

    /**
     * @return array<int, string>
     */
    public function requiredFields(?string $offerType): array
    {
        if ($offerType === 'vendor.model') {
            return ['vendor', 'model', 'price', 'currencyId', 'categoryId'];
        }

        return ['name', 'price', 'currencyId', 'categoryId'];
    }
}
