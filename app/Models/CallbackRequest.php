<?php

namespace App\Models;

use Database\Factories\CallbackRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CallbackRequest extends Model
{
    /** @use HasFactory<CallbackRequestFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_CALLED = 'called';

    public const STATUS_CANCELLED = 'cancelled';

    public const SOURCE_SITE = 'site';

    public const SOURCE_CHAT = 'chat';

    protected $fillable = [
        'user_id',
        'product_id',
        'name',
        'phone',
        'phone_hash',
        'email',
        'email_hash',
        'city',
        'call_time',
        'comments',
        'source',
        'status',
        'attempts',
        'notified_at',
        'last_error',
        'ip_address',
        'user_agent',
    ];

    protected $attributes = [
        'source' => self::SOURCE_SITE,
        'status' => self::STATUS_PENDING,
        'attempts' => 0,
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'notified_at' => 'datetime',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_PENDING => 'Ждёт ответа',
            self::STATUS_CALLED => 'Связались',
            self::STATUS_CANCELLED => 'Отменена',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function sourceLabels(): array
    {
        return [
            self::SOURCE_SITE => 'Сайт',
            self::SOURCE_CHAT => 'Чат',
        ];
    }

    public function statusLabel(): string
    {
        return self::statusLabels()[$this->status] ?? (string) $this->status;
    }

    public function sourceLabel(): string
    {
        return self::sourceLabels()[$this->source] ?? (string) $this->source;
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', self::STATUS_PENDING);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
