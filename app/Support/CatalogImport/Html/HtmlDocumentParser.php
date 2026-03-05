<?php

namespace App\Support\CatalogImport\Html;

use App\Support\CatalogImport\Contracts\RecordParserInterface;
use App\Support\CatalogImport\DTO\ResolvedSource;

final class HtmlDocumentParser implements RecordParserInterface
{
    public function __construct(
        private readonly HtmlDomParser $domParser = new HtmlDomParser,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return \Generator<int, HtmlDocumentRecord>
     */
    public function parse(ResolvedSource $source, array $options = []): \Generator
    {
        $url = $this->resolveUrl($source, $options);
        $meta = $this->resolveMeta($options);

        $domOptions = $this->resolveDomOptions($options);

        foreach ($this->domParser->parse($source, $domOptions) as $record) {
            yield new HtmlDocumentRecord(
                url: $url,
                document: $record,
                meta: $meta,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function resolveUrl(ResolvedSource $source, array $options): string
    {
        $url = $options['url'] ?? $source->source;

        if (! is_string($url) || trim($url) === '') {
            return $source->source;
        }

        return trim($url);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function resolveMeta(array $options): array
    {
        $meta = $options['meta'] ?? [];

        return is_array($meta) ? $meta : [];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function resolveDomOptions(array $options): array
    {
        $domOptions = [];

        if (is_string($options['card_selector'] ?? null)) {
            $domOptions['card_selector'] = trim((string) $options['card_selector']);
        }

        if (is_string($options['card_xpath'] ?? null)) {
            $domOptions['card_xpath'] = trim((string) $options['card_xpath']);
        }

        if (! isset($domOptions['card_selector']) && ! isset($domOptions['card_xpath'])) {
            $domOptions['card_xpath'] = '//html';
        }

        if (is_array($options['fields'] ?? null)) {
            $domOptions['fields'] = $options['fields'];
        }

        return $domOptions;
    }
}
