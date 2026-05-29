<?php

namespace App\Support\Legacy;

final class KratonProductPageParser
{
    /**
     * @return array{name: string, sku: string|null, manufacturer: string|null}|null
     */
    public function parse(string $contents): ?array
    {
        $contents = $this->decodeLegacyContents($contents);

        if (! str_contains($contents, 'id="TovKart"') || ! str_contains($contents, 'schema.org/Product')) {
            return null;
        }

        $name = $this->extract('/<div\s+itemprop=["\']name["\']\s*>(.*?)<\/div>/isu', $contents);

        if ($name === null) {
            return null;
        }

        return [
            'name' => $name,
            'sku' => $this->extractLabeledStrongValue('Артикул', $contents),
            'manufacturer' => $this->extractLabeledStrongValue('Производитель', $contents),
        ];
    }

    private function decodeLegacyContents(string $contents): string
    {
        if (mb_check_encoding($contents, 'UTF-8')) {
            return $contents;
        }

        return mb_convert_encoding($contents, 'UTF-8', 'Windows-1251');
    }

    private function extractLabeledStrongValue(string $label, string $contents): ?string
    {
        return $this->extract('/'.preg_quote($label, '/').':\s*<FONT[^>]*>\s*<STRONG>(.*?)<\/STRONG>/isu', $contents);
    }

    private function extract(string $pattern, string $contents): ?string
    {
        if (! preg_match($pattern, $contents, $matches)) {
            return null;
        }

        $value = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = strip_tags($value);
        $value = str_replace(["\xC2\xA0", "\xE2\x80\xAF"], ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
