<?php

namespace App\Support\CatalogImport\Processing;

use App\Support\CatalogImport\DTO\ProductPayload;

class ProductPayloadNormalizer
{
    public function normalize(ProductPayload $payload): ProductPayload
    {
        return new ProductPayload(
            externalId: $this->normalizeString($payload->externalId, collapseWhitespace: true) ?? '',
            name: $this->normalizeString($payload->name, collapseWhitespace: true) ?? '',
            description: $this->normalizeDescription($payload->description),
            brand: $this->normalizeString($payload->brand, collapseWhitespace: true),
            priceAmount: $this->normalizeNonNegativeInteger($payload->priceAmount),
            currency: $this->normalizeCurrency($payload->currency),
            inStock: $payload->inStock,
            qty: $this->normalizeNonNegativeInteger($payload->qty),
            images: $this->normalizeImages($payload->images),
            attributes: $payload->attributes,
            source: $payload->source,
            title: $this->normalizeString($payload->title, collapseWhitespace: true),
            sku: $this->normalizeString($payload->sku, collapseWhitespace: true),
            country: $this->normalizeString($payload->country, collapseWhitespace: true),
            discountPrice: $this->normalizeNonNegativeInteger($payload->discountPrice),
            short: $this->normalizeString($payload->short, collapseWhitespace: true),
            extraDescription: $this->normalizeDescription($payload->extraDescription),
            promoInfo: $this->normalizeString($payload->promoInfo, collapseWhitespace: true),
            metaTitle: $this->normalizeString($payload->metaTitle, collapseWhitespace: true),
            metaDescription: $this->normalizeString($payload->metaDescription, collapseWhitespace: true),
        );
    }

    private function normalizeCurrency(?string $currency): ?string
    {
        $currency = $this->normalizeString($currency, collapseWhitespace: false);

        if ($currency === null) {
            return null;
        }

        $currency = strtoupper($currency);
        $currency = preg_replace('/[^A-Z]/', '', $currency) ?? '';

        if ($currency === '' || strlen($currency) < 3) {
            return null;
        }

        if ($currency === 'RUR') {
            return 'RUB';
        }

        return substr($currency, 0, 3);
    }

    private function normalizeDescription(?string $description): ?string
    {
        if ($description === null) {
            return null;
        }

        $hasHtml = str_contains($description, '<') && str_contains($description, '>');

        return $this->normalizeString($description, collapseWhitespace: ! $hasHtml);
    }

    private function normalizeNonNegativeInteger(?int $value): ?int
    {
        if ($value === null) {
            return null;
        }

        return max(0, $value);
    }

    /**
     * @param  array<int, string>  $images
     * @return array<int, string>
     */
    private function normalizeImages(array $images): array
    {
        $normalized = [];
        $seen = [];

        foreach ($images as $image) {
            $cleaned = $this->normalizeString($image, collapseWhitespace: false);

            if ($cleaned === null) {
                continue;
            }

            $key = mb_strtolower($cleaned);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $normalized[] = $cleaned;
        }

        return $normalized;
    }

    private function normalizeString(?string $value, bool $collapseWhitespace): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
        $value = trim($value);

        if ($collapseWhitespace) {
            $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
            $value = trim($value);
        }

        return $value !== '' ? $value : null;
    }
}
