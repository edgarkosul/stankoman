<?php

namespace App\Filament\Forms\Components\RichEditor;

class TextSizeOptions
{
    /**
     * @var array<int, string>
     */
    private const SIZES = [
        'text-xs',
        'text-sm',
        'text-base',
        'text-lg',
        'text-xl',
        'text-2xl',
        'text-3xl',
        'text-4xl',
        'text-5xl',
        'text-6xl',
        'text-7xl',
        'text-8xl',
        'text-9xl',
    ];

    /**
     * @return array<int, string>
     */
    public static function classes(): array
    {
        return self::SIZES;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_combine(self::SIZES, self::SIZES);
    }

    public static function normalize(?string $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return in_array($value, self::SIZES, true) ? $value : null;
    }
}
