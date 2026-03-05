<?php

namespace App\Support\CatalogImport\Html;

use App\Support\CatalogImport\Contracts\RecordParserInterface;
use App\Support\CatalogImport\DTO\ResolvedSource;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMNodeList;
use DOMXPath;
use RuntimeException;
use Symfony\Component\CssSelector\CssSelectorConverter;
use Throwable;

final class HtmlDomParser implements RecordParserInterface
{
    private CssSelectorConverter $selectorConverter;

    public function __construct(?CssSelectorConverter $selectorConverter = null)
    {
        $this->selectorConverter = $selectorConverter ?? new CssSelectorConverter(html: true);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return \Generator<int, HtmlRecord>
     */
    public function parse(ResolvedSource $source, array $options = []): \Generator
    {
        $html = $this->readSource($source->resolvedPath);
        $dom = $this->createDom($html);
        $xpath = new DOMXPath($dom);
        $cards = $this->resolveCardNodes($xpath, $options);
        $fieldRules = $this->normalizeFieldRules($options['fields'] ?? []);

        return $this->iterateCards($xpath, $cards, $fieldRules);
    }

    private function readSource(string $path): string
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("HTML source was not found or is not readable: {$path}");
        }

        $raw = @file_get_contents($path);

        if (! is_string($raw)) {
            throw new RuntimeException("Unable to read HTML source: {$path}");
        }

        return $this->ensureUtf8($raw);
    }

    private function createDom(string $html): DOMDocument
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previousState = libxml_use_internal_errors(true);

        try {
            $loaded = $dom->loadHTML(
                '<?xml encoding="UTF-8">'.$html,
                LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOWARNING | LIBXML_NOERROR
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousState);
        }

        if ($loaded === false) {
            throw new RuntimeException('Unable to parse HTML source.');
        }

        return $dom;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<int, DOMNode>
     */
    private function resolveCardNodes(DOMXPath $xpath, array $options): array
    {
        $cardXPath = $this->stringFromMixed($options['card_xpath'] ?? null);
        $cardSelector = $this->stringFromMixed($options['card_selector'] ?? null);

        if ($cardXPath !== null) {
            return $this->nodeListToArray($this->queryNodes($xpath, $cardXPath));
        }

        if ($cardSelector !== null) {
            $expression = $this->selectorToXPath($cardSelector);

            return $this->nodeListToArray($this->queryNodes($xpath, $expression));
        }

        return $this->nodeListToArray($this->queryNodes($xpath, '//body'));
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function normalizeFieldRules(mixed $rules): array
    {
        if (! is_array($rules)) {
            return [];
        }

        $normalized = [];

        foreach ($rules as $field => $definition) {
            if (! is_string($field) || trim($field) === '') {
                continue;
            }

            $normalized[$field] = $this->normalizeExtractors($definition);
        }

        return $normalized;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeExtractors(mixed $definition): array
    {
        if (is_string($definition) && trim($definition) !== '') {
            return [['selector' => trim($definition)]];
        }

        if (! is_array($definition)) {
            return [];
        }

        if ($this->isExtractorRule($definition)) {
            return [$definition];
        }

        $extractors = [];

        foreach ($definition as $entry) {
            if (is_string($entry) && trim($entry) !== '') {
                $extractors[] = ['selector' => trim($entry)];

                continue;
            }

            if ($this->isExtractorRule($entry)) {
                $extractors[] = $entry;
            }
        }

        return $extractors;
    }

    private function isExtractorRule(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }

        return array_key_exists('selector', $value) || array_key_exists('xpath', $value);
    }

    /**
     * @param  array<int, DOMNode>  $cards
     * @param  array<string, array<int, array<string, mixed>>>  $fieldRules
     * @return \Generator<int, HtmlRecord>
     */
    private function iterateCards(DOMXPath $xpath, array $cards, array $fieldRules): \Generator
    {
        foreach ($cards as $index => $card) {
            $fields = $this->extractFields($xpath, $card, $fieldRules);

            yield new HtmlRecord(
                index: $index,
                html: $this->outerHtml($card),
                fields: $fields,
                meta: [
                    'node_name' => $card->nodeName,
                ],
            );
        }
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $fieldRules
     * @return array<string, string|null>
     */
    private function extractFields(DOMXPath $xpath, DOMNode $card, array $fieldRules): array
    {
        $fields = [];

        foreach ($fieldRules as $field => $extractors) {
            $fields[$field] = $this->extractWithFallback($xpath, $card, $extractors);
        }

        return $fields;
    }

    /**
     * @param  array<int, array<string, mixed>>  $extractors
     */
    private function extractWithFallback(DOMXPath $xpath, DOMNode $card, array $extractors): ?string
    {
        foreach ($extractors as $extractor) {
            $value = $this->extractValue($xpath, $card, $extractor);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $extractor
     */
    private function extractValue(DOMXPath $xpath, DOMNode $card, array $extractor): ?string
    {
        $expression = $this->stringFromMixed($extractor['xpath'] ?? null);

        if ($expression === null) {
            $selector = $this->stringFromMixed($extractor['selector'] ?? null);

            if ($selector === null) {
                return null;
            }

            $expression = $this->selectorToXPath($selector);
        }

        $nodes = $this->queryNodes($xpath, $expression, $card);

        if (! $nodes instanceof DOMNodeList || $nodes->length === 0) {
            return null;
        }

        $node = $nodes->item(0);

        if (! $node instanceof DOMNode) {
            return null;
        }

        $attribute = $this->stringFromMixed($extractor['attribute'] ?? null);
        $mode = strtolower($this->stringFromMixed($extractor['mode'] ?? null) ?? 'text');
        $value = $this->readNodeValue($node, $mode, $attribute);

        return $this->normalizeValue(
            $value,
            decodeEntities: $this->boolFromMixed($extractor['decode_html_entities'] ?? true, true),
            collapseWhitespace: $this->boolFromMixed($extractor['collapse_whitespace'] ?? true, true),
            trimValue: $this->boolFromMixed($extractor['trim'] ?? true, true),
        );
    }

    private function readNodeValue(DOMNode $node, string $mode, ?string $attribute): string
    {
        if ($attribute !== null && $node instanceof DOMElement) {
            return $node->getAttribute($attribute);
        }

        return match ($mode) {
            'html' => $this->innerHtml($node),
            'outer_html' => $this->outerHtml($node),
            default => (string) $node->textContent,
        };
    }

    private function normalizeValue(
        string $value,
        bool $decodeEntities,
        bool $collapseWhitespace,
        bool $trimValue,
    ): ?string {
        if ($decodeEntities) {
            $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        if ($collapseWhitespace) {
            $value = preg_replace('/\\s+/u', ' ', $value) ?? $value;
        }

        if ($trimValue) {
            $value = trim($value);
        }

        return $value !== '' ? $value : null;
    }

    private function selectorToXPath(string $selector): string
    {
        try {
            return $this->selectorConverter->toXPath($selector);
        } catch (Throwable $exception) {
            throw new RuntimeException("Invalid CSS selector: {$selector}", 0, $exception);
        }
    }

    private function queryNodes(DOMXPath $xpath, string $expression, ?DOMNode $context = null): ?DOMNodeList
    {
        if ($context === null || str_starts_with($expression, '/')) {
            $nodes = $xpath->query($expression);
        } else {
            $nodes = $xpath->query($expression, $context);
        }

        return $nodes instanceof DOMNodeList ? $nodes : null;
    }

    /**
     * @return array<int, DOMNode>
     */
    private function nodeListToArray(?DOMNodeList $nodeList): array
    {
        if (! $nodeList instanceof DOMNodeList) {
            return [];
        }

        $nodes = [];

        foreach ($nodeList as $node) {
            if ($node instanceof DOMNode) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    private function outerHtml(DOMNode $node): string
    {
        $ownerDocument = $node->ownerDocument;

        if (! $ownerDocument instanceof DOMDocument) {
            return '';
        }

        $html = $ownerDocument->saveHTML($node);

        return is_string($html) ? trim($html) : '';
    }

    private function innerHtml(DOMNode $node): string
    {
        $ownerDocument = $node->ownerDocument;

        if (! $ownerDocument instanceof DOMDocument) {
            return '';
        }

        $content = '';

        foreach ($node->childNodes as $childNode) {
            $fragment = $ownerDocument->saveHTML($childNode);

            if (is_string($fragment)) {
                $content .= $fragment;
            }
        }

        return trim($content);
    }

    private function ensureUtf8(string $html): string
    {
        if ($html === '' || mb_check_encoding($html, 'UTF-8')) {
            return $html;
        }

        if (function_exists('mb_convert_encoding')) {
            $converted = @mb_convert_encoding($html, 'UTF-8', 'UTF-8,Windows-1251,CP1251,ISO-8859-1');

            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }

        return $html;
    }

    private function stringFromMixed(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function boolFromMixed(mixed $value, bool $default): bool
    {
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
