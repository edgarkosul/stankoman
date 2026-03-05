<?php

namespace App\Support\CatalogImport\Yml;

use App\Support\CatalogImport\Contracts\SupplierAdapterInterface;
use App\Support\CatalogImport\DTO\ImportError;
use App\Support\CatalogImport\DTO\ProductPayload;
use App\Support\CatalogImport\DTO\RecordMappingResult;
use App\Support\CatalogImport\Enums\ImportErrorLevel;
use SimpleXMLElement;

final class YandexMarketFeedAdapter implements SupplierAdapterInterface
{
    public function mapRecord(mixed $record): RecordMappingResult
    {
        if (! $record instanceof YmlOfferRecord) {
            return new RecordMappingResult(
                payload: null,
                errors: [
                    new ImportError(
                        code: 'invalid_record_type',
                        message: 'Expected YmlOfferRecord instance.',
                        level: ImportErrorLevel::Fatal,
                    ),
                ],
            );
        }

        return $this->mapOffer($record);
    }

    public function mapOffer(YmlOfferRecord $offer): RecordMappingResult
    {
        $errors = [];

        $externalId = trim($offer->id);

        if ($externalId === '') {
            $errors[] = new ImportError(
                code: 'missing_offer_id',
                message: 'Offer attribute "id" is required.',
            );

            return new RecordMappingResult(payload: null, errors: $errors);
        }

        $xml = $this->loadOfferXml($offer->xml, $errors);

        if ($xml === null) {
            return new RecordMappingResult(payload: null, errors: $errors);
        }

        $offerType = $offer->type;

        if ($offerType === 'vendor.model') {
            return $this->mapVendorModelOffer($externalId, $offer, $xml, $errors);
        }

        return $this->mapSimplifiedOffer($externalId, $offer, $xml, $errors);
    }

    /**
     * @param  array<int, ImportError>  $errors
     */
    private function mapSimplifiedOffer(string $externalId, YmlOfferRecord $offer, SimpleXMLElement $xml, array $errors): RecordMappingResult
    {
        $name = $this->textOrNull($xml->name ?? null);

        if ($name === null) {
            $errors[] = new ImportError(
                code: 'missing_name',
                message: 'Simplified offers require <name>.',
            );

            return new RecordMappingResult(payload: null, errors: $errors);
        }

        $priceAmount = $this->parsePriceAmount($this->textOrNull($xml->price ?? null));
        $currency = $this->textOrNull($xml->currencyId ?? null);
        $vendor = $this->textOrNull($xml->vendor ?? null);
        $description = $this->textOrNull($xml->description ?? null);

        return new RecordMappingResult(
            payload: new ProductPayload(
                externalId: $externalId,
                name: $name,
                description: $description,
                brand: $vendor,
                priceAmount: $priceAmount,
                currency: $currency,
                inStock: $offer->available,
                source: [
                    'format' => 'yml',
                    'offer_type' => $offer->type,
                ],
            ),
            errors: $errors,
        );
    }

    /**
     * @param  array<int, ImportError>  $errors
     */
    private function mapVendorModelOffer(string $externalId, YmlOfferRecord $offer, SimpleXMLElement $xml, array $errors): RecordMappingResult
    {
        $typePrefix = $this->textOrNull($xml->typePrefix ?? null);
        $vendor = $this->textOrNull($xml->vendor ?? null);
        $model = $this->textOrNull($xml->model ?? null);

        if ($vendor === null) {
            $errors[] = new ImportError(
                code: 'missing_vendor',
                message: 'vendor.model offers require <vendor>.',
            );
        }

        if ($model === null) {
            $errors[] = new ImportError(
                code: 'missing_model',
                message: 'vendor.model offers require <model>.',
            );
        }

        if ($errors !== []) {
            return new RecordMappingResult(payload: null, errors: $errors);
        }

        $nameParts = [];

        if ($typePrefix !== null) {
            $nameParts[] = $typePrefix;
        }

        $nameParts[] = $vendor;
        $nameParts[] = $model;

        $name = implode(' ', $nameParts);

        $priceAmount = $this->parsePriceAmount($this->textOrNull($xml->price ?? null));
        $currency = $this->textOrNull($xml->currencyId ?? null);
        $description = $this->textOrNull($xml->description ?? null);

        return new RecordMappingResult(
            payload: new ProductPayload(
                externalId: $externalId,
                name: $name,
                description: $description,
                brand: $vendor,
                priceAmount: $priceAmount,
                currency: $currency,
                inStock: $offer->available,
                source: [
                    'format' => 'yml',
                    'offer_type' => $offer->type,
                ],
            ),
            errors: $errors,
        );
    }

    /**
     * @param  array<int, ImportError>  $errors
     */
    private function loadOfferXml(string $xml, array &$errors): ?SimpleXMLElement
    {
        $xml = trim($xml);

        if ($xml === '') {
            $errors[] = new ImportError(
                code: 'empty_offer_xml',
                message: 'Offer XML is empty.',
            );

            return null;
        }

        $prev = libxml_use_internal_errors(true);

        try {
            $node = simplexml_load_string($xml);

            if (! $node) {
                $errors[] = new ImportError(
                    code: 'invalid_offer_xml',
                    message: 'Offer XML is not a valid XML document.',
                );

                return null;
            }

            return $node;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
    }

    private function textOrNull(mixed $value): ?string
    {
        if ($value instanceof SimpleXMLElement) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = preg_replace('/\\s+/u', ' ', $value) ?? $value;
        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function parsePriceAmount(?string $raw): ?int
    {
        if ($raw === null) {
            return null;
        }

        $normalized = str_replace(["\xc2\xa0", ' '], '', $raw);
        $normalized = str_replace(',', '.', $normalized);

        if ($normalized === '' || ! is_numeric($normalized)) {
            return null;
        }

        return (int) round((float) $normalized);
    }
}
