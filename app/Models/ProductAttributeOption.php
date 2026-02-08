<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $product_id
 * @property int $attribute_id
 * @property int $attribute_option_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Attribute $attribute
 * @property-read \App\Models\AttributeOption $option
 * @property-read \App\Models\Product $product
 * @property-write mixed $for_product
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductAttributeOption forAttribute(int $attributeId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductAttributeOption forProduct(int $productId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductAttributeOption newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductAttributeOption newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductAttributeOption query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductAttributeOption whereAttributeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductAttributeOption whereAttributeOptionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductAttributeOption whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductAttributeOption whereProductId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductAttributeOption whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class ProductAttributeOption extends Pivot
{
    // Таблица pivot
    protected $table = 'product_attribute_option';

    // В этой таблице нет авто-инкрементного PK
    public $incrementing = false;

    // Таймстемпы есть (created_at / updated_at)
    public $timestamps = true;

    protected $fillable = [
        'product_id',
        'attribute_id',
        'attribute_option_id',
    ];

    /* -------------------- Связи -------------------- */

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class);
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(AttributeOption::class, 'attribute_option_id');
    }

    /* -------------------- Скоупы -------------------- */

    public function scopeForProduct($q, int $productId)
    {
        return $q->where('product_id', $productId);
    }

    public function scopeForAttribute($q, int $attributeId)
    {
        return $q->where('attribute_id', $attributeId);
    }

    /* -------------------- Хелперы записи -------------------- */

    /**
     * Синхронизировать набор опций (multiselect).
     * Добавит недостающие, удалит снятые.
     */
    public static function setForProductAttribute(int $productId, int $attributeId, array $optionIds): void
    {
        $optionIds = array_values(array_unique(array_map('intval', $optionIds)));

        // существующие
        $current = static::query()
            ->forProduct($productId)->forAttribute($attributeId)
            ->pluck('attribute_option_id')->all();

        $toInsert = array_diff($optionIds, $current);
        $toDelete = array_diff($current, $optionIds);

        if ($toDelete) {
            static::query()
                ->forProduct($productId)->forAttribute($attributeId)
                ->whereIn('attribute_option_id', $toDelete)
                ->delete();
        }

        if ($toInsert) {
            $now = now();
            $rows = array_map(fn ($id) => [
                'product_id'          => $productId,
                'attribute_id'        => $attributeId,
                'attribute_option_id' => $id,
                'created_at'          => $now,
                'updated_at'          => $now,
            ], $toInsert);

            static::query()->insert($rows);
        }
    }

    /**
     * Установить одну опцию (select).
     * Сначала очищает все опции этого атрибута у продукта.
     */
    public static function setSingle(int $productId, int $attributeId, ?int $optionId): void
    {
        static::query()->forProduct($productId)->forAttribute($attributeId)->delete();

        if ($optionId) {
            static::query()->insert([
                'product_id'          => $productId,
                'attribute_id'        => $attributeId,
                'attribute_option_id' => (int) $optionId,
                'created_at'          => now(),
                'updated_at'          => now(),
            ]);
        }
    }
}
