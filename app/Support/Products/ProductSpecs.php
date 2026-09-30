<?php

namespace App\Support\Products;

use App\Models\Product;

/**
 * Характеристики товара для показа человеку.
 *
 * Источник — колонка `products.specs` (JSON), а не EAV: на бою она заполнена
 * у 3 571 товара из 3 579, тогда как значения атрибутов покрывают чуть больше
 * трети каталога. EAV нужен фильтрам и сравнению, карточке — эта колонка.
 *
 * Правило одно, а читателей несколько: вкладка характеристик на витрине,
 * PDF-версия карточки, дальше — ассистент, которому эти же строки уезжают
 * в подсказку. До выноса правило было списано дважды слово в слово
 * (ProductController::buildSpecs и ProductPrintController::specsForPdf,
 * вплоть до комментария «берём те же данные, что и вкладка specs»),
 * и разойтись они могли молча: у PDF нет ни теста, ни глаз на каждый релиз.
 */
class ProductSpecs
{
    /**
     * Строки характеристик, готовые к выводу.
     *
     * @param  int  $limit  сколько строк вернуть; 0 — все. Обрезка нужна тем,
     *                      кто платит за длину: в подсказку модели весь список
     *                      характеристик не влезает и не нужен.
     * @return array<int, array{name: string, value: string, source: string|null}>
     */
    public function rows(Product $product, int $limit = 0): array
    {
        $raw = $product->specs;

        /*
         * Колонка кастуется в массив, но приезжать сюда может и строкой:
         * из сырых атрибутов, из несохранённой модели, из старых записей,
         * где JSON лежал текстом.
         */
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $raw = $decoded;
            }
        }

        if (! is_array($raw)) {
            return [];
        }

        $rows = [];

        foreach ($raw as $key => $row) {
            /*
             * Два формата в одной колонке: список объектов
             * ([{name, value, source}, …]) от импорта и простая карта
             * «название => значение» от ручной правки.
             */
            $normalized = is_array($row)
                ? self::row($row['name'] ?? $key, $row['value'] ?? null, $row['source'] ?? null)
                : self::row($key, $row);

            if ($normalized === null) {
                continue;
            }

            $rows[] = $normalized;

            if ($limit > 0 && count($rows) >= $limit) {
                break;
            }
        }

        return $rows;
    }

    /**
     * @return array{name: string, value: string, source: string|null}|null
     */
    private static function row(mixed $nameRaw, mixed $valueRaw, mixed $sourceRaw = null): ?array
    {
        $name = self::text($nameRaw);
        $value = self::value($valueRaw);

        // Строка без названия или без значения — это не характеристика,
        // а мусор импорта: показывать пустую ячейку хуже, чем не показать.
        if ($name === null || $value === null) {
            return null;
        }

        return [
            'name' => $name,
            'value' => $value,
            'source' => self::text($sourceRaw),
        ];
    }

    private static function text(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }

    private static function value(mixed $value): ?string
    {
        // Булево из фида читается человеком только словами.
        if (is_bool($value)) {
            return $value ? 'Да' : 'Нет';
        }

        return self::text($value);
    }
}
