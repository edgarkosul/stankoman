<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ShippingMethod;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Order extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_company' => 'bool',
            'items_subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'shipping_total' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'order_date' => 'date',
            'submitted_at' => 'datetime',
            'status' => OrderStatus::class,
            'payment_status' => PaymentStatus::class,
            'shipping_method' => ShippingMethod::class,
        ];
    }

    protected $attributes = [
        'currency' => 'RUB',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    protected static function booted(): void
    {
        static::creating(function (self $order): void {
            $order->order_date ??= now()->toDateString();
            $order->currency ??= 'RUB';

            DB::transaction(function () use ($order): void {
                $maxSeq = self::query()
                    ->whereDate('order_date', $order->order_date)
                    ->lockForUpdate()
                    ->max('seq');

                $order->seq = ((int) $maxSeq) + 1;
                $order->order_number = Carbon::parse($order->order_date)->format('d-m-y').'/'.str_pad((string) $order->seq, 2, '0', STR_PAD_LEFT);
                $order->public_hash ??= Str::random(40);
            });
        });
    }

    public function recalcTotals(): void
    {
        $itemsSubtotal = $this->items->sum(fn (OrderItem $item): float => (float) $item->total_amount);

        $this->items_subtotal = round($itemsSubtotal, 2);
        $this->grand_total = round(
            $this->items_subtotal - (float) $this->discount_total + (float) $this->shipping_total,
            2
        );
    }
}
