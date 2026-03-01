<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class ValidPhone implements ValidationRule
{
    public static function normalize(?string $value): ?string
    {
        $raw = is_string($value) ? $value : '';
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if ($digits === '') {
            return null;
        }

        if (strlen($digits) === 10) {
            $digits = '7'.$digits;
        } elseif (strlen($digits) === 11 && str_starts_with($digits, '8')) {
            $digits = '7'.substr($digits, 1);
        }

        if (strlen($digits) !== 11 || ! str_starts_with($digits, '7')) {
            return null;
        }

        return '+'.$digits;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (self::normalize((string) $value) === null) {
            $fail('Укажите телефон в формате +7 (999) 123-45-67.');
        }
    }
}
