<?php

namespace App\Support\Legacy;

use App\Models\LegacyProduct;
use App\Models\Product;
use App\Support\NameNormalizer;
use Illuminate\Support\Collection;

final class LegacyProductMatcher
{
    public const SKU_EXACT = 'sku_exact';

    public const SKU_NORMALIZED = 'sku_normalized';

    public const NAME_NORMALIZED = 'name_normalized';

    /**
     * @return array{product: Product, strategy: string}|null
     */
    public function match(LegacyProduct $legacyProduct): ?array
    {
        return $this->matchByExactSku($legacyProduct)
            ?? $this->matchByNormalizedSku($legacyProduct)
            ?? $this->matchByNormalizedName($legacyProduct);
    }

    /**
     * @return array{product: Product, strategy: string}|null
     */
    private function matchByExactSku(LegacyProduct $legacyProduct): ?array
    {
        $sku = $this->filledValue($legacyProduct->sku);

        if ($sku === null) {
            return null;
        }

        return $this->uniqueMatch(
            Product::query()
                ->where('sku', $sku)
                ->orderBy('id')
                ->limit(2)
                ->get(),
            self::SKU_EXACT,
        );
    }

    /**
     * @return array{product: Product, strategy: string}|null
     */
    private function matchByNormalizedSku(LegacyProduct $legacyProduct): ?array
    {
        $normalizedSku = self::normalizeSku($legacyProduct->sku);

        if ($normalizedSku === null) {
            return null;
        }

        $matches = Product::query()
            ->whereNotNull('sku')
            ->get(['id', 'name', 'slug', 'sku'])
            ->filter(
                static fn (Product $product): bool => self::normalizeSku($product->sku) === $normalizedSku
            )
            ->take(2)
            ->values();

        return $this->uniqueMatch($matches, self::SKU_NORMALIZED);
    }

    /**
     * @return array{product: Product, strategy: string}|null
     */
    private function matchByNormalizedName(LegacyProduct $legacyProduct): ?array
    {
        $normalizedName = NameNormalizer::normalize($legacyProduct->name);

        if ($normalizedName === null) {
            return null;
        }

        return $this->uniqueMatch(
            Product::query()
                ->where('name_normalized', $normalizedName)
                ->orderBy('id')
                ->limit(2)
                ->get(),
            self::NAME_NORMALIZED,
        );
    }

    public static function normalizeSku(?string $value): ?string
    {
        $value = self::normalizeBasic($value);

        if ($value === null) {
            return null;
        }

        $value = preg_replace('/[\s\-_\.\/\\\\]+/u', '', $value) ?? $value;

        return $value === '' ? null : $value;
    }

    private static function normalizeBasic(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = str_replace(
            ["\xC2\xA0", "\xE2\x80\xAF", "\t", "\n", "\r"],
            ' ',
            $value
        );

        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
        $normalized = trim($normalized);

        if ($normalized === '') {
            return null;
        }

        return mb_strtolower($normalized, 'UTF-8');
    }

    private function filledValue(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return array{product: Product, strategy: string}|null
     */
    private function uniqueMatch(Collection $products, string $strategy): ?array
    {
        if ($products->count() !== 1) {
            return null;
        }

        return [
            'product' => $products->first(),
            'strategy' => $strategy,
        ];
    }
}
