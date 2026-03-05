<?php

namespace App\Support\CatalogImport\Yml;

use RuntimeException;
use XMLReader;

class YmlStreamParser
{
    public function open(string $path): YmlStream
    {
        $reader = new XMLReader;
        $encoding = $this->detectXmlEncoding($path);

        $flags = LIBXML_NONET | LIBXML_COMPACT;

        if (! $reader->open($path, $encoding, $flags)) {
            throw new RuntimeException("Unable to open YML feed: {$path}");
        }

        $categories = $this->readCategories($reader);
        $offers = $this->iterateOffers($reader);

        return new YmlStream($categories, $offers);
    }

    /**
     * @return array<int, string>
     */
    private function readCategories(XMLReader $reader): array
    {
        $categories = [];

        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }

            if ($reader->name === 'category') {
                $raw = (string) $reader->readOuterXml();

                $parsed = $this->parseCategory($raw);

                if ($parsed !== null) {
                    $categories[$parsed['id']] = $parsed['name'];
                }

                $reader->next();

                continue;
            }

            if ($reader->name === 'offers') {
                break;
            }
        }

        return $categories;
    }

    /**
     * @return \Generator<int, YmlOfferRecord>
     */
    private function iterateOffers(XMLReader $reader): \Generator
    {
        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT) {
                    continue;
                }

                if ($reader->name !== 'offer') {
                    continue;
                }

                $id = trim((string) ($reader->getAttribute('id') ?? ''));
                $type = $this->nullableTrimmed($reader->getAttribute('type'));
                $available = $this->parseBoolAttribute($reader->getAttribute('available'));
                $xml = (string) $reader->readOuterXml();

                yield new YmlOfferRecord(
                    id: $id,
                    type: $type,
                    available: $available,
                    xml: $xml,
                );

                $reader->next();
            }
        } finally {
            $reader->close();
        }
    }

    private function detectXmlEncoding(string $path): ?string
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        $head = @fread($handle, 4096);
        @fclose($handle);

        if (! is_string($head) || $head === '') {
            return null;
        }

        if (preg_match('/<\\?xml[^>]*encoding=[\"\\\']([^\"\\\']+)[\"\\\']/i', $head, $matches) !== 1) {
            return null;
        }

        $encoding = trim((string) ($matches[1] ?? ''));

        return $encoding !== '' ? $encoding : null;
    }

    /**
     * @return array{id:int,name:string}|null
     */
    private function parseCategory(string $xml): ?array
    {
        $xml = trim($xml);

        if ($xml === '') {
            return null;
        }

        $prev = libxml_use_internal_errors(true);

        try {
            $node = simplexml_load_string($xml);

            if (! $node) {
                return null;
            }

            $idRaw = trim((string) ($node['id'] ?? ''));

            if ($idRaw === '' || ! ctype_digit($idRaw)) {
                return null;
            }

            $name = trim((string) $node);

            return [
                'id' => (int) $idRaw,
                'name' => $name,
            ];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
    }

    private function nullableTrimmed(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function parseBoolAttribute(?string $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        $value = strtolower(trim($value));

        if ($value === '') {
            return null;
        }

        if (in_array($value, ['true', '1', 'yes', 'y'], true)) {
            return true;
        }

        if (in_array($value, ['false', '0', 'no', 'n'], true)) {
            return false;
        }

        return null;
    }
}
