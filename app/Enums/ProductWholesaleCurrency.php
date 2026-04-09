<?php

namespace App\Enums;

enum ProductWholesaleCurrency: string
{
    case Usd = 'USD';
    case Cny = 'CNY';
    case Eur = 'EUR';
    case Rur = 'RUR';

    public function label(): string
    {
        return $this->value;
    }

    public function cbrCharCode(): ?string
    {
        return match ($this) {
            self::Usd => 'USD',
            self::Cny => 'CNY',
            self::Eur => 'EUR',
            self::Rur => null,
        };
    }

    public function defaultExchangeRate(): float
    {
        return match ($this) {
            self::Rur => 1.0,
            default => 0.0,
        };
    }

    public static function fromInput(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (! is_scalar($value)) {
            return null;
        }

        $normalized = strtoupper(trim((string) $value));

        if ($normalized === '') {
            return null;
        }

        if ($normalized === 'CHY') {
            $normalized = self::Cny->value;
        }

        return self::tryFrom($normalized);
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
            self::Usd->value => self::Usd->label(),
            self::Cny->value => self::Cny->label(),
            self::Eur->value => self::Eur->label(),
            self::Rur->value => self::Rur->label(),
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_keys(self::options());
    }
}
