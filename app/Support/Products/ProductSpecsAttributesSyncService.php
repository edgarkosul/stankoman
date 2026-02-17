<?php

namespace App\Support\Products;

use App\Models\Attribute;
use App\Models\AttributeOption;
use App\Models\Product;
use App\Models\ProductAttributeOption;
use App\Models\ProductAttributeValue;
use App\Models\Unit;
use App\Support\NameNormalizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProductSpecsAttributesSyncService
{
    /**
     * @return array{updated_pav:int,updated_pao:int,skipped:int,unchanged:int}
     */
    public function sync(Product $product, mixed $rawSpecs): array
    {
        $result = [
            'updated_pav' => 0,
            'updated_pao' => 0,
            'skipped' => 0,
            'unchanged' => 0,
        ];

        $specIndex = $this->buildSpecIndex($rawSpecs);

        if ($specIndex === []) {
            return $result;
        }

        DB::transaction(function () use ($product, $specIndex, &$result): void {
            $product->loadMissing([
                'attributeValues.attribute.unit',
                'attributeValues.attribute.units',
                'attributeOptions.attribute.options',
            ]);

            foreach ($product->attributeValues as $valueRow) {
                if (! $valueRow instanceof ProductAttributeValue || ! $valueRow->attribute instanceof Attribute) {
                    continue;
                }

                $attribute = $valueRow->attribute;

                if ($attribute->usesOptions()) {
                    continue;
                }

                $normalizedAttributeName = NameNormalizer::normalize($attribute->name);

                if ($normalizedAttributeName === null || ! array_key_exists($normalizedAttributeName, $specIndex)) {
                    continue;
                }

                $parsed = $this->parsePavValue(
                    attribute: $attribute,
                    rawValue: $specIndex[$normalizedAttributeName],
                );

                if (! $parsed['ok']) {
                    $result['skipped']++;

                    continue;
                }

                $targetValue = $parsed['value'];

                if ($this->isSamePavValue($valueRow, $attribute, $targetValue)) {
                    $result['unchanged']++;

                    continue;
                }

                $valueRow->setTypedValue($attribute, $targetValue);
                $valueRow->attribute()->associate($attribute);
                $valueRow->save();

                $result['updated_pav']++;
            }

            /** @var Collection<int, Collection<int, AttributeOption>> $optionsByAttributeId */
            $optionsByAttributeId = $product->attributeOptions->groupBy(
                fn (AttributeOption $option): int => (int) $option->pivot->attribute_id
            );

            foreach ($optionsByAttributeId as $attributeId => $selectedOptions) {
                if (! $selectedOptions instanceof Collection || $selectedOptions->isEmpty()) {
                    continue;
                }

                $firstOption = $selectedOptions->first();
                $attribute = $firstOption instanceof AttributeOption
                    ? $firstOption->attribute
                    : null;

                if (! $attribute instanceof Attribute) {
                    $attribute = Attribute::query()
                        ->with('options')
                        ->find((int) $attributeId);
                }

                if (! $attribute instanceof Attribute || ! $attribute->usesOptions()) {
                    continue;
                }

                $normalizedAttributeName = NameNormalizer::normalize($attribute->name);

                if ($normalizedAttributeName === null || ! array_key_exists($normalizedAttributeName, $specIndex)) {
                    continue;
                }

                $parsedOptionIds = $this->parseOptionIds(
                    attribute: $attribute,
                    rawValue: $specIndex[$normalizedAttributeName],
                );

                if (! $parsedOptionIds['ok']) {
                    $result['skipped']++;

                    continue;
                }

                $currentOptionIds = $selectedOptions
                    ->pluck('id')
                    ->map(fn (mixed $id): int => (int) $id)
                    ->filter(fn (int $id): bool => $id > 0)
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();

                $targetOptionIds = collect($parsedOptionIds['option_ids'])
                    ->map(fn (mixed $id): int => (int) $id)
                    ->filter(fn (int $id): bool => $id > 0)
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();

                if ($currentOptionIds === $targetOptionIds) {
                    $result['unchanged']++;

                    continue;
                }

                if ($attribute->input_type === 'select') {
                    ProductAttributeOption::setSingle(
                        (int) $product->getKey(),
                        (int) $attribute->getKey(),
                        $targetOptionIds[0] ?? null,
                    );
                } else {
                    ProductAttributeOption::setForProductAttribute(
                        (int) $product->getKey(),
                        (int) $attribute->getKey(),
                        $targetOptionIds,
                    );
                }

                $result['updated_pao']++;
            }

            $product->unsetRelation('attributeValues');
            $product->unsetRelation('attributeOptions');
        });

        return $result;
    }

    /**
     * @return array<string, string>
     */
    private function buildSpecIndex(mixed $rawSpecs): array
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

        $specIndex = [];

        foreach ($rawSpecs as $rowKey => $rowValue) {
            $name = null;
            $value = null;

            if (is_array($rowValue) && (array_key_exists('name', $rowValue) || array_key_exists('value', $rowValue))) {
                $name = $this->stringFromMixed($rowValue['name'] ?? null);
                $value = $this->stringFromMixed($rowValue['value'] ?? null);
            } elseif (is_string($rowKey)) {
                $name = $this->stringFromMixed($rowKey);
                $value = $this->stringFromMixed($rowValue);
            }

            if ($name === null || $value === null) {
                continue;
            }

            $normalizedName = NameNormalizer::normalize($name);

            if ($normalizedName === null) {
                continue;
            }

            $specIndex[$normalizedName] = $value;
        }

        return $specIndex;
    }

    private function stringFromMixed(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) || is_int($value) || is_float($value)) {
            $normalized = trim((string) $value);

            return $normalized === '' ? null : $normalized;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value)) {
            $items = collect($value)
                ->map(function (mixed $item): ?string {
                    if (is_string($item) || is_int($item) || is_float($item)) {
                        $item = trim((string) $item);

                        return $item === '' ? null : $item;
                    }

                    if (is_bool($item)) {
                        return $item ? '1' : '0';
                    }

                    return null;
                })
                ->filter(fn (?string $item): bool => $item !== null)
                ->values()
                ->all();

            if ($items === []) {
                return null;
            }

            return implode(', ', array_unique($items));
        }

        return null;
    }

    /**
     * @return array{ok:bool, value:mixed}
     */
    private function parsePavValue(Attribute $attribute, string $rawValue): array
    {
        if ($attribute->isBoolean()) {
            $parsedBoolean = $this->parseBooleanToken($rawValue);

            if ($parsedBoolean === null) {
                return ['ok' => false, 'value' => null];
            }

            return ['ok' => true, 'value' => $parsedBoolean];
        }

        if ($attribute->isNumber() || $attribute->isRange()) {
            $parsedNumbers = $this->parseNumbersWithUnit($rawValue);

            if ($parsedNumbers['numbers'] === []) {
                return ['ok' => false, 'value' => null];
            }

            $unitToken = $parsedNumbers['unit_token'];
            $numbers = $parsedNumbers['numbers'];

            if ($attribute->isNumber()) {
                $converted = $this->convertToAttributeUnit($attribute, $numbers[0], $unitToken);

                if (! $converted['ok']) {
                    return ['ok' => false, 'value' => null];
                }

                return ['ok' => true, 'value' => (float) $converted['value']];
            }

            $min = $numbers[0];
            $max = $numbers[1] ?? $numbers[0];

            $convertedMin = $this->convertToAttributeUnit($attribute, $min, $unitToken);
            $convertedMax = $this->convertToAttributeUnit($attribute, $max, $unitToken);

            if (! $convertedMin['ok'] || ! $convertedMax['ok']) {
                return ['ok' => false, 'value' => null];
            }

            $rangeMin = (float) $convertedMin['value'];
            $rangeMax = (float) $convertedMax['value'];

            if ($rangeMin > $rangeMax) {
                [$rangeMin, $rangeMax] = [$rangeMax, $rangeMin];
            }

            return [
                'ok' => true,
                'value' => [
                    'min' => $rangeMin,
                    'max' => $rangeMax,
                ],
            ];
        }

        $text = trim($rawValue);

        if ($text === '') {
            return ['ok' => false, 'value' => null];
        }

        return ['ok' => true, 'value' => $text];
    }

    private function isSamePavValue(ProductAttributeValue $valueRow, Attribute $attribute, mixed $targetValue): bool
    {
        if ($attribute->isBoolean()) {
            return $valueRow->value_boolean === (bool) $targetValue;
        }

        if ($attribute->isNumber()) {
            $current = $valueRow->value_number;

            if ($current === null && $valueRow->value_si !== null) {
                $current = $attribute->fromSi((float) $valueRow->value_si);
            }

            if ($current === null) {
                return false;
            }

            return $this->floatsEqual((float) $current, (float) $targetValue);
        }

        if ($attribute->isRange()) {
            $currentMin = $valueRow->value_min;
            $currentMax = $valueRow->value_max;

            if ($currentMin === null && $valueRow->value_min_si !== null) {
                $currentMin = $attribute->fromSi((float) $valueRow->value_min_si);
            }

            if ($currentMax === null && $valueRow->value_max_si !== null) {
                $currentMax = $attribute->fromSi((float) $valueRow->value_max_si);
            }

            if (! is_array($targetValue) || ! array_key_exists('min', $targetValue) || ! array_key_exists('max', $targetValue)) {
                return false;
            }

            if ($currentMin === null || $currentMax === null) {
                return false;
            }

            return $this->floatsEqual((float) $currentMin, (float) $targetValue['min'])
                && $this->floatsEqual((float) $currentMax, (float) $targetValue['max']);
        }

        $currentText = $valueRow->value_text;

        if ($currentText === null) {
            return false;
        }

        return trim($currentText) === trim((string) $targetValue);
    }

    private function floatsEqual(float $left, float $right): bool
    {
        return abs($left - $right) < 0.000001;
    }

    private function parseBooleanToken(string $rawValue): ?bool
    {
        $normalized = NameNormalizer::normalize($rawValue) ?? mb_strtolower(trim($rawValue), 'UTF-8');

        if (in_array($normalized, ['1', 'true', 'yes', 'да', 'есть', 'on'], true)) {
            return true;
        }

        if (in_array($normalized, ['0', 'false', 'no', 'нет', 'off'], true)) {
            return false;
        }

        return null;
    }

    /**
     * @return array{numbers:array<int, float>, unit_token:?string}
     */
    private function parseNumbersWithUnit(string $rawValue): array
    {
        $normalized = str_replace([
            "\xC2\xA0",
            "\xE2\x80\xAF",
            '−',
            '—',
            '–',
        ], [
            ' ',
            ' ',
            '-',
            '-',
            '-',
        ], $rawValue);

        $normalized = preg_replace('/(?<=\d)\s*-\s*(?=\d)/u', ' ', $normalized) ?? $normalized;

        preg_match_all('/[-+]?\d+(?:[.,]\d+)?/u', $normalized, $matches);

        $numbers = collect($matches[0] ?? [])
            ->map(function (string $chunk): ?float {
                $chunk = str_replace(',', '.', $chunk);

                if (! is_numeric($chunk)) {
                    return null;
                }

                return (float) $chunk;
            })
            ->filter(fn (?float $number): bool => $number !== null)
            ->values()
            ->all();

        return [
            'numbers' => $numbers,
            'unit_token' => $this->extractUnitToken($normalized),
        ];
    }

    private function extractUnitToken(string $rawValue): ?string
    {
        $withoutNumbers = preg_replace('/[-+]?\d+(?:[.,]\d+)?/u', ' ', $rawValue) ?? $rawValue;
        $withoutNumbers = str_replace(['-', '—', '–', '~', '(', ')', ':'], ' ', $withoutNumbers);
        $withoutNumbers = preg_replace('/\s+/u', ' ', trim($withoutNumbers)) ?? trim($withoutNumbers);

        if ($withoutNumbers === '') {
            return null;
        }

        $parts = preg_split('/\s+/u', $withoutNumbers) ?: [];
        $last = end($parts);

        if (! is_string($last)) {
            return null;
        }

        $last = trim($last, " \t\n\r\0\x0B,.;");

        return $last === '' ? null : $last;
    }

    /**
     * @return array{ok:bool, value:float}
     */
    private function convertToAttributeUnit(Attribute $attribute, float $value, ?string $unitToken): array
    {
        if ($unitToken === null) {
            return [
                'ok' => true,
                'value' => $value,
            ];
        }

        $resolvedUnit = $this->resolveUnitByToken($attribute, $unitToken);

        if (! $resolvedUnit instanceof Unit) {
            return [
                'ok' => false,
                'value' => 0.0,
            ];
        }

        if (! $attribute->defaultUnit()) {
            return [
                'ok' => false,
                'value' => 0.0,
            ];
        }

        $si = $attribute->toSiWithUnit($value, $resolvedUnit);

        if ($si === null) {
            return [
                'ok' => false,
                'value' => 0.0,
            ];
        }

        $converted = $attribute->fromSi($si);

        if ($converted === null) {
            return [
                'ok' => false,
                'value' => 0.0,
            ];
        }

        return [
            'ok' => true,
            'value' => (float) $converted,
        ];
    }

    private function resolveUnitByToken(Attribute $attribute, string $unitToken): ?Unit
    {
        $lookupToken = $this->normalizeLookupToken($unitToken);

        if ($lookupToken === null) {
            return null;
        }

        $units = $attribute->relationLoaded('units')
            ? $attribute->units
            : $attribute->units()->get();

        if (
            $attribute->unit instanceof Unit
            && ! $units->contains(fn (Unit $unit): bool => (int) $unit->getKey() === (int) $attribute->unit?->getKey())
        ) {
            $units = $units->prepend($attribute->unit);
        }

        $matches = $units
            ->filter(function (Unit $unit) use ($lookupToken): bool {
                return in_array($lookupToken, $this->unitLookupTokens($unit), true);
            })
            ->values();

        if ($matches->count() !== 1) {
            return null;
        }

        return $matches->first();
    }

    /**
     * @return array<int, string>
     */
    private function unitLookupTokens(Unit $unit): array
    {
        $tokens = [
            $this->normalizeLookupToken($unit->symbol),
            $this->normalizeLookupToken($unit->name),
            $this->normalizeLookupToken($unit->base_symbol),
        ];

        return array_values(array_filter(array_unique($tokens)));
    }

    /**
     * @return array{ok:bool, option_ids:array<int, int>}
     */
    private function parseOptionIds(Attribute $attribute, string $rawValue): array
    {
        $candidates = $this->extractOptionCandidates($rawValue);

        if ($candidates === []) {
            return [
                'ok' => false,
                'option_ids' => [],
            ];
        }

        if ($attribute->input_type === 'select' && count($candidates) !== 1) {
            return [
                'ok' => false,
                'option_ids' => [],
            ];
        }

        $optionIndex = $this->buildOptionIndex($attribute);
        $optionIds = [];

        foreach ($candidates as $candidate) {
            $lookupToken = $this->normalizeLookupToken($candidate);

            if ($lookupToken === null || ! isset($optionIndex[$lookupToken])) {
                return [
                    'ok' => false,
                    'option_ids' => [],
                ];
            }

            $optionIds[] = (int) $optionIndex[$lookupToken]->getKey();
        }

        $optionIds = array_values(array_unique($optionIds));

        return [
            'ok' => $optionIds !== [],
            'option_ids' => $optionIds,
        ];
    }

    /**
     * @return array<string, AttributeOption>
     */
    private function buildOptionIndex(Attribute $attribute): array
    {
        $attribute->loadMissing('options');

        $index = [];

        foreach ($attribute->options as $option) {
            if (! $option instanceof AttributeOption) {
                continue;
            }

            $lookupToken = $this->normalizeLookupToken($option->value);

            if ($lookupToken === null || isset($index[$lookupToken])) {
                continue;
            }

            $index[$lookupToken] = $option;
        }

        return $index;
    }

    private function normalizeLookupToken(?string $value): ?string
    {
        $normalized = NameNormalizer::normalize($value);

        if ($normalized === null) {
            return null;
        }

        $normalized = strtr($normalized, [
            '⁰' => '0',
            '¹' => '1',
            '²' => '2',
            '³' => '3',
            '⁴' => '4',
            '⁵' => '5',
            '⁶' => '6',
            '⁷' => '7',
            '⁸' => '8',
            '⁹' => '9',
            '₀' => '0',
            '₁' => '1',
            '₂' => '2',
            '₃' => '3',
            '₄' => '4',
            '₅' => '5',
            '₆' => '6',
            '₇' => '7',
            '₈' => '8',
            '₉' => '9',
        ]);

        $normalized = str_replace([' ', '.'], '', $normalized);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @return array<int, string>
     */
    private function extractOptionCandidates(string $rawValue): array
    {
        $rawValue = trim($rawValue);

        if ($rawValue === '') {
            return [];
        }

        $parts = preg_split('/\s*(?:,|;|\||\n|\r)+\s*/u', $rawValue) ?: [];

        if (count($parts) === 1 && str_contains($rawValue, ' / ')) {
            $parts = preg_split('/\s+\/\s+/u', $rawValue) ?: $parts;
        }

        $parts = array_map(static fn (string $value): string => trim($value), $parts);
        $parts = array_values(array_filter($parts, static fn (string $value): bool => $value !== ''));

        return array_values(array_unique($parts));
    }
}
