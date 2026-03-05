<?php

namespace App\Support\CatalogImport\Yml;

use App\Support\CatalogImport\DTO\AdapterIssue;
use App\Support\CatalogImport\DTO\AdapterResult;
use App\Support\CatalogImport\DTO\ProductPayload;
use SimpleXMLElement;

final class YandexMarketFeedAdapter
{
    public function mapOffer(YmlOfferRecord $offer): AdapterResult
    {
        $issues = [];

        $externalId = trim($offer->id);

        if ($externalId === '') {
            $issues[] = new AdapterIssue(
                code: 'missing_offer_id',
                message: 'Offer attribute "id" is required.',
            );

            return new AdapterResult(payload: null, issues: $issues);
        }

        $xml = $this->loadOfferXml($offer->xml, $issues);

        if ($xml === null) {
            return new AdapterResult(payload: null, issues: $issues);
        }

        $offerType = $offer->type;

        if ($offerType === 'vendor.model') {
            return $this->mapVendorModelOffer($externalId, $offer, $xml, $issues);
        }

        return $this->mapSimplifiedOffer($externalId, $offer, $xml, $issues);
    }

    /**
     * @param  array<int, AdapterIssue>  $issues
     */
    private function mapSimplifiedOffer(string $externalId, YmlOfferRecord $offer, SimpleXMLElement $xml, array $issues): AdapterResult
    {
        $name = $this->textOrNull($xml->name ?? null);

        if ($name === null) {
            $issues[] = new AdapterIssue(
                code: 'missing_name',
                message: 'Simplified offers require <name>.',
            );

            return new AdapterResult(payload: null, issues: $issues);
        }

        $priceAmount = $this->parsePriceAmount($this->textOrNull($xml->price ?? null));
        $currency = $this->textOrNull($xml->currencyId ?? null);
        $vendor = $this->textOrNull($xml->vendor ?? null);
        $description = $this->textOrNull($xml->description ?? null);

        return new AdapterResult(
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
            issues: $issues,
        );
    }

    /**
     * @param  array<int, AdapterIssue>  $issues
     */
    private function mapVendorModelOffer(string $externalId, YmlOfferRecord $offer, SimpleXMLElement $xml, array $issues): AdapterResult
    {
        $typePrefix = $this->textOrNull($xml->typePrefix ?? null);
        $vendor = $this->textOrNull($xml->vendor ?? null);
        $model = $this->textOrNull($xml->model ?? null);

        if ($vendor === null) {
            $issues[] = new AdapterIssue(
                code: 'missing_vendor',
                message: 'vendor.model offers require <vendor>.',
            );
        }

        if ($model === null) {
            $issues[] = new AdapterIssue(
                code: 'missing_model',
                message: 'vendor.model offers require <model>.',
            );
        }

        if ($issues !== []) {
            return new AdapterResult(payload: null, issues: $issues);
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

        return new AdapterResult(
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
            issues: $issues,
        );
    }

    /**
     * @param  array<int, AdapterIssue>  $issues
     */
    private function loadOfferXml(string $xml, array &$issues): ?SimpleXMLElement
    {
        $xml = trim($xml);

        if ($xml === '') {
            $issues[] = new AdapterIssue(
                code: 'empty_offer_xml',
                message: 'Offer XML is empty.',
            );

            return null;
        }

        $prev = libxml_use_internal_errors(true);

        try {
            $node = simplexml_load_string($xml);

            if (! $node) {
                $issues[] = new AdapterIssue(
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

        $normalized = str_replace(["\xc2\xa0", ' '], '', $raw); // nbsp + spaces
        $normalized = str_replace(',', '.', $normalized);

        if ($normalized === '' || ! is_numeric($normalized)) {
            return null;
        }

        return (int) round((float) $normalized);
    }
}
