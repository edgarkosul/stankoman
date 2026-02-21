<?php

namespace App\Support;

use App\Models\Attribute;
use App\Models\Product;
use App\Models\ProductAttributeOption;
use App\Models\ProductAttributeValue;
use App\Models\Unit;
use Illuminate\Support\Collection;

class CompareMatrixBuilder
{
    /**
     * @param  Collection<int, Product>  $products
     * @param  array{hideEquals?: bool, hideEmpty?: bool}  $opts
     * @return array{attributes: array<int, array<string, mixed>>, products: array<int, array<string, mixed>>}
     */
    public function build(Collection $products, array $opts = []): array
    {
        $productIds = $products->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

        if ($productIds === []) {
            return [
                'attributes' => [],
                'products' => [],
            ];
        }

        /** @var Collection<int, ProductAttributeValue> $attributeValues */
        $attributeValues = ProductAttributeValue::query()
            ->with(['attribute.unit'])
            ->whereIn('product_id', $productIds)
            ->get();

        /** @var Collection<int, ProductAttributeOption> $attributeOptions */
        $attributeOptions = ProductAttributeOption::query()
            ->with(['option.attribute.unit'])
            ->whereIn('product_id', $productIds)
            ->get();

        $attributeMeta = [];
        $cellsByAttribute = [];

        $ensureMeta = function (Attribute $attribute) use (&$attributeMeta): void {
            if (isset($attributeMeta[$attribute->id])) {
                return;
            }

            $attributeMeta[$attribute->id] = [
                'id' => $attribute->id,
                'name' => $attribute->name,
                'group' => $attribute->group,
                'type' => $attribute->data_type,
                'unit' => $attribute->unit?->symbol ?? $attribute->unit?->name,
                '_unit' => $attribute->unit,
                '_attribute' => $attribute,
            ];
        };

        foreach ($attributeValues as $row) {
            $attribute = $row->attribute;
            if (! $attribute) {
                continue;
            }

            $ensureMeta($attribute);
            /** @var Attribute $metaAttribute */
            $metaAttribute = $attributeMeta[$attribute->id]['_attribute'];
            /** @var Unit|null $metaUnit */
            $metaUnit = $attributeMeta[$attribute->id]['_unit'];

            $type = $metaAttribute->data_type;

            if ($type === 'number') {
                $number = $row->value_number;

                $cellsByAttribute[$attribute->id][$row->product_id] = [
                    'label' => $number === null ? null : $this->formatNumberUi((float) $number, $metaUnit, $metaAttribute),
                    'normalized' => $row->value_si ?? $number,
                ];

                continue;
            }

            if ($type === 'range') {
                $min = $row->value_min;
                $max = $row->value_max;

                $label = null;
                if ($min !== null && $max !== null) {
                    $label = $this->formatNumberUi((float) $min, $metaUnit, $metaAttribute).' — '.$this->formatNumberUi((float) $max, $metaUnit, $metaAttribute);
                } elseif ($min !== null) {
                    $label = '≥ '.$this->formatNumberUi((float) $min, $metaUnit, $metaAttribute);
                } elseif ($max !== null) {
                    $label = '≤ '.$this->formatNumberUi((float) $max, $metaUnit, $metaAttribute);
                }

                $cellsByAttribute[$attribute->id][$row->product_id] = [
                    'label' => $label,
                    'normalized' => [
                        'min' => $row->value_min_si ?? $min,
                        'max' => $row->value_max_si ?? $max,
                    ],
                ];

                continue;
            }

            if ($type === 'boolean') {
                $value = $row->value_boolean;

                $cellsByAttribute[$attribute->id][$row->product_id] = [
                    'label' => $value === null ? null : ($value ? 'Да' : 'Нет'),
                    'normalized' => $value,
                ];

                continue;
            }

            $text = $row->value_text;
            $label = is_string($text) && trim($text) !== '' ? $text : null;

            $cellsByAttribute[$attribute->id][$row->product_id] = [
                'label' => $label,
                'normalized' => $label,
            ];
        }

        $optionAccumulator = [];

        foreach ($attributeOptions as $row) {
            $option = $row->option;
            if (! $option) {
                continue;
            }

            $attribute = $option->attribute;
            if (! $attribute) {
                continue;
            }

            $ensureMeta($attribute);
            $attributeMeta[$attribute->id]['type'] = 'option';

            $optionAccumulator[$attribute->id][$row->product_id]['labels'][] = $option->value ?? (string) $option->id;
            $optionAccumulator[$attribute->id][$row->product_id]['ids'][] = (int) $option->id;
        }

        foreach ($optionAccumulator as $attributeId => $productsByAttribute) {
            foreach ($productsByAttribute as $productId => $accumulator) {
                $labels = array_values(array_unique($accumulator['labels'] ?? []));
                sort($labels, SORT_NATURAL | SORT_FLAG_CASE);

                $ids = array_values(array_unique($accumulator['ids'] ?? []));
                sort($ids);

                $cellsByAttribute[$attributeId][$productId] = [
                    'label' => $labels !== [] ? implode(', ', $labels) : null,
                    'normalized' => $ids,
                ];
            }
        }

        $rows = [];

        foreach ($attributeMeta as $attributeId => $meta) {
            $filled = 0;
            $normalized = [];

            foreach ($productIds as $productId) {
                $cell = $cellsByAttribute[$attributeId][$productId] ?? null;

                if (($cell['label'] ?? null) !== null) {
                    $filled++;
                }

                $normalized[] = json_encode(
                    $cell['normalized'] ?? null,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
            }

            $allEqual = count(array_unique($normalized)) <= 1;

            if (($opts['hideEquals'] ?? false) && $allEqual) {
                continue;
            }

            if (($opts['hideEmpty'] ?? false) && $filled === 0) {
                continue;
            }

            $rows[] = [
                'id' => $meta['id'],
                'name' => $meta['name'],
                'group' => $meta['group'],
                'type' => $meta['type'],
                'unit' => $meta['unit'],
                'all_equal' => $allEqual,
                'filled' => $filled,
            ];
        }

        usort($rows, function (array $left, array $right): int {
            if ($left['all_equal'] !== $right['all_equal']) {
                return $left['all_equal'] <=> $right['all_equal'];
            }

            if ($left['filled'] !== $right['filled']) {
                return $right['filled'] <=> $left['filled'];
            }

            return [
                (string) ($left['group'] ?? ''),
                (string) $left['name'],
            ] <=> [
                (string) ($right['group'] ?? ''),
                (string) $right['name'],
            ];
        });

        $products->loadMissing('categories');

        $columns = [];

        foreach ($products as $product) {
            $values = [];

            foreach ($rows as $index => $rowMeta) {
                $attributeId = (int) $rowMeta['id'];
                $cell = $cellsByAttribute[$attributeId][$product->id] ?? null;

                $values[$index] = [
                    'label' => $cell['label'] ?? null,
                    'normalized' => $cell['normalized'] ?? null,
                ];
            }

            $columns[] = [
                'id' => $product->id,
                'name' => $product->name,
                'url' => route('product.show', $product),
                'image' => $product->image,
                'price' => $product->price_amount,
                'sku' => $product->sku,
                'brand' => $product->brand,
                'category' => $product->primaryCategory()?->name,
                'values' => $values,
            ];
        }

        return [
            'attributes' => $rows,
            'products' => $columns,
        ];
    }

    private function formatNumberUi(float $value, ?Unit $unit, Attribute $attribute): string
    {
        $precision = max(0, (int) ($attribute->number_decimals ?? 0));
        $formatted = number_format($value, $precision, ',', ' ');

        if (filled($attribute->display_format)) {
            return str_replace(
                ['{value}', '{unit}'],
                [$formatted, (string) ($unit?->symbol ?? '')],
                (string) $attribute->display_format,
            );
        }

        if ($unit?->symbol) {
            return $formatted.' '.$unit->symbol;
        }

        if ($unit?->name) {
            return $formatted.' '.$unit->name;
        }

        return $formatted;
    }
}
