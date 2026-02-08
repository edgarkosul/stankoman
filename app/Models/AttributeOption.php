<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
/**
 * @property int $id
 * @property int $attribute_id
 * @property string $value
 * @property int $sort_order
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Attribute $attribute
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeOption newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeOption newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeOption query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeOption whereAttributeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeOption whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeOption whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeOption whereSortOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeOption whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeOption whereValue($value)
 * @mixin \Eloquent
 */
class AttributeOption extends Model
{
    protected $fillable = ['attribute_id', 'value', 'sort_order'];

    public function attribute()
    {
        return $this->belongsTo(Attribute::class);
    }
}
