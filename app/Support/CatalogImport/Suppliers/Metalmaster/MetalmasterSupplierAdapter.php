<?php

namespace App\Support\CatalogImport\Suppliers\Metalmaster;

use App\Support\CatalogImport\Contracts\SupplierAdapterInterface;
use App\Support\CatalogImport\DTO\ImportError;
use App\Support\CatalogImport\DTO\ProductPayload;
use App\Support\CatalogImport\DTO\RecordMappingResult;
use App\Support\CatalogImport\Enums\ImportErrorLevel;
use App\Support\CatalogImport\Html\HtmlDocumentRecord;
use App\Support\Metalmaster\MetalmasterProductParser;
use Throwable;

final class MetalmasterSupplierAdapter implements SupplierAdapterInterface
{
    public function __construct(
        private readonly MetalmasterSupplierProfile $profile = new MetalmasterSupplierProfile,
        private readonly MetalmasterProductParser $parser = new MetalmasterProductParser,
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

        $bucket = $this->resolveBucket($record);

        try {
            $parsed = $this->parser->parse($record->document->html, $record->url, $bucket);
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

        $externalId = $this->profile->resolveExternalId($record->url, $this->text($parsed['slug'] ?? null));
        $name = $this->text($parsed['name'] ?? null) ?? $this->profile->fallbackName($externalId);

        if ($name === null) {
            return new RecordMappingResult(
                payload: null,
                errors: [
                    new ImportError(
                        code: 'missing_name',
                        message: 'Metalmaster record does not contain a product name.',
                    ),
                ],
            );
        }

        $images = $this->normalizeImages([
            ...$this->normalizeImages($parsed['gallery'] ?? []),
            $this->text($parsed['image'] ?? null),
            $this->text($parsed['thumb'] ?? null),
        ]);

        return new RecordMappingResult(
            payload: new ProductPayload(
                externalId: $externalId,
                name: $name,
                description: $this->text($parsed['description'] ?? null),
                brand: $this->text($parsed['brand'] ?? null),
                priceAmount: $this->normalizeNonNegativeInteger($parsed['price_amount'] ?? null),
                currency: $this->text($parsed['currency'] ?? null),
                inStock: $this->normalizeBool($parsed['in_stock'] ?? null),
                qty: $this->normalizeNonNegativeInteger($parsed['qty'] ?? null),
                images: $images,
                attributes: $this->normalizeSpecs($parsed['specs'] ?? []),
                source: [
                    'supplier' => $this->profile->supplierKey(),
                    'profile' => $this->profile->profileKey(),
                    'bucket' => $bucket,
                    'url' => $record->url,
                    'slug' => $this->text($parsed['slug'] ?? null),
                    'external_id' => $externalId,
                    'legacy_match' => $this->profile->defaults()['legacy_match'] ?? null,
                ],
                title: $this->text($parsed['title'] ?? null),
                sku: $this->text($parsed['sku'] ?? null),
                country: $this->text($parsed['country'] ?? null),
                discountPrice: $this->normalizeNonNegativeInteger($parsed['discount_price'] ?? null),
                short: $this->text($parsed['short'] ?? null),
                extraDescription: $this->text($parsed['extra_description'] ?? null),
                promoInfo: $this->text($parsed['promo_info'] ?? null),
                metaTitle: $this->text($parsed['meta_title'] ?? null),
                metaDescription: $this->text($parsed['meta_description'] ?? null),
            ),
        );
    }

    private function resolveBucket(HtmlDocumentRecord $record): string
    {
        $bucket = $record->meta['bucket'] ?? '';

        if (! is_string($bucket)) {
            return '';
        }

        return trim($bucket);
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function normalizeNonNegativeInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return max(0, (int) round($value));
        }

        if (! is_string($value)) {
            return null;
        }

        if (! preg_match('/-?[0-9]+(?:[.,][0-9]+)?/', $value, $matches)) {
            return null;
        }

        $normalized = str_replace(',', '.', $matches[0]);

        return max(0, (int) round((float) $normalized));
    }

    private function normalizeBool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value > 0;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = mb_strtolower(trim($value));

        if (in_array($value, ['1', 'true', 'yes', 'y', 'instock', 'в наличии'], true)) {
            return true;
        }

        if (in_array($value, ['0', 'false', 'no', 'n', 'outofstock', 'нет в наличии'], true)) {
            return false;
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

            $images[mb_strtolower($image)] = $image;
        }

        return array_values($images);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeSpecs(mixed $rawSpecs): array
    {
        if (is_string($rawSpecs)) {
            $decoded = json_decode($rawSpecs, true);

            if (is_array($decoded)) {
                $rawSpecs = $decoded;
            }
        }

        if (! is_array($rawSpecs)) {
            return [];
        }

        return $rawSpecs;
    }
}
