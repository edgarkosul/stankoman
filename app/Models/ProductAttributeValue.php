<?php

namespace App\Models;

use App\Support\FilterSchemaCache;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $product_id
 * @property int $attribute_id
 * @property string|null $value_text
 * @property float|null $value_number
 * @property float|null $value_si
 * @property float|null $value_min_si
 * @property float|null $value_max_si
 * @property float|null $value_min
 * @property float|null $value_max
 * @property bool|null $value_boolean
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Attribute $attribute
 * @property-read string|null $display_value
 * @property-read string|null $display_value_si
 * @property-read mixed $raw_value
 * @property-read \App\Models\Product $product
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductAttributeValue newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductAttributeValue newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductAttributeValue query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductAttributeValue whereAttributeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductAttributeValue whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductAttributeValue whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductAttributeValue whereProductId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductAttributeValue whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductAttributeValue whereValueBoolean($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductAttributeValue whereValueMax($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductAttributeValue whereValueMaxSi($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductAttributeValue whereValueMin($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductAttributeValue whereValueMinSi($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductAttributeValue whereValueNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductAttributeValue whereValueSi($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductAttributeValue whereValueText($value)
 *
 * @mixin \Eloquent
 */
class ProductAttributeValue extends Model
{
    protected $fillable = [
        'product_id',
        'attribute_id',
        'value_text',
        'value_boolean',
        'value_number',
        'value_min',
        'value_max',
        'value_si',
        'value_min_si',
        'value_max_si',
    ];

    protected $casts = [
        'value_boolean' => 'bool',
        'value_number' => 'float',
        'value_min' => 'float',
        'value_max' => 'float',
        'value_si' => 'float',
        'value_min_si' => 'float',
        'value_max_si' => 'float',
    ];

    protected static function booted(): void
    {
        static::saved(function (self $model): void {
            self::invalidateFilterSchemaCache($model);
        });

        static::deleted(function (self $model): void {
            self::invalidateFilterSchemaCache($model);
        });
    }

    /* -------------------- Связи -------------------- */

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function attribute()
    {
        // unit нужен для конвертации
        return $this->belongsTo(Attribute::class)->with('unit');
    }

    /* -------------------- Утилиты UI↔SI -------------------- */

    public function numberUi(): ?float
    {
        $attr = $this->attribute;
        if (! $attr) {
            return null;
        }

        // 1) Если есть «пользовательское» значение — используем его
        if ($this->value_number !== null) {
            return (float) $this->value_number;
        }

        // 2) Иначе пересчитываем из SI
        if ($this->value_si !== null) {
            return $attr->fromSi((float) $this->value_si);
        }

        return null;
    }

    public function rangeUi(): array
    {
        $attr = $this->attribute;
        if (! $attr) {
            return [null, null];
        }

        $minUi = $this->value_min !== null
            ? (float) $this->value_min
            : ($this->value_min_si !== null ? $attr->fromSi((float) $this->value_min_si) : null);

        $maxUi = $this->value_max !== null
            ? (float) $this->value_max
            : ($this->value_max_si !== null ? $attr->fromSi((float) $this->value_max_si) : null);

        return [$minUi, $maxUi];
    }

    /** Отформатировать число по правилам атрибута */
    protected function formatNumber(?float $ui): ?string
    {
        if ($ui === null) {
            return null;
        }

        $attr = $this->attribute;
        $dec = $attr->number_decimals;      // int|null
        $round = $attr->number_rounding;      // 'round'|'floor'|'ceil'|null

        $x = (float) $ui;

        // Ровно округление — без масштабирования
        if ($dec !== null && $round) {
            $m = 10 ** $dec;
            $x = match ($round) {
                'round' => round($x * $m) / $m,
                'floor' => floor($x * $m) / $m,
                'ceil' => ceil($x * $m) / $m,
                default => $x,
            };
        }

        // Фиксированное число знаков, если задано
        if ($dec !== null) {
            return number_format($x, $dec, '.', '');
        }

        // Иначе — «красиво» без хвостов
        return rtrim(rtrim(number_format($x, 6, '.', ''), '0'), '.') ?: '0';
    }

    /* -------------------- Аксессоры -------------------- */

    /**
     * Унифицированное «сырое» (неформатированное) значение в UI.
     * Возвращает:
     *  - number: float|null
     *  - range : ['min'=>float|null, 'max'=>float|null]
     *  - boolean: bool|null
     *  - text: string|null
     *  - для опций всегда null (опции в pivot, не здесь)
     */
    public function getRawValueAttribute()
    {
        $attr = $this->attribute;
        if (! $attr) {
            return null;
        }

        if ($attr->usesOptions()) {
            return null; // PAV больше не хранит опции
        }

        if ($attr->data_type === 'range') {
            [$min, $max] = $this->rangeUi();

            return ['min' => $min, 'max' => $max];
        }

        if ($attr->isNumber()) {
            return $this->numberUi();
        }

        if ($attr->isBoolean()) {
            return $this->value_boolean;
        }

        return $this->value_text;
    }

    /**
     * Человекочитаемое значение для вывода (UI + единица).
     * - range: "1.60—4.00 мм", "≥ 1.6 мм", "≤ 4 мм"
     * - number: "220 В"
     * - boolean: "Да"/"Нет"
     * - text: как есть
     */
    public function getDisplayValueAttribute(): ?string
    {
        $attr = $this->attribute;
        if (! $attr) {
            return null;
        }

        // Опции — не тут
        if ($attr->usesOptions()) {
            return null;
        }

        $unit = method_exists($attr, 'defaultUnit')
            ? $attr->defaultUnit()
            : $attr->unit;

        $unitSuffix = $unit?->symbol ? (' '.$unit->symbol) : '';

        if ($attr->data_type === 'range') {
            [$minUi, $maxUi] = $this->rangeUi();
            $min = $this->formatNumber($minUi);
            $max = $this->formatNumber($maxUi);

            if ($min !== null && $max !== null) {
                return $min.'—'.$max.$unitSuffix;
            }
            if ($min !== null) {
                return '≥ '.$min.$unitSuffix;
            }
            if ($max !== null) {
                return '≤ '.$max.$unitSuffix;
            }

            return null;
        }

        if ($attr->isNumber()) {
            $num = $this->formatNumber($this->numberUi());
            if ($num === null) {
                return null;
            }

            $valuePart = $num.$unitSuffix;
            if ($attr->display_format) {
                $symbol = $unit?->symbol ?? '';

                return str_replace(
                    ['{value}', '{unit}'],
                    [$num, $symbol],
                    $attr->display_format
                );
            }

            return $valuePart;
        }

        if ($attr->isBoolean()) {
            return $this->value_boolean === null ? null : ($this->value_boolean ? 'Да' : 'Нет');
        }

        return $this->value_text;
    }

    public function getDisplayValueSiAttribute(): ?string
    {
        $attr = $this->attribute;
        if (! $attr || $attr->usesOptions()) {
            return null;
        }

        $ui = $this->display_value;
        if ($ui === null) {
            return null;
        }

        // Для number/range добавим хвост с SI, если есть base_symbol
        $unit = method_exists($attr, 'defaultUnit')
            ? $attr->defaultUnit()
            : $attr->unit;

        $base = $unit?->base_symbol;
        if (! $base) {
            return $ui;
        }

        if ($attr->data_type === 'number' && $this->value_si !== null) {
            $siStr = rtrim(rtrim(number_format((float) $this->value_si, 6, '.', ''), '0'), '.');

            return $ui.' ('.$siStr.' '.$base.')';
        }

        if ($attr->data_type === 'range' && ($this->value_min_si !== null || $this->value_max_si !== null)) {
            $min = $this->value_min_si !== null ? rtrim(rtrim(number_format((float) $this->value_min_si, 6, '.', ''), '0'), '.') : '…';
            $max = $this->value_max_si !== null ? rtrim(rtrim(number_format((float) $this->value_max_si, 6, '.', ''), '0'), '.') : '…';

            return $ui.' ('.$min.'—'.$max.' '.$base.')';
        }

        return $ui;
    }

    /**
     * Записать значение в корректные поля по типу атрибута (UI-значения).
     * $value:
     *  - number: float|null
     *  - range : ['min'=>?, 'max'=>?]
     *  - boolean: bool|null
     *  - text: string|null
     * ВНИМАНИЕ: опции здесь НЕ поддерживаются (они в pivot product_attribute_option).
     * Пересчёт *_si выполнит observer.
     */
    public function setTypedValue(Attribute $attr, $value): void
    {
        // Сбросим всё UI (observer сам обновит *_si)
        $this->value_text = null;
        $this->value_boolean = null;
        $this->value_number = null;
        $this->value_min = null;
        $this->value_max = null;

        if ($attr->usesOptions()) {
            // опции выставляются через ProductAttributeOption и pivot
            return;
        }

        if ($attr->data_type === 'range') {
            if (is_array($value)) {
                if (array_key_exists('min', $value)) {
                    $this->value_min = $value['min'] !== null ? (float) $value['min'] : null;
                }
                if (array_key_exists('max', $value)) {
                    $this->value_max = $value['max'] !== null ? (float) $value['max'] : null;
                }
            }

            return;
        }

        if ($attr->isNumber()) {
            $this->value_number = $value !== null ? (float) $value : null;

            return;
        }

        if ($attr->isBoolean()) {
            $this->value_boolean = $value !== null ? (bool) $value : null;

            return;
        }

        $this->value_text = $value !== null ? (string) $value : null;
    }

    private static function invalidateFilterSchemaCache(self $model): void
    {
        $pairs = [
            [(int) $model->product_id, (int) $model->attribute_id],
            [(int) ($model->getOriginal('product_id') ?? 0), (int) ($model->getOriginal('attribute_id') ?? 0)],
        ];

        foreach ($pairs as [$productId, $attributeId]) {
            if ($productId > 0 && $attributeId > 0) {
                FilterSchemaCache::forgetByProductAttribute($productId, $attributeId);
            }
        }
    }
}
