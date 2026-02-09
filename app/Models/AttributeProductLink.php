<?php

namespace App\Models;

use App\Models\Product;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string|null $id
 * @property int $attribute_id
 * @property int $product_id
 * @property string $source
 * @property string|null $pav_id
 * @property string|null $pao_option_ids
 * @property string|null $pao_values
 * @property string|null $value_text
 * @property string|null $value_number
 * @property int|null $value_boolean
 * @property string|null $value_min
 * @property string|null $value_max
 * @property-read Product|null $product
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeProductLink newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeProductLink newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeProductLink query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeProductLink whereAttributeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeProductLink whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeProductLink wherePaoOptionIds($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeProductLink wherePaoValues($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeProductLink wherePavId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeProductLink whereProductId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeProductLink whereSource($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeProductLink whereValueBoolean($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeProductLink whereValueMax($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeProductLink whereValueMin($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeProductLink whereValueNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeProductLink whereValueText($value)
 * @mixin \Eloquent
 */
class AttributeProductLink extends Model
{
    protected $table = 'attribute_product_links';

    // Ключ теперь есть
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    public $timestamps = false;
    protected $guarded = [];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
