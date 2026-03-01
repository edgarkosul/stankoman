<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class ValidInn implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';
        $length = strlen($digits);

        if (! in_array($length, [10, 12], true)) {
            $fail('ИНН должен содержать 10 или 12 цифр.');

            return;
        }

        if (! $this->isValid($digits)) {
            $fail('ИНН указан некорректно.');
        }
    }

    private function isValid(string $digits): bool
    {
        if (strlen($digits) === 10) {
            $coefficients = [2, 4, 10, 3, 5, 9, 4, 6, 8];
            $control = $this->controlDigit(substr($digits, 0, 9), $coefficients);

            return $control === (int) $digits[9];
        }

        if (strlen($digits) === 12) {
            $coefficients11 = [7, 2, 4, 10, 3, 5, 9, 4, 6, 8];
            $coefficients12 = [3, 7, 2, 4, 10, 3, 5, 9, 4, 6, 8];
            $control11 = $this->controlDigit(substr($digits, 0, 10), $coefficients11);
            $control12 = $this->controlDigit(substr($digits, 0, 11), $coefficients12);

            return $control11 === (int) $digits[10] && $control12 === (int) $digits[11];
        }

        return false;
    }

    private function controlDigit(string $base, array $coefficients): int
    {
        $sum = 0;

        foreach (str_split($base) as $index => $digit) {
            $sum += ((int) $digit) * $coefficients[$index];
        }

        return ($sum % 11) % 10;
    }
}
