<?php

namespace App\Models;

use App\Support\FilterSchemaCache;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $attribute_id
 * @property string $value
 * @property int $sort_order
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Attribute $attribute
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeOption newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeOption newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeOption query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeOption whereAttributeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeOption whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeOption whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeOption whereSortOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeOption whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeOption whereValue($value)
 *
 * @mixin \Eloquent
 */
class AttributeOption extends Model
{
    protected $fillable = ['attribute_id', 'value', 'sort_order'];

    protected static function booted(): void
    {
        static::saved(function (self $model): void {
            self::invalidateFilterSchemaCache($model);
        });

        static::deleted(function (self $model): void {
            self::invalidateFilterSchemaCache($model);
        });
    }

    public function attribute()
    {
        return $this->belongsTo(Attribute::class);
    }

    private static function invalidateFilterSchemaCache(self $model): void
    {
        $attributeIds = [
            (int) $model->attribute_id,
            (int) ($model->getOriginal('attribute_id') ?? 0),
        ];

        foreach ($attributeIds as $attributeId) {
            if ($attributeId > 0) {
                FilterSchemaCache::forgetByAttribute($attributeId);
            }
        }
    }
}
