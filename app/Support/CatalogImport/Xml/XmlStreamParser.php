<?php

namespace App\Support\CatalogImport\Xml;

use App\Support\CatalogImport\Contracts\RecordParserInterface;
use App\Support\CatalogImport\DTO\ResolvedSource;
use RuntimeException;
use XMLReader;

final class XmlStreamParser implements RecordParserInterface
{
    /**
     * @param  array<string, mixed>  $options
     * @return \Generator<int, XmlRecord>
     */
    public function parse(ResolvedSource $source, array $options = []): \Generator
    {
        $recordNode = $this->stringOption($options, 'record_node', 'offer');

        if ($recordNode === '') {
            throw new RuntimeException('XmlStreamParser option "record_node" must not be empty.');
        }

        $encoding = $this->detectXmlEncoding($source->resolvedPath);

        $reader = new XMLReader;
        $flags = LIBXML_NONET | LIBXML_COMPACT;

        if (! $reader->open($source->resolvedPath, $encoding, $flags)) {
            throw new RuntimeException("Unable to open XML source: {$source->resolvedPath}");
        }

        $matchLocalName = $this->boolOption($options, 'match_local_name', true);
        $convertToUtf8 = $this->boolOption($options, 'convert_to_utf8', true);

        return $this->iterateRecords($reader, $recordNode, $encoding, $matchLocalName, $convertToUtf8);
    }

    /**
     * @return \Generator<int, XmlRecord>
     */
    private function iterateRecords(
        XMLReader $reader,
        string $recordNode,
        ?string $sourceEncoding,
        bool $matchLocalName,
        bool $convertToUtf8,
    ): \Generator {
        $index = 0;

        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT) {
                    continue;
                }

                if (! $this->matchesRecordNode($reader, $recordNode, $matchLocalName)) {
                    continue;
                }

                $nodeName = $reader->name;
                $attributes = $this->extractAttributes($reader);
                $xml = (string) $reader->readOuterXml();

                if ($convertToUtf8) {
                    $xml = $this->normalizeToUtf8($xml, $sourceEncoding);
                }

                yield new XmlRecord(
                    index: $index,
                    nodeName: $nodeName,
                    attributes: $attributes,
                    xml: $xml,
                );

                $index++;
                $reader->next();
            }
        } finally {
            $reader->close();
        }
    }

    private function matchesRecordNode(XMLReader $reader, string $recordNode, bool $matchLocalName): bool
    {
        $node = $matchLocalName ? $reader->localName : $reader->name;

        return $node === $recordNode;
    }

    /**
     * @return array<string, string>
     */
    private function extractAttributes(XMLReader $reader): array
    {
        $attributes = [];

        if (! $reader->hasAttributes) {
            return $attributes;
        }

        if (! $reader->moveToFirstAttribute()) {
            return $attributes;
        }

        do {
            $attributes[$reader->name] = trim((string) $reader->value);
        } while ($reader->moveToNextAttribute());

        $reader->moveToElement();

        return $attributes;
    }

    private function normalizeToUtf8(string $value, ?string $sourceEncoding): string
    {
        if ($value === '' || mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        if (function_exists('mb_convert_encoding')) {
            $source = $sourceEncoding !== null
                ? $sourceEncoding.',UTF-8'
                : 'UTF-8,Windows-1251,CP1251,ISO-8859-1';

            $converted = @mb_convert_encoding($value, 'UTF-8', $source);

            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }

        if (is_string($sourceEncoding) && $sourceEncoding !== '' && function_exists('iconv')) {
            $converted = @iconv($sourceEncoding, 'UTF-8//IGNORE', $value);

            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }

        return $value;
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
     * @param  array<string, mixed>  $options
     */
    private function stringOption(array $options, string $key, string $default): string
    {
        $value = $options[$key] ?? null;

        if (! is_string($value)) {
            return $default;
        }

        return trim($value);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function boolOption(array $options, string $key, bool $default): bool
    {
        $value = $options[$key] ?? null;

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (! is_string($value)) {
            return $default;
        }

        $normalized = strtolower(trim($value));

        return match ($normalized) {
            '1', 'true', 'yes', 'y', 'on' => true,
            '0', 'false', 'no', 'n', 'off' => false,
            default => $default,
        };
    }
}
