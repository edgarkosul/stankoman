<?php

namespace App\Support\CatalogImport\Processing;

final class ExistingProductUpdateSelection
{
    public const MODE_ALL = 'all';

    public const MODE_SELECTED = 'selected';

    public const FIELD_PRICE = 'price';

    public const FIELD_AVAILABILITY = 'availability';

    public const FIELD_VIDEO = 'video';

    public const FIELD_IMAGES = 'images';

    /**
     * @var array<string, array<int, string>>
     */
    private const ATTRIBUTE_MAP = [
        self::FIELD_PRICE => ['price_amount'],
        self::FIELD_AVAILABILITY => ['in_stock'],
        self::FIELD_VIDEO => ['video'],
        self::FIELD_IMAGES => ['image', 'thumb', 'gallery'],
    ];

    /**
     * @return array<int, string>
     */
    public static function defaultFields(): array
    {
        return [
            self::FIELD_PRICE,
            self::FIELD_AVAILABILITY,
            self::FIELD_VIDEO,
            self::FIELD_IMAGES,
        ];
    }

    public static function normalizeMode(mixed $value): string
    {
        if (! is_string($value)) {
            return self::MODE_ALL;
        }

        $value = trim($value);

        return in_array($value, [self::MODE_ALL, self::MODE_SELECTED], true)
            ? $value
            : self::MODE_ALL;
    }

    /**
     * @return array<int, string>
     */
    public static function normalizeFields(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $normalized = [];
        $allowed = array_fill_keys(self::defaultFields(), true);

        foreach ($value as $field) {
            if (! is_string($field)) {
                continue;
            }

            $field = trim($field);

            if ($field === '' || ! isset($allowed[$field])) {
                continue;
            }

            $normalized[$field] = $field;
        }

        return array_values($normalized);
    }

    /**
     * @param  array<int, string>  $fields
     */
    public static function updatesImages(string $mode, array $fields): bool
    {
        if ($mode === self::MODE_ALL) {
            return true;
        }

        return in_array(self::FIELD_IMAGES, $fields, true);
    }

    /**
     * @param  array<int, string>  $fields
     * @return array<int, string>
     */
    public static function resolveAttributeKeys(string $mode, array $fields): array
    {
        if ($mode === self::MODE_ALL) {
            return array_values(array_unique(array_merge(...array_values(self::ATTRIBUTE_MAP))));
        }

        $attributeKeys = [];

        foreach ($fields as $field) {
            foreach (self::ATTRIBUTE_MAP[$field] ?? [] as $attributeKey) {
                $attributeKeys[$attributeKey] = $attributeKey;
            }
        }

        return array_values($attributeKeys);
    }
}
