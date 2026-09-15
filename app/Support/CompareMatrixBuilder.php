<?php

namespace App\Support;

use App\Models\Attribute;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttributeOption;
use App\Models\ProductAttributeValue;
use App\Models\Unit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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

        $products->loadMissing('categories');

        /** @var array<int, Category|null> $primaryCategories */
        $primaryCategories = $products
            ->mapWithKeys(fn (Product $product): array => [(int) $product->id => $product->primaryCategory()])
            ->all();

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

        $rowUnits = $this->resolveRowUnits($attributeValues, $primaryCategories);

        $attributeMeta = [];
        $cellsByAttribute = [];

        $ensureMeta = function (Attribute $attribute) use (&$attributeMeta, $rowUnits): void {
            if (isset($attributeMeta[$attribute->id])) {
                return;
            }

            $unit = $rowUnits[$attribute->id]['unit'] ?? $attribute->unit;

            $attributeMeta[$attribute->id] = [
                'id' => $attribute->id,
                'name' => $attribute->name,
                'group' => $attribute->group,
                'type' => $attribute->data_type,
                'unit' => $unit?->symbol ?? $unit?->name,
                '_unit' => $unit,
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

            // Числа — по правилам категории товара, но только когда строка идёт
            // в единице категорий; иначе по правилам самого атрибута.
            $formatCategory = ($rowUnits[$attribute->id]['by_category'] ?? false)
                ? ($primaryCategories[(int) $row->product_id] ?? null)
                : null;

            $type = $metaAttribute->data_type;

            if ($type === 'number') {
                $si = $this->toSi($row->value_si, $row->value_number, $metaAttribute);

                $cellsByAttribute[$attribute->id][$row->product_id] = [
                    'label' => $si === null ? null : $this->formatNumberUi($si, $metaUnit, $metaAttribute, $formatCategory),
                    'normalized' => $row->value_si ?? $row->value_number,
                ];

                continue;
            }

            if ($type === 'range') {
                $minSi = $this->toSi($row->value_min_si, $row->value_min, $metaAttribute);
                $maxSi = $this->toSi($row->value_max_si, $row->value_max, $metaAttribute);

                $label = null;
                if ($minSi !== null && $maxSi !== null) {
                    $label = $this->formatNumberUi($minSi, $metaUnit, $metaAttribute, $formatCategory).' — '.$this->formatNumberUi($maxSi, $metaUnit, $metaAttribute, $formatCategory);
                } elseif ($minSi !== null) {
                    $label = '≥ '.$this->formatNumberUi($minSi, $metaUnit, $metaAttribute, $formatCategory);
                } elseif ($maxSi !== null) {
                    $label = '≤ '.$this->formatNumberUi($maxSi, $metaUnit, $metaAttribute, $formatCategory);
                }

                $cellsByAttribute[$attribute->id][$row->product_id] = [
                    'label' => $label,
                    'normalized' => [
                        'min' => $row->value_min_si ?? $row->value_min,
                        'max' => $row->value_max_si ?? $row->value_max,
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
                'category' => $primaryCategories[(int) $product->id]?->name,
                'values' => $values,
            ];
        }

        return [
            'attributes' => $rows,
            'products' => $columns,
        ];
    }

    /**
     * Единица строки сравнения для числовых атрибутов.
     *
     * Если категории всех сравниваемых товаров показывают атрибут в одной
     * единице, строка идёт в ней — так же, как на карточках. Если единицы
     * расходятся, строка остаётся в единице атрибута: л.с. и кВт в одной
     * строке не сравнить.
     *
     * @param  Collection<int, ProductAttributeValue>  $attributeValues
     * @param  array<int, Category|null>  $primaryCategories
     * @return array<int, array{unit: Unit|null, by_category: bool}>
     */
    private function resolveRowUnits(Collection $attributeValues, array $primaryCategories): array
    {
        $numericValues = $attributeValues->filter(
            fn (ProductAttributeValue $row): bool => in_array($row->attribute?->data_type, ['number', 'range'], true)
        );

        if ($numericValues->isEmpty()) {
            return [];
        }

        $categoryIds = collect($primaryCategories)
            ->filter()
            ->map(fn (Category $category): int => (int) $category->getKey())
            ->unique()
            ->values()
            ->all();

        $pivotRows = $categoryIds === []
            ? collect()
            : DB::table('category_attribute')
                ->whereIn('category_id', $categoryIds)
                ->whereIn('attribute_id', $numericValues->pluck('attribute_id')->unique()->values()->all())
                ->whereNotNull('display_unit_id')
                ->get(['category_id', 'attribute_id', 'display_unit_id']);

        $units = Unit::query()
            ->whereIn('id', $pivotRows->pluck('display_unit_id')->unique()->values()->all())
            ->get()
            ->keyBy('id');

        $displayUnits = [];

        foreach ($pivotRows as $pivot) {
            $displayUnits[(int) $pivot->category_id][(int) $pivot->attribute_id] = $units->get((int) $pivot->display_unit_id);
        }

        $rowUnits = [];

        foreach ($numericValues->groupBy('attribute_id') as $attributeId => $rows) {
            /** @var Attribute $attribute */
            $attribute = $rows->first()->attribute;

            $candidates = $rows->map(function (ProductAttributeValue $row) use ($attribute, $primaryCategories, $displayUnits): ?Unit {
                $category = $primaryCategories[(int) $row->product_id] ?? null;

                return ($category ? ($displayUnits[(int) $category->getKey()][(int) $attribute->id] ?? null) : null)
                    ?? $attribute->unit;
            });

            $sameUnit = $candidates->map(fn (?Unit $unit): ?int => $unit?->id)->unique()->count() === 1;

            $rowUnits[(int) $attributeId] = $sameUnit
                ? ['unit' => $candidates->first(), 'by_category' => true]
                : ['unit' => $attribute->unit, 'by_category' => false];
        }

        return $rowUnits;
    }

    private function toSi(?float $si, ?float $value, Attribute $attribute): ?float
    {
        if ($si !== null) {
            return $si;
        }

        return $value === null ? null : $attribute->toSi($value);
    }

    private function formatNumberUi(float $si, ?Unit $unit, Attribute $attribute, ?Category $category): string
    {
        $formatted = $attribute->formatNumberForCategory((float) $attribute->fromSiWithUnit($si, $unit), $category);

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
