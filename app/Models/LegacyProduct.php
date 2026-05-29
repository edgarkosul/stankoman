<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Parsed product page from an abandoned legacy site.
 *
 * @property int $id
 * @property string $source_site
 * @property string $source_path
 * @property string $name
 * @property string|null $sku
 * @property string|null $manufacturer
 * @property int|null $matched_product_id
 * @property string|null $match_strategy
 * @property bool $redirect_enabled
 */
class LegacyProduct extends Model
{
    protected $fillable = [
        'source_site',
        'source_path',
        'name',
        'sku',
        'manufacturer',
        'matched_product_id',
        'match_strategy',
        'redirect_enabled',
    ];

    protected $casts = [
        'redirect_enabled' => 'bool',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function matchedProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'matched_product_id');
    }
}
