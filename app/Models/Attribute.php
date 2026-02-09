<?php

namespace App\Models;

use App\Models\Unit;
use App\Models\Category;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use App\Models\AttributeProductLink;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Casts\Attribute as EloquentAttribute;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $data_type
 * @property string|null $input_type
 * @property int|null $unit_id
 * @property bool $is_filterable
 * @property bool $is_comparable
 * @property string|null $group
 * @property string|null $display_format
 * @property int $sort_order
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property int|null $number_decimals
 * @property float|null $number_step
 * @property string|null $number_rounding
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Category> $categories
 * @property-read int|null $categories_count
 * @property-read string $filter_ui
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\AttributeOption> $options
 * @property-read int|null $options_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ProductAttributeOption> $paoLinks
 * @property-read int|null $pao_links_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ProductAttributeValue> $pavValues
 * @property-read int|null $pav_values_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Product> $productsViaOptions
 * @property-read int|null $products_via_options_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Product> $productsViaValues
 * @property-read int|null $products_via_values_count
 * @property-read \App\Models\Unit|null $unit
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Attribute newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Attribute newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Attribute query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Attribute whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Attribute whereDataType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Attribute whereDisplayFormat($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Attribute whereGroup($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Attribute whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Attribute whereInputType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Attribute whereIsComparable($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Attribute whereIsFilterable($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Attribute whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Attribute whereNumberDecimals($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Attribute whereNumberRounding($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Attribute whereNumberStep($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Attribute whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Attribute whereSortOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Attribute whereUnitId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Attribute whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class Attribute extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'data_type',
        'input_type',
        'unit_id',
        'dimension',
        'is_filterable',
        'is_comparable',
        'group',
        'display_format',
        'sort_order',
        'number_decimals',
        'number_step',
        'number_rounding',
    ];

    protected $casts = [
        'is_filterable'   => 'bool',
        'is_comparable'   => 'bool',
        'sort_order'      => 'int',
        'number_decimals' => 'int',
        'number_step'     => 'float',   // важно: использовать как число
    ];

    // Типы
    public function isText(): bool
    {
        return $this->data_type === 'text';
    }
    public function isNumber(): bool
    {
        return $this->data_type === 'number';
    }
    public function isBoolean(): bool
    {
        return $this->data_type === 'boolean';
    }
    public function usesOptions(): bool
    {
        return in_array($this->input_type, ['select', 'multiselect'], true);
    }

    public function isRange(): bool
    {
        return $this->data_type === 'range';
    }

    // Справочники
    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function units(): BelongsToMany
    {
        return $this->belongsToMany(Unit::class, 'attribute_unit')
            ->withPivot(['is_default', 'sort_order'])
            ->withTimestamps();
    }

    public function options(): HasMany
    {
        return $this->hasMany(AttributeOption::class)->orderBy('sort_order');
    }

    /**
     * Юнит по умолчанию из pivot.
     * При отсутствии pivot — фоллбек на legacy $this->unit.
     */
    // public function defaultUnit(): ?Unit
    // {
    //     // 1. На переходном этапе главным считаем legacy unit_id
    //     if ($this->relationLoaded('unit') || $this->unit_id !== null) {
    //         if ($this->unit) {
    //             return $this->unit;
    //         }
    //     }

    //     // 2. Если unit_id нет — пробуем взять из уже загруженной pivot-связи
    //     if ($this->relationLoaded('units')) {
    //         $unit = $this->units->firstWhere('pivot.is_default', true)
    //             ?? $this->units->first();

    //         if ($unit) {
    //             return $unit;
    //         }
    //     }

    //     // 3. Иначе пробуем запросом из attribute_unit
    //     $unit = $this->units()
    //         ->wherePivot('is_default', true)
    //         ->orderByPivot('sort_order')
    //         ->orderBy('name')
    //         ->first();

    //     return $unit ?: null;
    // }

    public function defaultUnit(): ?Unit
    {
        return $this->unit;
    }


    /** PAV: значения-строки у товаров (число/диапазон/текст/булево) */
    public function pavValues(): HasMany
    {
        // было productValues() — лучше явно указать назначение
        return $this->hasMany(ProductAttributeValue::class);
    }

    /** PAO: связки опций у товаров (select/multiselect) */
    public function paoLinks(): HasMany
    {
        return $this->hasMany(ProductAttributeOption::class);
    }

    /** Товары, у которых есть опции этого атрибута (через pivot) */
    public function productsViaOptions(): BelongsToMany
    {
        return $this->belongsToMany(
            Product::class,
            'product_attribute_option',
            'attribute_id', // FK в pivot на этот атрибут
            'product_id'    // FK в pivot на товар
        )->withPivot('attribute_option_id')->withTimestamps();
    }

    /** Товары, у которых есть значения PAV этого атрибута */
    public function productsViaValues(): BelongsToMany
    {
        return $this->belongsToMany(
            Product::class,
            'product_attribute_values',
            'attribute_id',
            'product_id'
        )->withTimestamps();
    }

    public function productLinks(): HasMany
    {
        return $this->hasMany(AttributeProductLink::class, 'attribute_id');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_attribute')
            ->withPivot([
                'is_required',
                'filter_order',
                'compare_order',
                'visible_in_specs',
                'visible_in_compare',
                'group_override',
            ])
            ->withTimestamps();
    }

    public function getFilterUiAttribute(): string
    {
        if ($this->input_type === 'select') return 'select';
        if ($this->input_type === 'multiselect') return 'multiselect';

        return match ($this->data_type) {
            'number'  => 'number',
            'range'   => 'range',
            'boolean' => 'boolean',
            default   => 'text',
        };
    }

    // ----- Формат и конвертация -----

    public function numberDecimals(): int
    {
        return $this->number_decimals ?? 2;
    }

    public function numberStep(): string
    {
        // Если шаг явно задан — используем его
        if ($this->number_step !== null) {
            $v = (float) $this->number_step;

            // step <= 0 трактуем как "нет явного шага" — уйдём в ветку по decimals
            if ($v > 0) {
                // Гарантированно десятичная строка (без экспоненты)
                // Подбери точность под свою БД (обычно 10–12 хватает)
                $str = sprintf('%.12F', $v);
                // Срежем только хвостовые нули ПОСЛЕ точки и саму точку, если нечего оставлять
                $str = rtrim(rtrim($str, '0'), '.');

                return $str === '' ? '0' : $str; // на всякий случай
            }
        }

        // Иначе вычисляем шаг из decimals
        $dec = $this->numberDecimals();
        return $dec <= 0
            ? '1'
            : '0.' . str_repeat('0', $dec - 1) . '1';
    }

    public function roundingMode(): string
    {
        return $this->number_rounding ?? 'round';
    }

    public function factor(): float
    {
        $unit = $this->defaultUnit();
        return (float)($unit?->si_factor ?? 1.0);
    }

    public function offset(): float
    {
        $unit = $this->defaultUnit();
        return (float)($unit?->si_offset ?? 0.0);
    }

    /** UI -> SI */
    public function toSi(?float $ui): ?float
    {
        if ($ui === null) return null;
        return $ui * $this->factor() + $this->offset();
    }

    /** SI -> UI */
    public function fromSi(?float $si): ?float
    {
        if ($si === null) return null;
        $f = max($this->factor(), 1e-20);
        return ($si - $this->offset()) / $f;
    }

    /**
     * UI -> SI с учётом конкретной единицы измерения.
     * Если $unit = null — используем стандартный $this->unit.
     */
    public function toSiWithUnit(?float $ui, ?Unit $unit = null): ?float
    {
        if ($ui === null) {
            return null;
        }

        $unit ??= $this->defaultUnit();

        if (! $unit) {
            // Fallback на старое поведение
            return $this->toSi($ui);
        }

        $factor = (float) ($unit->si_factor ?? 1.0);
        $offset = (float) ($unit->si_offset ?? 0.0);

        return $ui * $factor + $offset;
    }

    /**
     * SI -> UI с учётом конкретной единицы измерения.
     * Если $unit = null — используем стандартный $this->unit.
     */
    public function fromSiWithUnit(?float $si, ?Unit $unit = null): ?float
    {
        if ($si === null) {
            return null;
        }

        $unit ??= $this->defaultUnit();

        if (! $unit) {
            // Fallback на старое поведение
            return $this->fromSi($si);
        }

        $factor = (float) ($unit->si_factor ?? 1.0) ?: 1e-20;
        $offset = (float) ($unit->si_offset ?? 0.0);

        return ($si - $offset) / $factor;
    }

    // ----- Удобные методы для товара -----

    /**
     * Округление значения согласно настройкам атрибута:
     * - number_decimals — сколько знаков после запятой;
     * - number_rounding — стратегия ('round', 'floor', 'ceil', 'truncate' и т.п.).
     */
    public function quantize(float $value): float
    {
        $dec   = $this->numberDecimals();
        if ($dec < 0) {
            $dec = 0;
        }

        $mode  = $this->roundingMode();
        $factor = 10 ** $dec;

        switch ($mode) {
            case 'floor':
                // всегда вниз
                return floor($value * $factor) / $factor;

            case 'ceil':
                // всегда вверх
                return ceil($value * $factor) / $factor;

            case 'truncate':
            case 'trunc':
                // просто отбрасываем хвост
                return ($value >= 0
                    ? floor($value * $factor)
                    : ceil($value * $factor)
                ) / $factor;

            case 'round':
            default:
                // обычное округление
                return round($value, $dec);
        }
    }

    /**
     * Получить «человеческие» значения этого атрибута для конкретного товара.
     * Возвращает:
     * - для select/multiselect: массив подписей опций;
     * - для PAV: массив чисел/диапазонов/текста/булево.
     */
    public function valuesForProduct(Product $product): array
    {
        if ($this->usesOptions()) {
            return $product->attributeOptions()
                ->where('attribute_options.attribute_id', $this->getKey())
                ->orderBy('attribute_options.sort_order')
                ->pluck('attribute_options.value')
                ->all();
        }

        return $product->attributeValues()
            ->where('attribute_id', $this->getKey())
            ->get(['value_text', 'value_number', 'value_min', 'value_max', 'value_boolean'])
            ->map(function ($r) {
                if ($r->value_min !== null || $r->value_max !== null) {
                    return ['min' => $r->value_min, 'max' => $r->value_max];
                }
                if ($r->value_number !== null)  return $r->value_number;
                if ($r->value_text !== null)    return $r->value_text;
                if ($r->value_boolean !== null) return (bool) $r->value_boolean;
                return null;
            })->filter()->values()->all();
    }

    protected static function booted(): void
    {
        static::creating(function (self $m) {
            // если slug не задан — генерим
            if (blank($m->slug)) {
                $m->slug = static::makeUniqueSlug($m->name);
            }
        });

        static::updating(function (self $m) {
            // slug считаем стабильным ключом — не трогаем при апдейтах;
            // если очень нужно автообновление по name, снимай этот гард и генери заново.
            if ($m->isDirty('slug')) {
                // запретим ручное изменение (на всякий случай)
                $m->slug = $m->getOriginal('slug');
            }

            // Доп. защита: если вдруг slug пуст — восстановим
            if (blank($m->slug)) {
                $m->slug = static::makeUniqueSlug($m->name);
            }
        });
    }

    protected static function makeUniqueSlug(?string $name): string
    {
        $base = Str::slug((string) $name);
        if ($base === '') {
            $base = 'attr';
        }

        $slug = $base;
        $i = 2;

        while (static::query()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }

    protected function name(): EloquentAttribute
    {
        return EloquentAttribute::make(
            get: function ($value) {
                if (!is_string($value) || $value === '') {
                    return $value;
                }

                // Если есть кириллица — приводим к "Ход штока"
                if (preg_match('/\p{Cyrillic}/u', $value)) {
                    $lower = Str::lower($value);      // "ход штока"
                    return Str::ucfirst($lower);      // "Ход штока"
                }

                // Для латиницы/тех. обозначений (IP65, LED и т.п.) не трогаем
                return $value;
            },
        );
    }

    /**
     * Синхронизировать pivot attribute_unit на основе массива unit ID из формы.
     *
     * @param  array<int,string|int>|null  $unitIds
     */
    public function syncUnitsFromIds(?array $unitIds): void
    {
        $unitIds = collect($unitIds ?? [])
            ->filter()             // убрать null/пустые
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values();

        // unit_id считается "дефолтным" и обязан быть среди доступных юнитов
        if ($this->unit_id && ! $unitIds->contains((int) $this->unit_id)) {
            $unitIds->prepend((int) $this->unit_id);
        }

        // Если вообще нет юнитов — просто детачим всё
        if ($unitIds->isEmpty()) {
            $this->units()->detach();
            return;
        }

        // Подтягиваем реальные юниты, по возможности фильтруя по dimension
        $units = Unit::query()
            ->whereIn('id', $unitIds->all())
            ->when($this->dimension, fn($q) => $q->where('dimension', $this->dimension))
            ->get()
            ->keyBy('id');

        $pivotData = [];
        $sort      = 0;

        foreach ($unitIds as $id) {
            if (! isset($units[$id])) {
                continue; // защитимся от неправильных id / чужих dimension
            }

            $pivotData[$id] = [
                'is_default' => ($id === (int) $this->unit_id),
                'sort_order' => $sort++,
            ];
        }

        // Синхронизация pivot
        $this->units()->sync($pivotData);
    }

    public function uiUnitForCategory(?Category $category = null): ?Unit
    {
        // Без категории — просто глобальный unit
        if (! $category) {
            return $this->defaultUnit();
        }

        static $cache = [];

        $key = $category->getKey() . ':' . $this->getKey();

        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        // 1) Если на категории уже загружены attributeDefs — используем их, без лишних запросов
        if ($category->relationLoaded('attributeDefs')) {
            $catAttr = $category->attributeDefs->firstWhere('id', $this->getKey());

            if ($catAttr && $catAttr->pivot && ! empty($catAttr->pivot->display_unit_id)) {
                // Если на pivote есть relation displayUnit() — можно использовать его
                if (
                    method_exists($catAttr->pivot, 'displayUnit') &&
                    $catAttr->pivot->relationLoaded('displayUnit')
                ) {
                    return $cache[$key] = $catAttr->pivot->displayUnit;
                }

                // Иначе просто достаём Unit по id
                if ($unit = Unit::find($catAttr->pivot->display_unit_id)) {
                    return $cache[$key] = $unit;
                }
            }
        }

        // 2) Фоллбек — один запрос в pivot таблицу
        $displayUnitId = DB::table('category_attribute')
            ->where('category_id', $category->getKey())
            ->where('attribute_id', $this->getKey())
            ->value('display_unit_id'); // имя колонки подправь, если оно другое

        if ($displayUnitId) {
            if ($unit = Unit::find($displayUnitId)) {
                return $cache[$key] = $unit;
            }
        }

        // 3) По умолчанию — глобальная unit
        return $cache[$key] = $this->defaultUnit();
    }

    /**
     * Вернуть настройки формата числа (decimals/step/rounding) с учётом категории.
     *
     * @return array{decimals:int, step:string, rounding:string}
     */
    protected function resolveNumberFormatForCategory(?Category $category = null): array
    {
        // Базовые (глобальные) настройки атрибута
        $base = [
            'decimals' => $this->numberDecimals(),
            'step'     => $this->numberStep(),
            'rounding' => $this->roundingMode(),
        ];

        if (! $category) {
            return $base;
        }

        static $cache = [];

        $catId  = $category->getKey();
        $attrId = $this->getKey();

        if (! isset($cache[$catId])) {
            $rows = DB::table('category_attribute')
                ->where('category_id', $catId)
                ->select('attribute_id', 'number_decimals', 'number_step', 'number_rounding')
                ->get();

            $cache[$catId] = $rows
                ->keyBy('attribute_id')
                ->map(function ($row) {
                    return [
                        'decimals' => $row->number_decimals === null ? null : (int) $row->number_decimals,
                        'step'     => $row->number_step,
                        'rounding' => $row->number_rounding,
                    ];
                })
                ->all();
        }

        $cfg = $cache[$catId][$attrId] ?? null;

        if (! $cfg) {
            return $base;
        }

        $dec = $cfg['decimals'] ?? $base['decimals'];

        // Шаг: явный > 0 → используем его, иначе вычисляем из decimals
        if ($cfg['step'] !== null) {
            $v = (float) $cfg['step'];

            if ($v > 0) {
                $str = sprintf('%.12F', $v);
                $str = rtrim(rtrim($str, '0'), '.');
                $step = $str === '' ? '0' : $str;
            } else {
                // step <= 0 — трактуем как "нет явного шага"
                $step = $dec <= 0
                    ? '1'
                    : '0.' . str_repeat('0', $dec - 1) . '1';
            }
        } else {
            // Если decimals переопределены — шаг считаем из них; иначе оставляем глобальный
            if ($cfg['decimals'] !== null && $cfg['decimals'] !== $base['decimals']) {
                $step = $dec <= 0
                    ? '1'
                    : '0.' . str_repeat('0', $dec - 1) . '1';
            } else {
                $step = $base['step'];
            }
        }

        $rounding = $cfg['rounding'] ?? $base['rounding'];

        return [
            'decimals' => $dec,
            'step'     => $step,
            'rounding' => $rounding,
        ];
    }

    public function filterNumberDecimalsForCategory(?Category $category = null): int
    {
        return $this->resolveNumberFormatForCategory($category)['decimals'];
    }

    public function filterNumberStepForCategory(?Category $category = null): string
    {
        return $this->resolveNumberFormatForCategory($category)['step'];
    }

    public function filterRoundingModeForCategory(?Category $category = null): string
    {
        return $this->resolveNumberFormatForCategory($category)['rounding'];
    }

    public function quantizeForCategory(float $value, ?Category $category = null): float
    {
        $cfg = $this->resolveNumberFormatForCategory($category);

        $dec = $cfg['decimals'];
        if ($dec < 0) {
            $dec = 0;
        }

        $mode   = $cfg['rounding'];
        $factor = 10 ** $dec;

        switch ($mode) {
            case 'floor':
                return floor($value * $factor) / $factor;

            case 'ceil':
                return ceil($value * $factor) / $factor;

            case 'truncate':
            case 'trunc':
                return ($value >= 0
                    ? floor($value * $factor)
                    : ceil($value * $factor)
                ) / $factor;

            case 'round':
            default:
                return round($value, $dec);
        }
    }
}
