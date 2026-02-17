<?php

namespace App\Models;

use App\Support\FilterSchemaCache;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property int $category_id
 * @property int $attribute_id
 * @property bool $is_required
 * @property int $filter_order
 * @property int $compare_order
 * @property bool $visible_in_specs
 * @property bool $visible_in_compare
 * @property string|null $group_override
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CategoryAttribute newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CategoryAttribute newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CategoryAttribute query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CategoryAttribute whereAttributeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CategoryAttribute whereCategoryId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CategoryAttribute whereCompareOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CategoryAttribute whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CategoryAttribute whereFilterOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CategoryAttribute whereGroupOverride($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CategoryAttribute whereIsRequired($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CategoryAttribute whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CategoryAttribute whereVisibleInCompare($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CategoryAttribute whereVisibleInSpecs($value)
 *
 * @mixin \Eloquent
 */
class CategoryAttribute extends Pivot
{
    protected $table = 'category_attribute';

    public $timestamps = true; // у тебя withTimestamps() в связи — оставь true

    protected $fillable = [
        'is_required',
        'filter_order',
        'compare_order',
        'visible_in_specs',
        'visible_in_compare',
        'display_unit_id',
        'number_decimals',
        'number_step',
        'number_rounding',
    ];

    protected $casts = [
        'is_required' => 'bool',
        'visible_in_specs' => 'bool',
        'visible_in_compare' => 'bool',
        'filter_order' => 'int',
        'compare_order' => 'int',
        'display_unit_id' => 'int',
        'number_decimals' => 'int',
        'number_step' => 'float',
        'number_rounding' => 'string',
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

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class, 'attribute_id');
    }

    public function displayUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'display_unit_id');
    }

    /**
     * Какой юнит использовать для отображения в этой категории.
     * Если не выбран явно — берём defaultUnit атрибута.
     */
    public function resolveDisplayUnit(): ?Unit
    {
        if ($this->relationLoaded('displayUnit') && $this->displayUnit) {
            return $this->displayUnit;
        }

        if ($this->display_unit_id) {
            return $this->displayUnit()->first();
        }

        return $this->attribute?->defaultUnit();
    }

    private static function invalidateFilterSchemaCache(self $model): void
    {
        $categoryIds = [
            (int) $model->category_id,
            (int) ($model->getOriginal('category_id') ?? 0),
        ];

        foreach ($categoryIds as $categoryId) {
            if ($categoryId > 0) {
                FilterSchemaCache::forgetCategory($categoryId);
            }
        }
    }
}
