<?php

namespace App\Support\CatalogImport\Yml;

final class VendorModelOfferNameResolver
{
    public function composeLegacyName(
        ?string $typePrefix,
        ?string $vendor,
        ?string $model,
        ?string $fallbackName = null,
    ): ?string {
        $typePrefix = $this->normalize($typePrefix);
        $vendor = $this->normalize($vendor);
        $model = $this->normalize($model);
        $fallbackName = $this->normalize($fallbackName);

        if ($model === null) {
            return $fallbackName;
        }

        $parts = array_values(array_filter([
            $typePrefix,
            $vendor,
            $model,
        ], static fn (?string $part): bool => $part !== null));

        if ($parts === []) {
            return $fallbackName;
        }

        return implode(' ', $parts);
    }

    public function resolveName(
        ?string $typePrefix,
        ?string $vendor,
        ?string $model,
        ?string $fallbackName = null,
    ): ?string {
        $typePrefix = $this->normalize($typePrefix);
        $vendor = $this->normalize($vendor);
        $model = $this->normalize($model);
        $fallbackName = $this->normalize($fallbackName);

        if ($model === null) {
            return $fallbackName;
        }

        $name = $model;

        if (
            $vendor !== null
            && ! $this->startsWithPart($name, $vendor)
            && ! $this->startsWithPartAfterPrefix($name, $typePrefix, $vendor)
        ) {
            $name = $vendor.' '.$name;
        }

        if ($typePrefix !== null && ! $this->startsWithPart($name, $typePrefix)) {
            $name = $typePrefix.' '.$name;
        }

        return $name;
    }

    private function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
        $value = trim($value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function startsWithPart(string $value, string $part): bool
    {
        return preg_match($this->leadingPartPattern($part), $value) === 1;
    }

    private function startsWithPartAfterPrefix(string $value, ?string $prefix, string $part): bool
    {
        if ($prefix === null || ! $this->startsWithPart($value, $prefix)) {
            return false;
        }

        $withoutPrefix = preg_replace($this->leadingPartPattern($prefix), '', $value, 1);

        if (! is_string($withoutPrefix)) {
            return false;
        }

        $withoutPrefix = trim($withoutPrefix);

        if ($withoutPrefix === '') {
            return false;
        }

        return $this->startsWithPart($withoutPrefix, $part);
    }

    private function leadingPartPattern(string $part): string
    {
        return '/^'.preg_quote($part, '/').'(?=$|[\s\-\/\(\[])/iu';
    }
}
