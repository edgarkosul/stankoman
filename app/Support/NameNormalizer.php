<?php

namespace App\Support;

use App\Models\Unit;
use Illuminate\Support\Facades\Schema;

final class NameNormalizer
{
    /**
     * @var array<string, string|null>
     */
    private static array $normalizedValuesCache = [];

    /**
     * @var array<string, true>|null
     */
    private static ?array $unitTokensCache = null;

    public static function flushCache(): void
    {
        self::$normalizedValuesCache = [];
        self::$unitTokensCache = null;
    }

    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (array_key_exists($value, self::$normalizedValuesCache)) {
            return self::$normalizedValuesCache[$value];
        }

        $normalized = str_replace(
            ["\xC2\xA0", "\xE2\x80\xAF", "\t", "\n", "\r"],
            ' ',
            $value
        );

        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
        $normalized = trim($normalized);

        if ($normalized === '') {
            return null;
        }

        $normalized = mb_strtolower($normalized, 'UTF-8');

        $normalized = strtr($normalized, [
            '–' => '-',
            '—' => '-',
            '−' => '-',
            '“' => '"',
            '”' => '"',
            '„' => '"',
            '«' => '"',
            '»' => '"',
            '‟' => '"',
            '’' => "'",
            '‘' => "'",
            '‚' => "'",
        ]);

        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return self::$normalizedValuesCache[$value] = self::stripTrailingUnitToken($normalized);
    }

    private static function stripTrailingUnitToken(string $normalized): ?string
    {
        if ($normalized === '') {
            return null;
        }

        if (! preg_match('/^(.*?)(\s*,\s*|\s+)([^,]+)$/u', $normalized, $matches)) {
            return $normalized;
        }

        $unitToken = self::normalizeUnitToken($matches[3]);

        if ($unitToken === null || ! isset(self::unitTokens()[$unitToken])) {
            return $normalized;
        }

        $trimmed = trim($matches[1]);

        return $trimmed === '' ? $normalized : $trimmed;
    }

    /**
     * @return array<string, true>
     */
    private static function unitTokens(): array
    {
        if (self::$unitTokensCache !== null) {
            return self::$unitTokensCache;
        }

        if (! Schema::hasTable('units')) {
            return self::$unitTokensCache = [];
        }

        $tokens = [];

        foreach (Unit::query()->get(['name', 'symbol', 'base_symbol']) as $unit) {
            foreach ([$unit->name, $unit->symbol, $unit->base_symbol] as $value) {
                $token = self::normalizeUnitToken($value);

                if ($token !== null) {
                    $tokens[$token] = true;
                }
            }
        }

        return self::$unitTokensCache = $tokens;
    }

    private static function normalizeUnitToken(?string $value): ?string
    {
        $normalized = self::normalizeBasic($value);

        if ($normalized === null) {
            return null;
        }

        $normalized = strtr($normalized, [
            '⁰' => '0',
            '¹' => '1',
            '²' => '2',
            '³' => '3',
            '⁴' => '4',
            '⁵' => '5',
            '⁶' => '6',
            '⁷' => '7',
            '⁸' => '8',
            '⁹' => '9',
            '₀' => '0',
            '₁' => '1',
            '₂' => '2',
            '₃' => '3',
            '₄' => '4',
            '₅' => '5',
            '₆' => '6',
            '₇' => '7',
            '₈' => '8',
            '₉' => '9',
        ]);

        $normalized = str_replace([' ', '.'], '', $normalized);

        return $normalized === '' ? null : $normalized;
    }

    private static function normalizeBasic(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = str_replace(
            ["\xC2\xA0", "\xE2\x80\xAF", "\t", "\n", "\r"],
            ' ',
            $value
        );

        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
        $normalized = trim($normalized);

        if ($normalized === '') {
            return null;
        }

        $normalized = mb_strtolower($normalized, 'UTF-8');

        $normalized = strtr($normalized, [
            '–' => '-',
            '—' => '-',
            '−' => '-',
            '“' => '"',
            '”' => '"',
            '„' => '"',
            '«' => '"',
            '»' => '"',
            '‟' => '"',
            '’' => "'",
            '‘' => "'",
            '‚' => "'",
        ]);

        return preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
    }
}
