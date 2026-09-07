<?php

namespace App\Support\CatalogImport\Runs;

use Illuminate\Support\Facades\Lang;

final class ImportRunEventProductFieldLabels
{
    private const ADMIN_LOCALE = 'ru';

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

        if (Lang::has($key)) {
            return (string) __($key);
        }

        // Админка русскоязычная, а app.locale по умолчанию en: без явного
        // обращения к ru в интерфейс попадают технические имена колонок.
        if (Lang::has($key, self::ADMIN_LOCALE)) {
            return (string) __($key, [], self::ADMIN_LOCALE);
        }

        return $normalized;
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
