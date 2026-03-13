<?php

namespace App\Enums;

enum ProductWarranty: string
{
    case Months12 = '12';
    case Months24 = '24';
    case Months36 = '36';
    case Months60 = '60';

    public function label(): string
    {
        return $this->value.' мес.';
    }

    public static function fromInput(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        if ($normalized === '') {
            return null;
        }

        $case = self::tryFrom($normalized);

        if ($case instanceof self) {
            return $case;
        }

        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
        $normalized = function_exists('mb_strtolower')
            ? mb_strtolower($normalized, 'UTF-8')
            : strtolower($normalized);

        if (preg_match('/^(?<months>\d+)\s*(?:мес\.?|месяц|месяца|месяцев)$/u', $normalized, $matches) !== 1) {
            return null;
        }

        return self::tryFrom($matches['months']);
    }

    public static function normalizeInput(mixed $value): ?string
    {
        return self::fromInput($value)?->value;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::Months12->value => self::Months12->label(),
            self::Months24->value => self::Months24->label(),
            self::Months36->value => self::Months36->label(),
            self::Months60->value => self::Months60->label(),
        ];
    }
}
