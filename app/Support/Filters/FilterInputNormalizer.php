<?php

namespace App\Support\Filters;

use App\DTO\FilterInput;
use App\Enums\FilterType;

/**
 * Приводит сырой payload фильтра из UI к детерминированному DTO.
 */
final class FilterInputNormalizer
{
    public static function normalize(string $key, mixed $payload): FilterInput
    {
        $type   = self::extractType($payload);
        $values = self::normalizeValues($payload);

        [$min, $max] = $type === FilterType::RANGE
            ? self::normalizeRange($payload)
            : [null, null];

        [$hasBoolValue, $bool] = $type === FilterType::BOOLEAN
            ? self::normalizeBoolean($payload)
            : [false, null];

        return new FilterInput(
            key: $key,
            type: $type,
            values: $values,
            min: $min,
            max: $max,
            bool: $bool,
            hasBoolValue: $hasBoolValue,
        );
    }

    private static function extractType(mixed $payload): ?FilterType
    {
        if (! is_array($payload)) {
            return null;
        }

        $typeRaw = $payload['type'] ?? null;

        return is_string($typeRaw)
            ? FilterType::tryFrom($typeRaw)
            : null;
    }

    /**
     * Собирает value + values, режет пустые строки и дубли.
     *
     * @return string[]
     */
    private static function normalizeValues(mixed $payload): array
    {
        $values = [];

        if (is_array($payload)) {
            $fromArray = $payload['values'] ?? [];
            if (is_array($fromArray)) {
                $values = array_merge($values, $fromArray);
            }

            if (array_key_exists('value', $payload)) {
                $values[] = $payload['value'];
            }
        } elseif ($payload !== null) {
            $values[] = $payload;
        }

        $values = array_map(static fn($v) => trim((string) $v), $values);
        $values = array_values(array_filter(
            array_unique($values),
            static fn($v) => $v !== ''
        ));

        return $values;
    }

    /**
     * Нормализует диапазон: приводит пустые к null.
     *
     * @return array{float|null,float|null}
     */
    private static function normalizeRange(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [null, null];
        }

        $min = self::normalizeNumber($payload['min'] ?? null);
        $max = self::normalizeNumber($payload['max'] ?? null);

        return [$min, $max];
    }

    private static function normalizeNumber(mixed $value): ?float
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        return (float) $value;
    }

    /**
     * Отмечает наличие булевого флажка и приводит его к bool.
     *
     * @return array{bool,bool|null} [hasValue, value]
     */
    private static function normalizeBoolean(mixed $payload): array
    {
        if (! is_array($payload) || ! array_key_exists('value', $payload)) {
            return [false, null];
        }

        return [true, (bool) $payload['value']];
    }
}
