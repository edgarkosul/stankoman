<?php

namespace App\Support\CatalogImport\Suppliers\Vactool;

use App\Support\CatalogImport\Contracts\SupplierAdapterInterface;
use App\Support\CatalogImport\DTO\ImportError;
use App\Support\CatalogImport\DTO\ProductPayload;
use App\Support\CatalogImport\DTO\RecordMappingResult;
use App\Support\CatalogImport\Enums\ImportErrorLevel;
use App\Support\CatalogImport\Html\HtmlDocumentRecord;
use App\Support\Vactool\VactoolProductParser;
use Throwable;

final class VactoolSupplierAdapter implements SupplierAdapterInterface
{
    public function __construct(
        private readonly VactoolSupplierProfile $profile = new VactoolSupplierProfile,
        private readonly VactoolProductParser $parser = new VactoolProductParser,
    ) {}

    public function mapRecord(mixed $record): RecordMappingResult
    {
        if (! $record instanceof HtmlDocumentRecord) {
            return new RecordMappingResult(
                payload: null,
                errors: [
                    new ImportError(
                        code: 'invalid_record_type',
                        message: 'Expected HtmlDocumentRecord instance.',
                        level: ImportErrorLevel::Fatal,
                    ),
                ],
            );
        }

        try {
            $parsed = $this->parser->parse($record->document->html, $record->url);
        } catch (Throwable $exception) {
            return new RecordMappingResult(
                payload: null,
                errors: [
                    new ImportError(
                        code: 'record_parse_failed',
                        message: $exception->getMessage(),
                    ),
                ],
            );
        }

        $externalId = $this->profile->resolveExternalId($record->url);
        $name = $this->text($parsed['title'] ?? null) ?? $this->profile->fallbackName($externalId);

        if ($name === null) {
            return new RecordMappingResult(
                payload: null,
                errors: [
                    new ImportError(
                        code: 'missing_name',
                        message: 'Vactool record does not contain a product title.',
                    ),
                ],
            );
        }

        $priceAmount = $this->normalizePriceAmount($parsed['price'] ?? null);
        $currency = $this->normalizeCurrency($parsed['currency'] ?? null);
        $qty = $this->normalizeQuantity($parsed['stock_qty'] ?? null);
        $inStock = $this->resolveInStock($parsed['availability'] ?? null, $qty);
        $images = $this->normalizeImages($parsed['images'] ?? []);

        return new RecordMappingResult(
            payload: new ProductPayload(
                externalId: $externalId,
                name: $name,
                description: $this->text($parsed['description'] ?? null),
                brand: $this->text($parsed['brand'] ?? null),
                priceAmount: $priceAmount,
                currency: $currency,
                inStock: $inStock,
                qty: $qty,
                images: $images,
                attributes: $this->normalizeSpecs($parsed['specs'] ?? []),
                source: [
                    'supplier' => $this->profile->supplierKey(),
                    'profile' => $this->profile->profileKey(),
                    'category' => $this->text($parsed['category'] ?? null),
                    'breadcrumbs' => is_array($parsed['breadcrumbs'] ?? null) ? $parsed['breadcrumbs'] : [],
                    'url' => $record->url,
                    'external_id' => $externalId,
                    'legacy_match' => $this->profile->defaults()['legacy_match'] ?? null,
                ],
                title: $this->text($parsed['title'] ?? null),
                metaTitle: $this->text($parsed['title'] ?? null),
            ),
        );
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function normalizePriceAmount(mixed $rawPrice): ?int
    {
        if (is_int($rawPrice) || is_float($rawPrice)) {
            return max(0, (int) round($rawPrice));
        }

        if (! is_string($rawPrice)) {
            return null;
        }

        $price = str_replace(["\xC2\xA0", ' '], '', trim($rawPrice));
        $price = preg_replace('/[^0-9,.-]/u', '', $price) ?? '';

        if ($price === '') {
            return null;
        }

        if (str_contains($price, ',') && str_contains($price, '.')) {
            $price = str_replace(',', '', $price);
        }

        $price = str_replace(',', '.', $price);

        if (! is_numeric($price)) {
            return null;
        }

        return max(0, (int) round((float) $price));
    }

    private function normalizeCurrency(mixed $rawCurrency): ?string
    {
        if (! is_string($rawCurrency)) {
            return null;
        }

        $currency = strtoupper($rawCurrency);
        $currency = preg_replace('/[^A-Z]/', '', $currency) ?? '';

        if ($currency === 'RUR') {
            return 'RUB';
        }

        if (strlen($currency) < 3) {
            return null;
        }

        return substr($currency, 0, 3);
    }

    private function normalizeQuantity(mixed $rawQuantity): ?int
    {
        if (is_bool($rawQuantity)) {
            return $rawQuantity ? 1 : 0;
        }

        if (is_int($rawQuantity) || is_float($rawQuantity)) {
            return max(0, (int) round($rawQuantity));
        }

        if (! is_string($rawQuantity)) {
            return null;
        }

        if (! preg_match('/[0-9]+(?:[.,][0-9]+)?/', $rawQuantity, $matches)) {
            return null;
        }

        $value = str_replace(',', '.', $matches[0]);

        return max(0, (int) round((float) $value));
    }

    private function resolveInStock(mixed $rawAvailability, ?int $quantity): ?bool
    {
        if ($quantity !== null) {
            return $quantity > 0;
        }

        if (! is_string($rawAvailability)) {
            return null;
        }

        $availability = mb_strtolower($rawAvailability);
        $availability = preg_replace('/\s+/u', '', $availability) ?? $availability;

        if (
            str_contains($availability, 'outofstock')
            || str_contains($availability, 'out_of_stock')
            || str_contains($availability, 'out-of-stock')
            || str_contains($availability, 'нетвналичии')
            || str_contains($availability, 'soldout')
        ) {
            return false;
        }

        if (
            str_contains($availability, 'instock')
            || str_contains($availability, 'in_stock')
            || str_contains($availability, 'in-stock')
            || str_contains($availability, 'available')
            || str_contains($availability, 'вналичии')
        ) {
            return true;
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function normalizeImages(mixed $rawImages): array
    {
        if (! is_array($rawImages)) {
            return [];
        }

        $images = [];

        foreach ($rawImages as $image) {
            if (! is_string($image)) {
                continue;
            }

            $image = trim($image);

            if ($image === '') {
                continue;
            }

            $key = mb_strtolower($image);
            $images[$key] = $image;
        }

        return array_values($images);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeSpecs(mixed $rawSpecs): array
    {
        if (! is_array($rawSpecs)) {
            return [];
        }

        return $rawSpecs;
    }
}
