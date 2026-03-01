<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class ValidKpp implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';

        if (! preg_match('/^\d{9}$/', $digits)) {
            $fail('КПП должен содержать 9 цифр.');
        }
    }
}
