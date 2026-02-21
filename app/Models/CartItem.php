<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'cart_id',
        'product_id',
        'quantity',
        'price_snapshot',
        'options',
        'options_key',
    ];

    protected $casts = [
        'options' => 'array',
        'price_snapshot' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $item): void {
            $item->options_key = self::makeOptionsKey($item->options ?? []);
        });
    }

    /**
     * @param  array<string, mixed>|null  $options
     */
    public static function makeOptionsKey(?array $options): string
    {
        $normalize = function (mixed $value) use (&$normalize): mixed {
            if (is_array($value)) {
                ksort($value);
                foreach ($value as $key => $nested) {
                    $value[$key] = $normalize($nested);
                }
            }

            return $value;
        };

        $normalized = $normalize($options ?? []);

        return sha1((string) json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public static function emptyOptionsKey(): string
    {
        return self::makeOptionsKey([]);
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function subtotal(): float
    {
        return (float) ($this->price_snapshot ?? 0) * (int) $this->quantity;
    }
}
