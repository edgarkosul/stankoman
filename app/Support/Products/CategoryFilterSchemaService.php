<?php

namespace App\Support\Products;

use App\Models\Attribute as ProductAttribute;
use App\Models\Category;
use App\Models\Unit;
use InvalidArgumentException;

class CategoryFilterSchemaService
{
    public const TEMPLATE_TYPE = 'category_filter_v1';

    public const PRODUCTS_SHEET = 'Товары';

    public const REFERENCES_SHEET = 'Справочники';

    public const META_SHEET = '_meta';

    public const CLEAR_MARKER = '!clear';

    /**
     * @return array<int, string>
     */
    public function fixedColumns(): array
    {
        return ['product_id', 'name', 'sku', 'updated_at'];
    }

    /**
     * @return array{
     *     template_type:string,
     *     category_id:int,
     *     schema_hash:string,
     *     fixed_columns:array<int, string>,
     *     columns:array<int, array<string, mixed>>,
     *     attributes:array<int, array<string, mixed>>,
     *     attributes_by_key:array<string, array<string, mixed>>,
     * }
     */
    public function build(Category $category): array
    {
        $loadedCategory = Category::query()
            ->whereKey($category->getKey())
            ->with([
                'attributeDefs' => function ($query) {
                    $query
                        ->with([
                            'options:id,attribute_id,value,sort_order',
                            'unit:id,name,symbol,si_factor,si_offset',
                            'units:id,name,symbol,si_factor,si_offset',
                        ])
                        ->orderBy('category_attribute.filter_order');
                },
            ])
            ->first();

        if (! $loadedCategory) {
            throw new InvalidArgumentException('Категория не найдена.');
        }

        if (! $loadedCategory->isLeaf()) {
            throw new InvalidArgumentException('Шаблон доступен только для листовой категории.');
        }

        $columns = [];
        $attributes = [];

        foreach ($this->fixedColumns() as $fixedColumn) {
            $columns[] = [
                'key' => $fixedColumn,
                'label' => $this->fixedColumnLabel($fixedColumn),
                'kind' => 'fixed',
            ];
        }

        foreach ($loadedCategory->attributeDefs as $attribute) {
            $attributeSchema = $this->buildAttributeSchema($attribute, $loadedCategory);
            $attributes[] = $attributeSchema;

            $columns = array_merge($columns, $this->attributeColumns($attributeSchema));
        }

        $attributesByKey = [];
        foreach ($attributes as $attribute) {
            $attributesByKey[$attribute['attribute_key']] = $attribute;
        }

        $schemaHash = $this->calculateSchemaHash(
            categoryId: (int) $loadedCategory->getKey(),
            columns: $columns,
            attributes: $attributes,
        );

        return [
            'template_type' => self::TEMPLATE_TYPE,
            'category_id' => (int) $loadedCategory->getKey(),
            'schema_hash' => $schemaHash,
            'fixed_columns' => $this->fixedColumns(),
            'columns' => $columns,
            'attributes' => $attributes,
            'attributes_by_key' => $attributesByKey,
        ];
    }

    protected function fixedColumnLabel(string $fixedColumn): string
    {
        return match ($fixedColumn) {
            'product_id' => 'ID товара',
            'name' => 'Наименование',
            'sku' => 'Артикул',
            'updated_at' => 'Изменено',
            default => $fixedColumn,
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function attributeColumns(array $attribute): array
    {
        $label = (string) $attribute['name'];
        $displayUnit = $attribute['display_unit'] ?? null;
        $unitSymbol = is_array($displayUnit) ? ($displayUnit['symbol'] ?? null) : null;
        $unitSuffix = $unitSymbol ? ' ['.$unitSymbol.']' : '';

        $templateType = (string) $attribute['template_type'];
        $attributeKey = (string) $attribute['attribute_key'];

        if ($templateType === 'range') {
            return [
                [
                    'key' => $attributeKey.'.min',
                    'label' => $label.' от'.$unitSuffix,
                    'kind' => 'attribute',
                    'attribute_key' => $attributeKey,
                    'value_mode' => 'range_min',
                ],
                [
                    'key' => $attributeKey.'.max',
                    'label' => $label.' до'.$unitSuffix,
                    'kind' => 'attribute',
                    'attribute_key' => $attributeKey,
                    'value_mode' => 'range_max',
                ],
            ];
        }

        return [
            [
                'key' => $attributeKey,
                'label' => $templateType === 'number' ? $label.$unitSuffix : $label,
                'kind' => 'attribute',
                'attribute_key' => $attributeKey,
                'value_mode' => 'single',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildAttributeSchema(ProductAttribute $attribute, Category $category): array
    {
        $templateType = $this->resolveTemplateType($attribute);

        $displayUnit = $this->resolveDisplayUnit($attribute);
        $baseUnit = $attribute->defaultUnit();

        $attributeKey = 'attr.'.$attribute->slug;

        $options = $attribute->options
            ->sortBy('sort_order')
            ->values()
            ->map(fn ($option) => [
                'id' => (int) $option->id,
                'label' => trim((string) $option->value),
            ])
            ->all();

        return [
            'attribute_key' => $attributeKey,
            'attribute_id' => (int) $attribute->getKey(),
            'slug' => (string) $attribute->slug,
            'name' => (string) $attribute->name,
            'filter_order' => (int) ($attribute->pivot?->filter_order ?? 0),
            'template_type' => $templateType,
            'data_type' => (string) $attribute->data_type,
            'input_type' => (string) ($attribute->input_type ?? ''),
            'display_unit' => $this->unitPayload($displayUnit),
            'base_unit' => $this->unitPayload($baseUnit),
            'number_format' => in_array($templateType, ['number', 'range'], true)
                ? [
                    'decimals' => $attribute->filterNumberDecimalsForCategory($category),
                    'step' => $attribute->filterNumberStepForCategory($category),
                    'rounding' => $attribute->filterRoundingModeForCategory($category),
                ]
                : null,
            'options' => $options,
        ];
    }

    protected function resolveTemplateType(ProductAttribute $attribute): string
    {
        if ($attribute->input_type === 'select') {
            return 'select';
        }

        if ($attribute->input_type === 'multiselect') {
            return 'multiselect';
        }

        return match ($attribute->data_type) {
            'number' => 'number',
            'range' => 'range',
            'boolean' => 'boolean',
            default => 'text',
        };
    }

    protected function resolveDisplayUnit(ProductAttribute $attribute): ?Unit
    {
        $displayUnitId = $attribute->pivot?->display_unit_id;

        if ($displayUnitId) {
            $pivotUnit = $attribute->units->firstWhere('id', (int) $displayUnitId);
            if ($pivotUnit) {
                return $pivotUnit;
            }

            return Unit::query()->find((int) $displayUnitId);
        }

        return $attribute->defaultUnit();
    }

    /**
     * @return array{id:int|null,name:string|null,symbol:string|null,si_factor:float,si_offset:float}|null
     */
    protected function unitPayload(?Unit $unit): ?array
    {
        if (! $unit) {
            return null;
        }

        return [
            'id' => (int) $unit->getKey(),
            'name' => (string) $unit->name,
            'symbol' => $unit->symbol ? (string) $unit->symbol : null,
            'si_factor' => (float) ($unit->si_factor ?? 1.0),
            'si_offset' => (float) ($unit->si_offset ?? 0.0),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $columns
     * @param  array<int, array<string, mixed>>  $attributes
     */
    protected function calculateSchemaHash(int $categoryId, array $columns, array $attributes): string
    {
        $normalizedColumns = array_map(function (array $column): array {
            return [
                'key' => (string) ($column['key'] ?? ''),
                'kind' => (string) ($column['kind'] ?? ''),
                'attribute_key' => (string) ($column['attribute_key'] ?? ''),
                'value_mode' => (string) ($column['value_mode'] ?? ''),
            ];
        }, $columns);

        $normalizedAttributes = array_map(function (array $attribute): array {
            return [
                'attribute_key' => (string) ($attribute['attribute_key'] ?? ''),
                'attribute_id' => (int) ($attribute['attribute_id'] ?? 0),
                'slug' => (string) ($attribute['slug'] ?? ''),
                'template_type' => (string) ($attribute['template_type'] ?? ''),
                'data_type' => (string) ($attribute['data_type'] ?? ''),
                'input_type' => (string) ($attribute['input_type'] ?? ''),
                'display_unit' => [
                    'id' => (int) (($attribute['display_unit']['id'] ?? 0)),
                    'si_factor' => (float) (($attribute['display_unit']['si_factor'] ?? 1.0)),
                    'si_offset' => (float) (($attribute['display_unit']['si_offset'] ?? 0.0)),
                ],
                'base_unit' => [
                    'id' => (int) (($attribute['base_unit']['id'] ?? 0)),
                    'si_factor' => (float) (($attribute['base_unit']['si_factor'] ?? 1.0)),
                    'si_offset' => (float) (($attribute['base_unit']['si_offset'] ?? 0.0)),
                ],
                'number_format' => [
                    'decimals' => (int) (($attribute['number_format']['decimals'] ?? 0)),
                    'step' => (string) (($attribute['number_format']['step'] ?? '')),
                    'rounding' => (string) (($attribute['number_format']['rounding'] ?? '')),
                ],
                'options' => array_map(function (array $option): array {
                    return [
                        'id' => (int) ($option['id'] ?? 0),
                        'label' => (string) ($option['label'] ?? ''),
                    ];
                }, $attribute['options'] ?? []),
            ];
        }, $attributes);

        $payload = [
            'template_type' => self::TEMPLATE_TYPE,
            'category_id' => $categoryId,
            'columns' => $normalizedColumns,
            'attributes' => $normalizedAttributes,
        ];

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
