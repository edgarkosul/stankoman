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
    public function __construct(
        private readonly YandexMarketFeedProfile $profile = new YandexMarketFeedProfile,
    ) {}

    public function mapRecord(mixed $record): RecordMappingResult
    {
        if (! $record instanceof YmlOfferRecord) {
            return new RecordMappingResult(
                payload: null,
                errors: [
                    new ImportError(
                        code: 'invalid_record_type',
                        message: 'Ожидался экземпляр YmlOfferRecord.',
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
                message: 'Атрибут offer "id" обязателен.',
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
        $categoryId = $this->textOrNull($xml->categoryId ?? null);
        $priceRaw = $this->textOrNull($xml->price ?? null);
        $currency = $this->textOrNull($xml->currencyId ?? null);

        $this->appendRequiredFieldErrors(
            errors: $errors,
            offerType: null,
            values: [
                'name' => $name,
                'price' => $priceRaw,
                'currencyId' => $currency,
                'categoryId' => $categoryId,
            ],
        );

        if ($errors !== []) {
            return new RecordMappingResult(payload: null, errors: $errors);
        }

        $priceAmount = $this->parsePriceAmount($priceRaw);
        $vendor = $this->textOrNull($xml->vendor ?? null);
        $description = $this->textOrNull($xml->description ?? null);
        $pictures = $this->extractPictures($xml);
        $params = $this->extractParams($xml);
        $resolvedSku = $this->resolveSku($externalId, $name, $xml);

        return new RecordMappingResult(
            payload: new ProductPayload(
                externalId: $externalId,
                name: $name,
                description: $description,
                brand: $vendor,
                priceAmount: $priceAmount,
                currency: $currency,
                inStock: $offer->available,
                images: $pictures,
                attributes: $params,
                sku: $resolvedSku['sku'],
                source: [
                    'supplier' => $this->profile->supplierKey(),
                    'profile' => $this->profile->profileName(),
                    'format' => 'yml',
                    'offer_type' => $offer->type,
                    'category_id' => $categoryId,
                    'sku_source' => $resolvedSku['source'],
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
        $categoryId = $this->textOrNull($xml->categoryId ?? null);
        $priceRaw = $this->textOrNull($xml->price ?? null);
        $currency = $this->textOrNull($xml->currencyId ?? null);

        $this->appendRequiredFieldErrors(
            errors: $errors,
            offerType: 'vendor.model',
            values: [
                'vendor' => $vendor,
                'model' => $model,
                'price' => $priceRaw,
                'currencyId' => $currency,
                'categoryId' => $categoryId,
            ],
        );

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

        $priceAmount = $this->parsePriceAmount($priceRaw);
        $description = $this->textOrNull($xml->description ?? null);
        $pictures = $this->extractPictures($xml);
        $params = $this->extractParams($xml);
        $resolvedSku = $this->resolveSku($externalId, $name, $xml);

        return new RecordMappingResult(
            payload: new ProductPayload(
                externalId: $externalId,
                name: $name,
                description: $description,
                brand: $vendor,
                priceAmount: $priceAmount,
                currency: $currency,
                inStock: $offer->available,
                images: $pictures,
                attributes: $params,
                sku: $resolvedSku['sku'],
                source: [
                    'supplier' => $this->profile->supplierKey(),
                    'profile' => $this->profile->profileName(),
                    'format' => 'yml',
                    'offer_type' => $offer->type,
                    'category_id' => $categoryId,
                    'sku_source' => $resolvedSku['source'],
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
                message: 'XML offer-записи пуст.',
            );

            return null;
        }

        $prev = libxml_use_internal_errors(true);

        try {
            $node = simplexml_load_string($xml);

            if (! $node) {
                $errors[] = new ImportError(
                    code: 'invalid_offer_xml',
                    message: 'XML offer-записи не является корректным XML-документом.',
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

    /**
     * @return array{sku: string, source: string}
     */
    private function resolveSku(string $externalId, string $name, SimpleXMLElement $xml): array
    {
        $paramSku = $this->findSkuInParams($xml);
        $shopSku = $this->textOrNull($xml->{'shop-sku'} ?? null);
        $vendorCode = $this->textOrNull($xml->vendorCode ?? null);

        $candidates = [
            ['source' => 'shop-sku', 'value' => $shopSku],
            ['source' => 'vendorCode', 'value' => $vendorCode],
            ['source' => 'offer-id', 'value' => $externalId],
            ['source' => 'param', 'value' => $paramSku],
        ];

        foreach ($candidates as $candidate) {
            $normalized = $this->normalizeSku($candidate['value']);

            if ($normalized === null) {
                continue;
            }

            return [
                'sku' => $normalized,
                'source' => $candidate['source'],
            ];
        }

        return [
            'sku' => $this->generateFallbackSku($externalId, $name),
            'source' => 'generated',
        ];
    }

    private function normalizeSku(?string $value): ?string
    {
        $value = $this->textOrNull($value);

        if ($value === null) {
            return null;
        }

        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
        $value = preg_replace('/\s+/u', '-', $value) ?? $value;
        $value = preg_replace('/[^\p{L}\p{N}\-._\/]+/u', '', $value) ?? $value;
        $value = preg_replace('/-{2,}/', '-', $value) ?? $value;
        $value = trim($value, "-._/\t\n\r\0\x0B ");

        if ($value === '') {
            return null;
        }

        return mb_strtoupper($value);
    }

    private function generateFallbackSku(string $externalId, string $name): string
    {
        $hash = strtoupper(substr(hash('sha256', $externalId.'|'.$name), 0, 12));

        return 'YML-'.$hash;
    }

    private function findSkuInParams(SimpleXMLElement $xml): ?string
    {
        $supportedNames = [
            'артикул',
            'sku',
            'код товара',
            'vendor code',
            'vendorcode',
            'part number',
            'partnumber',
        ];
        $lookup = array_fill_keys($supportedNames, true);

        foreach ($xml->param as $paramNode) {
            $name = $this->textOrNull((string) ($paramNode['name'] ?? ''));

            if ($name === null) {
                continue;
            }

            $normalizedName = $this->normalizeParamName($name);

            if (! isset($lookup[$normalizedName])) {
                continue;
            }

            $value = $this->textOrNull($paramNode);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function normalizeParamName(string $name): string
    {
        $name = mb_strtolower($name);
        $name = preg_replace('/[_\-]+/u', ' ', $name) ?? $name;
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;

        return trim($name);
    }

    /**
     * @param  array<int, ImportError>  $errors
     * @param  array<string, string|null>  $values
     */
    private function appendRequiredFieldErrors(array &$errors, ?string $offerType, array $values): void
    {
        if (($this->profile->defaults()['strict_required_fields'] ?? false) !== true) {
            return;
        }

        foreach ($this->profile->requiredFields($offerType) as $field) {
            $value = $values[$field] ?? null;

            if (is_string($value) && trim($value) !== '') {
                continue;
            }

            $errors[] = new ImportError(
                code: 'missing_required_'.$field,
                message: sprintf('В offer-записи отсутствует обязательное поле <%s>.', $field),
            );
        }
    }

    /**
     * @return array<int, string>
     */
    private function extractPictures(SimpleXMLElement $xml): array
    {
        $pictures = [];
        $seen = [];

        foreach ($xml->picture as $pictureNode) {
            $picture = $this->textOrNull($pictureNode);

            if ($picture === null) {
                continue;
            }

            $key = mb_strtolower($picture);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $pictures[] = $picture;
        }

        return $pictures;
    }

    /**
     * @return array<int, array{name:string,value:string,source:string}>
     */
    private function extractParams(SimpleXMLElement $xml): array
    {
        $params = [];
        $seen = [];

        foreach ($xml->param as $paramNode) {
            $name = $this->textOrNull((string) ($paramNode['name'] ?? ''));
            $value = $this->textOrNull($paramNode);

            if ($name === null || $value === null) {
                continue;
            }

            $unit = $this->textOrNull((string) ($paramNode['unit'] ?? ''));

            if ($unit !== null) {
                $value .= ' '.$unit;
            }

            $key = mb_strtolower($name.'::'.$value);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $params[] = [
                'name' => $name,
                'value' => $value,
                'source' => 'yml',
            ];
        }

        return $params;
    }
}
