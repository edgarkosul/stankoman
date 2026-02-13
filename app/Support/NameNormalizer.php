<?php

namespace App\Support;

final class NameNormalizer
{
    public static function normalize(?string $value): ?string
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
