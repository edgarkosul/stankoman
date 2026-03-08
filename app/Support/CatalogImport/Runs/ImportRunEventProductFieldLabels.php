<?php

namespace App\Support\CatalogImport\Runs;

use Illuminate\Support\Facades\Lang;

final class ImportRunEventProductFieldLabels
{
    public static function label(mixed $field): string
    {
        if (! is_string($field)) {
            return (string) $field;
        }

        $normalized = trim($field);

        if ($normalized === '') {
            return '';
        }

        $key = 'import-run-events.product_fields.'.$normalized;

        if (! Lang::has($key)) {
            return $normalized;
        }

        return (string) __($key);
    }

    /**
     * @param  array<int, mixed>  $fields
     * @return array<int, string>
     */
    public static function labels(array $fields): array
    {
        return array_values(array_map(
            static fn (mixed $field): string => self::label($field),
            $fields,
        ));
    }
}
