<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

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
 * @property string|null $match_source
 * @property bool $match_locked
 * @property Carbon|null $matched_at
 * @property int|null $matched_by_user_id
 * @property bool $redirect_enabled
 */
class LegacyProduct extends Model
{
    public const MATCH_SOURCE_AUTO = 'auto';

    public const MATCH_SOURCE_MANUAL = 'manual';

    public const STRATEGY_MANUAL = 'manual';

    public const STRATEGY_MANUAL_REMOVED = 'manual_removed';

    protected $fillable = [
        'source_site',
        'source_path',
        'name',
        'sku',
        'manufacturer',
        'matched_product_id',
        'match_strategy',
        'match_source',
        'match_locked',
        'matched_at',
        'matched_by_user_id',
        'redirect_enabled',
    ];

    protected $casts = [
        'match_locked' => 'bool',
        'matched_at' => 'datetime',
        'redirect_enabled' => 'bool',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function matchedProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'matched_product_id');
    }

    public function matchedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'matched_by_user_id');
    }

    public function applyAutomaticMatch(Product $product, string $strategy): void
    {
        if ($this->match_locked) {
            return;
        }

        $this->forceFill([
            'matched_product_id' => $product->getKey(),
            'match_strategy' => $strategy,
            'match_source' => self::MATCH_SOURCE_AUTO,
            'match_locked' => false,
            'matched_at' => now(),
            'matched_by_user_id' => null,
            'redirect_enabled' => true,
        ])->save();
    }

    public function clearAutomaticMatch(): void
    {
        if ($this->match_locked) {
            return;
        }

        $this->forceFill([
            'matched_product_id' => null,
            'match_strategy' => null,
            'match_source' => null,
            'matched_at' => null,
            'matched_by_user_id' => null,
            'redirect_enabled' => false,
        ])->save();
    }

    public function applyManualMatch(Product $product, ?User $user = null): void
    {
        $this->forceFill([
            'matched_product_id' => $product->getKey(),
            'match_strategy' => self::STRATEGY_MANUAL,
            'match_source' => self::MATCH_SOURCE_MANUAL,
            'match_locked' => true,
            'matched_at' => now(),
            'matched_by_user_id' => $user?->getKey(),
            'redirect_enabled' => true,
        ])->save();
    }

    public function removeManualMatch(?User $user = null): void
    {
        $this->forceFill([
            'matched_product_id' => null,
            'match_strategy' => self::STRATEGY_MANUAL_REMOVED,
            'match_source' => self::MATCH_SOURCE_MANUAL,
            'match_locked' => true,
            'matched_at' => now(),
            'matched_by_user_id' => $user?->getKey(),
            'redirect_enabled' => false,
        ])->save();
    }
}
