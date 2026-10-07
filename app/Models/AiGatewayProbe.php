<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Одна проба одного адреса шлюза. Подробности — в миграции.
 *
 * Дописывается и не правится: `updated_at` у таблицы нет.
 */
class AiGatewayProbe extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'ip',
        'ok',
        'http_status',
        'latency_ms',
        'error',
        'pinned',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'ok' => 'boolean',
            'pinned' => 'boolean',
            'created_at' => 'datetime',
        ];
    }
}
