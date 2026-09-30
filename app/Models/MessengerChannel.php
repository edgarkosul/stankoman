<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Чат MAX, куда приходят уведомления для менеджеров.
 *
 * Появляется сам, когда менеджер подключает бота кнопкой из админки
 * (см. MaxUpdates). Флаги говорят, что сюда слать: заказы, заявки
 * с сайта, вопросы из чата. Если бота выгнали, канал выключается, а причина
 * остаётся в `last_error`, чтобы на странице было видно, почему
 * уведомления перестали приходить.
 *
 * @property int $id
 * @property string $label
 * @property string $chat_id
 * @property bool $notify_orders
 * @property bool $notify_requests
 * @property bool $notify_chat
 * @property bool $enabled
 * @property string|null $last_error
 * @property Carbon|null $failed_at
 * @property Carbon|null $last_sent_at
 */
class MessengerChannel extends Model
{
    public const TOPIC_ORDERS = 'orders';

    public const TOPIC_REQUESTS = 'requests';

    public const TOPIC_CHAT = 'chat';

    /** Тема → флаг канала. */
    public const TOPICS = [
        self::TOPIC_ORDERS => 'notify_orders',
        self::TOPIC_REQUESTS => 'notify_requests',
        self::TOPIC_CHAT => 'notify_chat',
    ];

    protected $fillable = [
        'label',
        'chat_id',
        'notify_orders',
        'notify_requests',
        'notify_chat',
        'enabled',
        'last_error',
        'failed_at',
        'last_sent_at',
    ];

    protected $attributes = [
        'notify_orders' => true,
        'notify_requests' => true,
        'notify_chat' => true,
        'enabled' => true,
    ];

    protected function casts(): array
    {
        return [
            'notify_orders' => 'boolean',
            'notify_requests' => 'boolean',
            'notify_chat' => 'boolean',
            'enabled' => 'boolean',
            'failed_at' => 'datetime',
            'last_sent_at' => 'datetime',
        ];
    }

    /** @param Builder<self> $query */
    public function scopeForTopic(Builder $query, string $topic): void
    {
        $flag = self::TOPICS[$topic] ?? throw new InvalidArgumentException("Неизвестная тема уведомлений: {$topic}");

        $query->where('enabled', true)->where($flag, true);
    }

    /**
     * Канал отказал насовсем: бота остановили или удалили из чата.
     * Выключаем и запоминаем почему — менеджер увидит это в админке.
     */
    public function disable(string $reason): void
    {
        $this->forceFill([
            'enabled' => false,
            'last_error' => mb_substr($reason, 0, 255),
            'failed_at' => now(),
        ])->save();
    }
}
