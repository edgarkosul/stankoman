<?php

namespace App\Support\CatalogImport\Suppliers\Metaltec;

use App\Support\CatalogImport\Contracts\SupplierAdapterInterface;
use App\Support\CatalogImport\DTO\ImportError;
use App\Support\CatalogImport\DTO\ProductPayload;
use App\Support\CatalogImport\DTO\RecordMappingResult;
use App\Support\CatalogImport\Enums\ImportErrorLevel;
use App\Support\CatalogImport\Xml\XmlRecord;
use DOMDocument;
use DOMElement;
use DOMXPath;
use SimpleXMLElement;

final class MetaltecSupplierAdapter implements SupplierAdapterInterface
{
    public function __construct(
        private readonly MetaltecSupplierProfile $profile = new MetaltecSupplierProfile,
    ) {}

    public function mapRecord(mixed $record): RecordMappingResult
    {
        if (! $record instanceof XmlRecord) {
            return new RecordMappingResult(
                payload: null,
                errors: [
                    new ImportError(
                        code: 'invalid_record_type',
                        message: 'Ожидался экземпляр XmlRecord.',
                        level: ImportErrorLevel::Fatal,
                    ),
                ],
            );
        }

        $item = $this->loadItemXml($record->xml);

        if (! $item instanceof SimpleXMLElement) {
            return new RecordMappingResult(
                payload: null,
                errors: [
                    new ImportError(
                        code: 'invalid_item_xml',
                        message: 'XML item-запись не является корректным XML-документом.',
                    ),
                ],
            );
        }

        $externalId = $this->text($item->{'ID'} ?? null);

        if ($externalId === null) {
            return new RecordMappingResult(
                payload: null,
                errors: [
                    new ImportError(
                        code: 'missing_item_id',
                        message: 'XML item-запись не содержит обязательный ID.',
                    ),
                ],
            );
        }

        $name = $this->text($item->{'Наименование'} ?? null) ?? $this->profile->fallbackName($externalId);

        if ($name === null) {
            return new RecordMappingResult(
                payload: null,
                errors: [
                    new ImportError(
                        code: 'missing_name',
                        message: 'XML item-запись не содержит названия товара.',
                    ),
                ],
            );
        }

        $priceRub = $this->normalizePriceAmount($item->{'ЦенаРуб'} ?? null);
        $oldPriceRub = $this->normalizePriceAmount($item->{'СтараяЦенаРуб'} ?? null);
        [$priceAmount, $discountPrice, $currency] = $this->resolvePricing($priceRub, $oldPriceRub);
        $status = $this->text($item->{'Статус'} ?? null);
        $section = $this->profile->normalizeSection($this->text($item->{'Раздел'} ?? null));
        $categoryId = $this->profile->categoryIdForSection($section);
        $inStock = $this->resolveInStock($status);
        $description = $this->mergeHtmlFragments(
            $this->normalizeHtmlFragment($this->text($item->{'Описание'} ?? null)),
            $this->normalizeHtmlFragment($this->text($item->{'КонструктивныеОсобенности'} ?? null)),
        );
        $images = $this->resolveImages(
            $this->text($item->{'Фото'} ?? null),
            $this->text($item->{'ФотоДоп'} ?? null),
        );

        return new RecordMappingResult(
            payload: new ProductPayload(
                externalId: $externalId,
                name: $name,
                description: $description,
                brand: $this->profile->defaults()['brand'] ?? 'Metaltec',
                priceAmount: $priceAmount,
                currency: $currency,
                inStock: $inStock,
                qty: $inStock ? 1 : 0,
                images: $images,
                attributes: $this->resolveAttributes($item),
                source: [
                    'supplier' => $this->profile->supplierKey(),
                    'profile' => $this->profile->profileKey(),
                    'format' => 'xml',
                    'external_id' => $externalId,
                    'section' => $section,
                    'category_id' => $categoryId,
                    'status' => $status,
                    'source_currency' => $this->normalizeCurrency($item->{'Валюта'} ?? null),
                    'source_price' => $this->normalizePriceAmount($item->{'Цена'} ?? null),
                    'source_old_price' => $this->normalizePriceAmount($item->{'СтараяЦена'} ?? null),
                    'source_price_rub' => $priceRub,
                    'source_old_price_rub' => $oldPriceRub,
                    'legacy_match' => $this->profile->defaults()['legacy_match'] ?? null,
                ],
                title: $name,
                country: $this->text($item->{'Страна'} ?? null),
                discountPrice: $discountPrice,
                short: $this->normalizeShortText($this->text($item->{'Анонс'} ?? null)),
                metaTitle: $name,
            ),
        );
    }

    private function mergeHtmlFragments(?string $description, ?string $extraDescription): ?string
    {
        if ($description === null) {
            return $extraDescription;
        }

        if ($extraDescription === null) {
            return $description;
        }

        return trim($description)."\n\n".trim($extraDescription);
    }

    private function loadItemXml(string $xml): ?SimpleXMLElement
    {
        $xml = trim($xml);

        if ($xml === '') {
            return null;
        }

        $previousState = libxml_use_internal_errors(true);

        try {
            $item = simplexml_load_string($xml);

            return $item instanceof SimpleXMLElement ? $item : null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousState);
        }
    }

    private function text(mixed $value): ?string
    {
        if ($value instanceof SimpleXMLElement) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function normalizePriceAmount(mixed $value): ?int
    {
        $value = $this->text($value);

        if ($value === null) {
            return null;
        }

        $normalized = str_replace(["\xc2\xa0", ' '], '', $value);
        $normalized = str_replace(',', '.', $normalized);
        $normalized = preg_replace('/[^0-9.-]/', '', $normalized) ?? '';

        if ($normalized === '' || ! is_numeric($normalized)) {
            return null;
        }

        return max(0, (int) round((float) $normalized));
    }

    /**
     * @return array{0: int|null, 1: int|null, 2: string|null}
     */
    private function resolvePricing(?int $priceRub, ?int $oldPriceRub): array
    {
        if ($priceRub === null && $oldPriceRub === null) {
            return [null, null, null];
        }

        if ($priceRub !== null && $oldPriceRub !== null && $oldPriceRub > $priceRub) {
            return [$oldPriceRub, $priceRub, 'RUB'];
        }

        if ($priceRub !== null) {
            return [$priceRub, null, 'RUB'];
        }

        return [$oldPriceRub, null, 'RUB'];
    }

    private function normalizeCurrency(mixed $value): ?string
    {
        $currency = $this->text($value);

        if ($currency === null) {
            return null;
        }

        $currency = mb_strtoupper($currency);
        $currency = preg_replace('/[^A-Z]/', '', $currency) ?? '';

        if ($currency === 'RUR') {
            return 'RUB';
        }

        return strlen($currency) >= 3 ? substr($currency, 0, 3) : null;
    }

    private function resolveInStock(?string $status): bool
    {
        if ($status === null) {
            return false;
        }

        $normalized = mb_strtolower($status);
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return str_contains($normalized, 'в наличии');
    }

    /**
     * @return array<int, string>
     */
    private function resolveImages(?string $mainImage, ?string $extraImages): array
    {
        $images = [];

        foreach ([$mainImage] as $image) {
            $resolved = $this->resolveUrl($image);

            if ($resolved !== null) {
                $images[mb_strtolower($resolved)] = $resolved;
            }
        }

        if ($extraImages !== null) {
            foreach (explode(',', $extraImages) as $image) {
                $resolved = $this->resolveUrl($image);

                if ($resolved !== null) {
                    $images[mb_strtolower($resolved)] = $resolved;
                }
            }
        }

        return array_values($images);
    }

    /**
     * @return array<int, array{name:string,value:string,source:string}>
     */
    private function resolveAttributes(SimpleXMLElement $item): array
    {
        $attributes = $this->parseSpecsHtml($this->text($item->{'Характеристики'} ?? null));

        foreach ([
            'Раздел' => 'Раздел',
            'ОбщийВес' => 'Общий вес',
            'ОбщийОбъём' => 'Общий объем',
            'МаксимальнаяДлинаГиба' => 'Максимальная длина гиба',
            'УсилиеГибки' => 'Усилие гибки',
        ] as $field => $label) {
            $value = $this->text($item->{$field} ?? null);

            if ($value === null) {
                continue;
            }

            $attributes[] = [
                'name' => $label,
                'value' => $value,
                'source' => 'import',
            ];
        }

        return $this->uniqueAttributes($attributes);
    }

    /**
     * @return array<int, array{name:string,value:string,source:string}>
     */
    private function parseSpecsHtml(?string $html): array
    {
        if ($html === null) {
            return [];
        }

        $root = $this->loadHtmlRoot($html);

        if (! $root instanceof DOMElement) {
            return [];
        }

        $xpath = new DOMXPath($root->ownerDocument);
        $sections = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " productMain__characteristics ")]', $root);

        if ($sections === false) {
            return [];
        }

        $attributes = [];

        foreach ($sections as $section) {
            if (! $section instanceof DOMElement) {
                continue;
            }

            $items = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " productMain__list-item ")]', $section);

            if ($items === false) {
                continue;
            }

            foreach ($items as $item) {
                if (! $item instanceof DOMElement) {
                    continue;
                }

                $values = [];

                foreach ($item->childNodes as $child) {
                    if (! $child instanceof DOMElement) {
                        continue;
                    }

                    $text = $this->normalizeDomText($child->textContent);

                    if ($text !== null) {
                        $values[] = $text;
                    }
                }

                if (count($values) < 2) {
                    continue;
                }

                $name = $values[0];
                $value = $values[1];

                $attributes[] = [
                    'name' => $name,
                    'value' => $value,
                    'source' => 'import',
                ];
            }
        }

        return $attributes;
    }

    private function normalizeShortText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = preg_replace('/<br\s*\/?>/iu', '; ', $value) ?? $value;
        $value = strip_tags($value);
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s*;\s*/u', '; ', $value) ?? $value;
        $value = trim($value, " \t\n\r\0\x0B;");

        return $value !== '' ? $value : null;
    }

    private function normalizeHtmlFragment(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        $root = $this->loadHtmlRoot($html);

        if (! $root instanceof DOMElement) {
            return $html;
        }

        $xpath = new DOMXPath($root->ownerDocument);

        foreach (['href', 'src'] as $attribute) {
            $nodes = $xpath->query(sprintf('.//*[@%s]', $attribute), $root);

            if ($nodes === false) {
                continue;
            }

            foreach ($nodes as $node) {
                if (! $node instanceof DOMElement) {
                    continue;
                }

                $resolved = $this->resolveUrl($node->getAttribute($attribute));

                if ($resolved !== null) {
                    $node->setAttribute($attribute, $resolved);
                }
            }
        }

        $html = $this->extractInnerHtml($root);
        $html = trim($html);

        return $html !== '' ? $html : null;
    }

    private function loadHtmlRoot(string $html): ?DOMElement
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previousState = libxml_use_internal_errors(true);
        $wrappedHtml = '<?xml encoding="UTF-8"><div id="metaltec-fragment-root">'.$html.'</div>';

        try {
            $loaded = $dom->loadHTML(
                $wrappedHtml,
                LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOWARNING | LIBXML_NOERROR
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousState);
        }

        if (! $loaded) {
            return null;
        }

        $root = $dom->getElementById('metaltec-fragment-root');

        return $root instanceof DOMElement ? $root : null;
    }

    private function extractInnerHtml(DOMElement $root): string
    {
        $html = '';

        foreach ($root->childNodes as $child) {
            $html .= $root->ownerDocument->saveHTML($child) ?: '';
        }

        return $html;
    }

    private function normalizeDomText(?string $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function resolveUrl(?string $value): ?string
    {
        $value = $this->text($value);

        if ($value === null) {
            return null;
        }

        if (filter_var($value, FILTER_VALIDATE_URL) !== false) {
            return $value;
        }

        if (str_starts_with($value, '//')) {
            return 'https:'.$value;
        }

        $baseUrl = rtrim($this->profile->sourceBaseUrl(), '/');

        if (str_starts_with($value, '/')) {
            return $baseUrl.$value;
        }

        return $baseUrl.'/'.$value;
    }

    /**
     * @param  array<int, array{name:string,value:string,source:string}>  $attributes
     * @return array<int, array{name:string,value:string,source:string}>
     */
    private function uniqueAttributes(array $attributes): array
    {
        $unique = [];

        foreach ($attributes as $attribute) {
            $name = $this->normalizeDomText($attribute['name'] ?? null);
            $value = $this->normalizeDomText($attribute['value'] ?? null);

            if ($name === null || $value === null) {
                continue;
            }

            $key = mb_strtolower($name.'::'.$value);

            if (isset($unique[$key])) {
                continue;
            }

            $unique[$key] = [
                'name' => $name,
                'value' => $value,
                'source' => $attribute['source'] ?? 'import',
            ];
        }

        return array_values($unique);
    }
}
