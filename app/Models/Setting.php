<?php

namespace App\Models;

use App\Enums\SettingType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    /** @use HasFactory<\Database\Factories\SettingFactory> */
    use HasFactory;

    public const CACHE_KEY = 'settings.cache';

    protected $fillable = [
        'key',
        'value',
        'type',
        'description',
        'autoload',
    ];

    protected function casts(): array
    {
        return [
            'type' => SettingType::class,
            'autoload' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn (): bool => self::flushCache());
        static::deleted(fn (): bool => self::flushCache());
    }

    public function getValueForConfig(): mixed
    {
        $raw = $this->value;

        return match ($this->type) {
            SettingType::Int => $raw === null ? null : (int) $raw,
            SettingType::Float => $raw === null ? null : (float) $raw,
            SettingType::Bool => filter_var($raw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE),
            SettingType::Json => $this->decodeJson($raw),
            default => $raw,
        };
    }

    public static function flushCache(): bool
    {
        return cache()->forget(self::CACHE_KEY);
    }

    protected function decodeJson(?string $raw): mixed
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        return $raw;
    }
}
