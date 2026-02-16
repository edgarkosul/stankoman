<?php

namespace App\Support;

use App\DTO\Filter;
use App\Enums\FilterType;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\Unit;
use App\Support\Filters\FilterInputNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ProductFilterService
{
    /**
     * Построить схему фильтров для листовой категории.
     * Возвращает коллекцию Filter (отсортированные по filter_order).
     */
    public static function schemaForCategory(Category $category): Collection
    {
        // кэш на 10 минут, чтобы не грузить БД на каждой загрузке листинга
        return Cache::remember(
            "filters:schema:cat:{$category->getKey()}",
            now()->addMinutes(10),
            function () use ($category) {
                $attrs = $category->filterableAttributes()
                    ->with([
                        'options:id,attribute_id,value,sort_order',
                        'unit:id,symbol,si_factor,si_offset',
                    ])
                    ->orderBy('category_attribute.filter_order')
                    ->get();

                // --- display_unit_id -> Unit для этой категории ---
                $attrIds = $attrs->pluck('id')->all();

                $displayUnitsByAttrId = [];

                if (! empty($attrIds)) {
                    $pivotUnits = DB::table('category_attribute')
                        ->where('category_id', $category->getKey())
                        ->whereIn('attribute_id', $attrIds)
                        ->pluck('display_unit_id', 'attribute_id'); // [attribute_id => display_unit_id|null]

                    $unitIds = $pivotUnits
                        ->filter(fn ($id) => ! is_null($id))
                        ->unique()
                        ->values()
                        ->all();

                    $units = $unitIds
                        ? Unit::query()
                            ->whereIn('id', $unitIds)
                            ->get(['id', 'symbol', 'si_factor', 'si_offset'])
                            ->keyBy('id')
                        : collect();

                    foreach ($pivotUnits as $attrId => $unitId) {
                        $displayUnitsByAttrId[(int) $attrId] = $unitId
                            ? $units->get($unitId)
                            : null;
                    }
                }

                // --- базовые атрибуты ---
                $attrs = $attrs->map(function (Attribute $a) use ($category, $displayUnitsByAttrId) {
                    $key = $a->slug;
                    $label = $a->name;
                    $order = (int) $a->pivot->filter_order;
                    $cast = self::castFor($a);

                    // единица отображения для данной категории
                    /** @var Unit|null $displayUnit */
                    $displayUnit = $displayUnitsByAttrId[$a->getKey()] ?? $a->unit;

                    // опции (select/multiselect)
                    if ($a->usesOptions()) {
                        $opts = self::optionsForCategory($a, $category);

                        // если в категории 0 или 1 живая опция — фильтр не показываем
                        if (count($opts) <= 1) {
                            return null;
                        }

                        return new Filter(
                            key: $key,
                            label: $label,
                            type: $a->filter_ui === 'dropdown'
                                ? FilterType::SELECT
                                : FilterType::MULTISELECT,
                            meta: ['options' => $opts],
                            order: $order,
                            value_cast: $cast,
                        );
                    }

                    // булевы
                    if ($a->isBoolean()) {
                        return new Filter(
                            $key,
                            $label,
                            FilterType::BOOLEAN,
                            meta: [
                                'options' => [
                                    ['v' => '1', 'l' => 'Да'],
                                    ['v' => '0', 'l' => 'Нет'],
                                ],
                            ],
                            order: $order,
                            value_cast: $cast,
                        );
                    }

                    // числовые / диапазонные
                    if ($a->isNumber() || $a->isRange()) {
                        [$minUi, $maxUi] = self::numberRangeFor($a, $category->getKey(), $displayUnit);

                        // если вернуть нечего — скрываем фильтр
                        if ($minUi === null || $maxUi === null) {
                            return null;
                        }

                        return new Filter(
                            key: $key,
                            label: $label,
                            type: FilterType::RANGE,
                            meta: [
                                'min' => $minUi,
                                'max' => $maxUi,
                                'step' => (float) $a->filterNumberStepForCategory($category),
                                'decimals' => $a->filterNumberDecimalsForCategory($category),
                                'suffix' => (string) optional($displayUnit)->symbol,
                            ],
                            order: $order,
                            value_cast: $cast,
                        );
                    }

                    // === TEXT (фасет топ-100) ===
                    if ($a->isText()) {
                        $opts = self::facetTextOptions($a->id, $category->getKey());

                        if (count($opts) <= 1) {
                            return null;
                        }

                        return new Filter(
                            $key,
                            $label,
                            FilterType::MULTISELECT,
                            meta: ['options' => $opts],
                            order: $order,
                            value_cast: $cast,
                        );
                    }

                    return null;
                })
                    ->filter()
                    ->values();

                // Исключаем потенциальные коллизии по ключам системных фильтров.
                $attrs = $attrs
                    ->reject(fn (Filter $filter) => in_array($filter->key, ['brand', 'price', 'discount'], true))
                    ->sortBy('order')
                    ->values()
                    ->map(function (Filter $filter, int $index): Filter {
                        $filter->order = $index + 3;

                        return $filter;
                    })
                    ->values();

                // === SYSTEM: BRAND (всегда первый) ===
                $brandOpt = collect($category->getUniqueBrands()->toArray())
                    ->filter(fn ($s) => $s !== null && $s !== '')
                    ->map(fn ($s) => trim((string) $s))
                    ->filter()
                    ->unique(fn ($s) => mb_strtolower($s))
                    ->sort(SORT_NATURAL | SORT_FLAG_CASE)
                    ->values()
                    ->map(fn ($s) => ['v' => $s, 'l' => $s])
                    ->all();

                $brandFilter = new Filter(
                    'brand',
                    'Бренд',
                    FilterType::MULTISELECT,
                    meta: ['options' => $brandOpt],
                    order: 0,
                    value_cast: 'string',
                );

                // === SYSTEM: PRICE (всегда второй) ===
                $priceBounds = DB::table('products as p')
                    ->join('product_categories as pc', 'pc.product_id', '=', 'p.id')
                    ->where('pc.category_id', $category->getKey())
                    ->where('p.is_active', true)
                    ->whereNotNull('p.price_amount')
                    ->where('p.price_amount', '>', 0)
                    ->selectRaw('MIN(p.price_amount) as min_price, MAX(p.price_amount) as max_price')
                    ->first();

                $minPrice = (int) ($priceBounds->min_price ?? 0);
                $maxPrice = (int) ($priceBounds->max_price ?? $minPrice);
                if ($maxPrice < $minPrice) {
                    [$minPrice, $maxPrice] = [$maxPrice, $minPrice];
                }

                if ($maxPrice <= $minPrice) {
                    $maxPrice = $minPrice + 1;
                }

                $range = $maxPrice - $minPrice;
                $roughStep = max(1, (int) floor($range / 100));

                $priceStep = $roughStep >= 100
                    ? ((int) round($roughStep / 100) * 100)
                    : ($roughStep >= 10
                        ? ((int) round($roughStep / 10) * 10)
                        : $roughStep);

                $priceFilter = new Filter(
                    'price',
                    'Цена',
                    FilterType::RANGE,
                    meta: [
                        'min' => $minPrice,
                        'max' => $maxPrice,
                        'step' => max(1, $priceStep),
                        'decimals' => 0,
                        'suffix' => '₽',
                    ],
                    order: 1,
                    value_cast: 'float',
                );

                // === SYSTEM: DISCOUNT (всегда третий) ===
                $discountFilter = new Filter(
                    key: 'discount',
                    label: 'Со скидкой',
                    type: FilterType::BOOLEAN,
                    meta: [
                        'options' => [
                            ['v' => '1', 'l' => 'Да'],
                            ['v' => '0', 'l' => 'Нет'],
                        ],
                    ],
                    order: 2,
                    value_cast: 'bool',
                );

                return collect([$brandFilter, $priceFilter, $discountFilter])
                    ->concat($attrs)
                    ->values();
            }
        );
    }

    /**
     * Вернуть список опций атрибута, реально встречающихся у продуктов в категории.
     * Отсортировано по частоте встречаемости (DESC).
     *
     * @return array<int, array{v:string,l:string}>
     */
    protected static function optionsForCategory(Attribute $attribute, Category $category): array
    {
        // Берём ТОЛЬКО реально встречающиеся в этой категории опции
        // Считаем частоту по количеству товаров (на случай дублей — DISTINCT)
        $rows = DB::table('product_attribute_option as pao')
            ->join('product_categories as pc', 'pc.product_id', '=', 'pao.product_id')
            ->join('attribute_options as ao', 'ao.id', '=', 'pao.attribute_option_id')
            ->where('pc.category_id', $category->getKey())
            ->where('pao.attribute_id', $attribute->getKey())
            ->groupBy('ao.id', 'ao.value', 'ao.sort_order')
            ->selectRaw('ao.id, ao.value, ao.sort_order, COUNT(DISTINCT pao.product_id) as cnt')
            // Приоритет: заданный ручной порядок → по возрастанию sort_order
            // Затем частота → по убыванию
            // Затем значение для детерминизма
            ->orderByRaw('CASE WHEN ao.sort_order IS NULL OR ao.sort_order = 0 THEN 1 ELSE 0 END')
            ->orderBy('ao.sort_order')
            ->orderByDesc('cnt')
            ->orderBy('ao.value')
            ->get();

        return $rows
            ->map(fn ($r) => ['v' => (string) $r->id, 'l' => $r->value])
            ->all();
    }

    public static function apply(Builder $query, array $selected, ?Category $category = null): Builder
    {

        $productsTable = $query->getModel()->getTable();
        // Подтягиваем мета по атрибутам: id, data_type, value_source/filter_ui + legacy input_type + unit
        $keys = array_keys($selected);
        $attrs = Attribute::query()
            ->whereIn('slug', $keys)
            ->with('unit:id,symbol,si_factor,si_offset')
            ->get(['id', 'slug', 'data_type', 'value_source', 'filter_ui', 'input_type', 'unit_id'])
            ->keyBy('slug');

        // Для конкретной категории — карта attribute_id => Unit|null
        $displayUnitsByAttrId = [];

        if ($category && $attrs->isNotEmpty()) {
            $attrIds = $attrs->pluck('id')->all();

            $pivotUnits = DB::table('category_attribute')
                ->where('category_id', $category->getKey())
                ->whereIn('attribute_id', $attrIds)
                ->pluck('display_unit_id', 'attribute_id'); // [attribute_id => unit_id|null]

            $unitIds = $pivotUnits
                ->filter(fn ($id) => ! is_null($id))
                ->unique()
                ->values()
                ->all();

            $units = $unitIds
                ? Unit::query()
                    ->whereIn('id', $unitIds)
                    ->get(['id', 'symbol', 'si_factor', 'si_offset'])
                    ->keyBy('id')
                : collect();

            foreach ($pivotUnits as $attrId => $unitId) {
                $displayUnitsByAttrId[(int) $attrId] = $unitId
                    ? $units->get($unitId)
                    : null;
            }
        }

        foreach ($selected as $key => $payload) {
            $input = FilterInputNormalizer::normalize($key, $payload);
            /** @var \App\Models\Attribute|null $attr */
            $attr = $attrs->get($key);
            $type = $input->type;

            // === SYSTEM: BRAND (products.brand) ===
            if (! $attr && $key === 'brand') {
                if ($input->hasValues()) {
                    $query->whereIn($productsTable.'.brand', $input->values);
                }

                continue;
            }

            // === SYSTEM: PRICE (products.price_amount) ===
            if (! $attr && $key === 'price' && $type === FilterType::RANGE) {
                if ($input->min !== null) {
                    $query->where($productsTable.'.price_amount', '>=', (float) $input->min);
                }
                if ($input->max !== null) {
                    $query->where($productsTable.'.price_amount', '<=', (float) $input->max);
                }

                continue;
            }

            // === SYSTEM: DISCOUNT (products.discount_price < products.price_amount) ===
            if (! $attr && $key === 'discount' && $type === FilterType::BOOLEAN) {
                if ($input->bool !== true) {
                    continue;
                }

                $query->where(function ($q) use ($productsTable) {
                    $q->whereNotNull($productsTable.'.discount_price')
                        ->where($productsTable.'.discount_price', '>', 0)
                        ->whereColumn($productsTable.'.discount_price', '<', $productsTable.'.price_amount');
                });

                continue;
            }

            // === Дальше — логика по атрибутам ===
            if (! $attr) {
                continue;
            }

            $attrId = $attr->id;
            /** @var Unit|null $displayUnit */
            $displayUnit = $displayUnitsByAttrId[$attrId] ?? $attr->unit;

            switch ($type) {
                case FilterType::SELECT:
                case FilterType::MULTISELECT:
                    // Опционный атрибут?
                    $isOptions = $attr->usesOptions();

                    if ($isOptions) {
                        // === Ветка опций по ID ===
                        $values = array_values(array_unique(array_filter(
                            array_map('intval', $input->values)
                        )));

                        if (! $values) {
                            break;
                        }

                        $query->whereExists(function ($sub) use ($productsTable, $attrId, $values) {
                            $sub->select(DB::raw(1))
                                ->from('product_attribute_option as pao')
                                ->join('attribute_options as ao', 'ao.id', '=', 'pao.attribute_option_id')
                                ->whereColumn('pao.product_id', $productsTable.'.id')
                                ->where('ao.attribute_id', $attrId)
                                ->whereIn('ao.id', $values);
                        });
                    } else {
                        // === Текстовый фасет (data_type='text'), матч по value_text ===
                        $texts = array_values(array_filter(
                            array_map('strval', $input->values),
                            static fn ($v) => $v !== ''
                        ));

                        if (! $texts) {
                            break;
                        }

                        $query->whereExists(function ($sub) use ($productsTable, $attrId, $texts) {
                            $sub->select(DB::raw(1))
                                ->from('product_attribute_values as pav')
                                ->whereColumn('pav.product_id', $productsTable.'.id')
                                ->where('pav.attribute_id', $attrId)
                                ->whereIn('pav.value_text', $texts);
                        });
                    }

                    break;

                case FilterType::RANGE:
                    if ($input->min === null && $input->max === null) {
                        break;
                    }

                    // 2) UI -> SI через единицу отображения категории
                    $minSi = $input->min !== null
                        ? $attr->toSiWithUnit((float) $input->min, $displayUnit)
                        : null;

                    $maxSi = $input->max !== null
                        ? $attr->toSiWithUnit((float) $input->max, $displayUnit)
                        : null;

                    // 3) Fallback-выражения для старых value_* (в "базовой" единице атрибута)
                    $baseUnit = $attr->unit;
                    $factor = (float) ($baseUnit?->si_factor ?? 1.0);
                    $offset = (float) ($baseUnit?->si_offset ?? 0.0);

                    if ($attr->isRange()) {
                        $rightExpr = 'COALESCE(
                    pav.value_max_si,
                    pav.value_si,
                    pav.value_min_si,
                    pav.value_max * ? + ?,
                    pav.value_number * ? + ?,
                    pav.value_min * ? + ?
                )';

                        $leftExpr = 'COALESCE(
                    pav.value_min_si,
                    pav.value_si,
                    pav.value_max_si,
                    pav.value_min * ? + ?,
                    pav.value_number * ? + ?,
                    pav.value_max * ? + ?
                )';

                        $bindRight = [$factor, $offset, $factor, $offset, $factor, $offset];
                        $bindLeft = [$factor, $offset, $factor, $offset, $factor, $offset];
                    } else {
                        $rightExpr = 'COALESCE(
                    pav.value_si,
                    pav.value_max_si,
                    pav.value_min_si,
                    pav.value_number * ? + ?,
                    pav.value_max * ? + ?,
                    pav.value_min * ? + ?
                )';

                        $leftExpr = 'COALESCE(
                    pav.value_si,
                    pav.value_min_si,
                    pav.value_max_si,
                    pav.value_number * ? + ?,
                    pav.value_min * ? + ?,
                    pav.value_max * ? + ?
                )';

                        $bindRight = [$factor, $offset, $factor, $offset, $factor, $offset];
                        $bindLeft = [$factor, $offset, $factor, $offset, $factor, $offset];
                    }

                    $query->whereExists(function ($sub) use (
                        $productsTable,
                        $attrId,
                        $minSi,
                        $maxSi,
                        $rightExpr,
                        $leftExpr,
                        $bindRight,
                        $bindLeft
                    ) {
                        $sub->select(DB::raw(1))
                            ->from('product_attribute_values as pav')
                            ->whereColumn('pav.product_id', $productsTable.'.id')
                            ->where('pav.attribute_id', $attrId);

                        if ($minSi !== null) {
                            $sub->whereRaw("$rightExpr >= ?", array_merge($bindRight, [$minSi]));
                        }
                        if ($maxSi !== null) {
                            $sub->whereRaw("$leftExpr <= ?", array_merge($bindLeft, [$maxSi]));
                        }
                    });

                    break;

                case FilterType::BOOLEAN:
                    if ($input->hasBoolValue) {
                        $wantTrue = (bool) $input->bool;

                        if ($wantTrue) {
                            $query->whereExists(function ($sub) use ($productsTable, $attrId) {
                                $sub->select(DB::raw(1))
                                    ->from('product_attribute_values as pav')
                                    ->whereColumn('pav.product_id', $productsTable.'.id')
                                    ->where('pav.attribute_id', $attrId)
                                    ->where('pav.value_boolean', 1);
                            });
                        } else {
                            $query->whereNotExists(function ($sub) use ($productsTable, $attrId) {
                                $sub->select(DB::raw(1))
                                    ->from('product_attribute_values as pav')
                                    ->whereColumn('pav.product_id', $productsTable.'.id')
                                    ->where('pav.attribute_id', $attrId)
                                    ->where('pav.value_boolean', 1);
                            });
                        }
                    }

                    break;

                case FilterType::TEXT:
                case FilterType::MULTITEXT:
                    $texts = array_values(array_filter(
                        array_map('strval', $input->values),
                        static fn ($v) => $v !== ''
                    ));

                    if ($texts) {
                        $query->whereExists(function ($sub) use ($productsTable, $attrId, $texts) {
                            $sub->select(DB::raw(1))
                                ->from('product_attribute_values as pav')
                                ->whereColumn('pav.product_id', $productsTable.'.id')
                                ->where('pav.attribute_id', $attrId)
                                ->whereIn('pav.value_text', $texts);
                        });
                    }

                    break;

            }
        }

        return $query;
    }

    /**
     * Мин/макс диапазон для числового атрибута в юните конкретной категории.
     *
     * @return array{0: float|null, 1: float|null}
     */
    protected static function numberRangeFor(Attribute $attribute, int $categoryId, ?Unit $displayUnit = null): array
    {
        // базовая (историческая) единица атрибута —
        // в ней хранились value_min/value_number, из которых мы при бэкофилле считали *_si
        $attribute->loadMissing('unit:id,si_factor,si_offset');
        $baseUnit = $attribute->unit;

        $factor = (float) ($baseUnit?->si_factor ?? 1.0);
        $offset = (float) ($baseUnit?->si_offset ?? 0.0);

        $isRange = $attribute->isRange();
        $attributeId = $attribute->getKey();

        if ($isRange) {
            $minExpr = 'COALESCE(
                pav.value_min_si,
                pav.value_si,
                pav.value_max_si,
                pav.value_min * ? + ?,
                pav.value_number * ? + ?,
                pav.value_max * ? + ?
            )';

            $maxExpr = 'COALESCE(
                pav.value_max_si,
                pav.value_si,
                pav.value_min_si,
                pav.value_max * ? + ?,
                pav.value_number * ? + ?,
                pav.value_min * ? + ?
            )';

            $bindMin = [$factor, $offset, $factor, $offset, $factor, $offset];
            $bindMax = [$factor, $offset, $factor, $offset, $factor, $offset];
        } else {
            $minExpr = 'COALESCE(
                pav.value_si,
                pav.value_min_si,
                pav.value_max_si,
                pav.value_number * ? + ?,
                pav.value_min * ? + ?,
                pav.value_max * ? + ?
            )';

            $maxExpr = 'COALESCE(
                pav.value_si,
                pav.value_max_si,
                pav.value_min_si,
                pav.value_number * ? + ?,
                pav.value_max * ? + ?,
                pav.value_min * ? + ?
            )';

            $bindMin = [$factor, $offset, $factor, $offset, $factor, $offset];
            $bindMax = [$factor, $offset, $factor, $offset, $factor, $offset];
        }

        $row = DB::table('product_attribute_values as pav')
            ->join('product_categories as pc', 'pc.product_id', '=', 'pav.product_id')
            ->where('pc.category_id', $categoryId)
            ->where('pav.attribute_id', $attributeId)
            // хотя бы что-то должно быть задано в любой из "числовых" колонок
            ->where(function ($q) {
                $q->whereNotNull('pav.value_min_si')
                    ->orWhereNotNull('pav.value_max_si')
                    ->orWhereNotNull('pav.value_si')
                    ->orWhereNotNull('pav.value_min')
                    ->orWhereNotNull('pav.value_max')
                    ->orWhereNotNull('pav.value_number');
            })
            ->selectRaw(
                "MIN($minExpr) as mn_si, MAX($maxExpr) as mx_si",
                array_merge($bindMin, $bindMax)
            )
            ->first();

        if (! $row || ($row->mn_si === null && $row->mx_si === null)) {
            return [null, null];
        }

        // Конвертируем SI → UI в юнит категории
        $unitForUi = $displayUnit ?: $baseUnit;

        $minUi = $row->mn_si !== null
            ? $attribute->fromSiWithUnit((float) $row->mn_si, $unitForUi)
            : null;

        $maxUi = $row->mx_si !== null
            ? $attribute->fromSiWithUnit((float) $row->mx_si, $unitForUi)
            : null;

        if ($minUi !== null) {
            $minUi = $attribute->quantize($minUi);
        }
        if ($maxUi !== null) {
            $maxUi = $attribute->quantize($maxUi);
        }

        // защита от бесполезного фильтра
        if ($minUi === null || $maxUi === null || $minUi === $maxUi) {
            return [null, null];
        }

        return [$minUi, $maxUi];
    }

    /** Фасет для текстовых значений (топ-100 значений) */
    protected static function facetTextOptions(int $attributeId, int $categoryId): array
    {
        $rows = DB::table('product_attribute_values as pav')
            ->join('product_categories as pc', 'pc.product_id', '=', 'pav.product_id')
            ->where('pc.category_id', $categoryId)
            ->where('pav.attribute_id', $attributeId)
            ->whereNotNull('pav.value_text')
            ->select('pav.value_text as v')
            ->groupBy('pav.value_text')
            ->orderByRaw('COUNT(*) DESC')
            ->limit(100)
            ->get();

        return $rows->map(fn ($r) => ['v' => (string) $r->v, 'l' => (string) $r->v])->all();
    }

    public function invalidateSchemasForAttribute(int $attributeId): void
    {
        // Найдём ID категорий, где этот атрибут привязан
        $categoryIds = DB::table('category_attribute')
            ->where('attribute_id', $attributeId)
            ->pluck('category_id')
            ->all();

        if (empty($categoryIds)) {
            return;
        }

        // Очистим кэш для каждой категории
        foreach ($categoryIds as $catId) {
            $key = "filters:schema:cat:{$catId}";
            Cache::forget($key);
        }
    }

    private static function castFor(Attribute $a): string
    {
        return match (true) {
            $a->usesOptions() => 'int',
            $a->isText() => 'string',
            $a->isBoolean() => 'bool',
            $a->isNumber(),
            $a->isRange() => 'float',
            default => 'string',
        };
    }
}
