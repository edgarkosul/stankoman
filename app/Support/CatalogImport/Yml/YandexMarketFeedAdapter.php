<?php

namespace App\Support\CatalogImport\Yml;

use App\Support\CatalogImport\Contracts\SupplierAdapterInterface;
use App\Support\CatalogImport\DTO\ImportError;
use App\Support\CatalogImport\DTO\ProductPayload;
use App\Support\CatalogImport\DTO\RecordMappingResult;
use App\Support\CatalogImport\Enums\ImportErrorLevel;
use App\Support\Filament\PdfLinkBlockConfigNormalizer;
use Illuminate\Validation\ValidationException;
use SimpleXMLElement;

final class YandexMarketFeedAdapter implements SupplierAdapterInterface
{
    private const PDF_LINK_BLOCK_ID = 'pdf-link';

    private const RUTUBE_VIDEO_BLOCK_ID = 'rutube-video';

    private const RUTUBE_VIDEO_BLOCK_WIDTH = 640;

    private const RUTUBE_VIDEO_BLOCK_ALIGNMENT = 'center';

    public function __construct(
        private readonly YandexMarketFeedProfile $profile = new YandexMarketFeedProfile,
    ) {}

    public function mapRecord(mixed $record): RecordMappingResult
    {
        if (! $record instanceof YmlOfferRecord) {
            return new RecordMappingResult(
                payload: null,
                errors: [
                    new ImportError(
                        code: 'invalid_record_type',
                        message: 'Ожидался экземпляр YmlOfferRecord.',
                        level: ImportErrorLevel::Fatal,
                    ),
                ],
            );
        }

        return $this->mapOffer($record);
    }

    public function mapOffer(YmlOfferRecord $offer): RecordMappingResult
    {
        $errors = [];

        $externalId = trim($offer->id);

        if ($externalId === '') {
            $errors[] = new ImportError(
                code: 'missing_offer_id',
                message: 'Атрибут offer "id" обязателен.',
            );

            return new RecordMappingResult(payload: null, errors: $errors);
        }

        $xml = $this->loadOfferXml($offer->xml, $errors);

        if ($xml === null) {
            return new RecordMappingResult(payload: null, errors: $errors);
        }

        $offerType = $offer->type;

        if ($offerType === 'vendor.model') {
            return $this->mapVendorModelOffer($externalId, $offer, $xml, $errors);
        }

        return $this->mapSimplifiedOffer($externalId, $offer, $xml, $errors);
    }

    /**
     * @param  array<int, ImportError>  $errors
     */
    private function mapSimplifiedOffer(string $externalId, YmlOfferRecord $offer, SimpleXMLElement $xml, array $errors): RecordMappingResult
    {
        $name = $this->textOrNull($xml->name ?? null);
        $categoryId = $this->textOrNull($xml->categoryId ?? null);
        $priceRaw = $this->textOrNull($xml->price ?? null);
        $currency = $this->resolveCurrency($xml->currencyId ?? null);

        $this->appendRequiredFieldErrors(
            errors: $errors,
            offerType: null,
            values: [
                'name' => $name,
                'price' => $priceRaw,
                'currencyId' => $currency,
                'categoryId' => $categoryId,
            ],
        );

        if ($errors !== []) {
            return new RecordMappingResult(payload: null, errors: $errors);
        }

        $priceAmount = $this->parsePriceAmount($priceRaw);
        $vendor = $this->textOrNull($xml->vendor ?? null);
        $pictures = $this->extractPictures($xml);
        $description = $this->extractDescription($xml, $pictures);
        $offerContent = $this->extractOfferContent($xml);
        $video = $this->extractVideo($xml);
        $errors = [...$errors, ...$offerContent['errors']];
        $resolvedSku = $this->resolveSku($externalId, $name, $xml);

        return new RecordMappingResult(
            payload: new ProductPayload(
                externalId: $externalId,
                name: $name,
                description: $description,
                brand: $vendor,
                priceAmount: $priceAmount,
                currency: $currency,
                inStock: $offer->available,
                images: $pictures,
                attributes: $offerContent['params'],
                sku: $resolvedSku['sku'],
                instructions: $offerContent['instructions'],
                video: $video,
                source: [
                    'supplier' => $this->profile->supplierKey(),
                    'profile' => $this->profile->profileName(),
                    'format' => 'yml',
                    'offer_type' => $offer->type,
                    'category_id' => $categoryId,
                    'sku_source' => $resolvedSku['source'],
                ],
            ),
            errors: $errors,
        );
    }

    /**
     * @param  array<int, ImportError>  $errors
     */
    private function mapVendorModelOffer(string $externalId, YmlOfferRecord $offer, SimpleXMLElement $xml, array $errors): RecordMappingResult
    {
        $typePrefix = $this->textOrNull($xml->typePrefix ?? null);
        $vendor = $this->textOrNull($xml->vendor ?? null);
        $model = $this->textOrNull($xml->model ?? null);
        $fallbackName = $this->textOrNull($xml->name ?? null);
        $resolvedModel = $model ?? $fallbackName;
        $categoryId = $this->textOrNull($xml->categoryId ?? null);
        $priceRaw = $this->textOrNull($xml->price ?? null);
        $currency = $this->resolveCurrency($xml->currencyId ?? null);

        $this->appendRequiredFieldErrors(
            errors: $errors,
            offerType: 'vendor.model',
            values: [
                'vendor' => $vendor,
                'model' => $resolvedModel,
                'price' => $priceRaw,
                'currencyId' => $currency,
                'categoryId' => $categoryId,
            ],
        );

        if ($errors !== []) {
            return new RecordMappingResult(payload: null, errors: $errors);
        }

        $name = $fallbackName;

        if ($model !== null) {
            $nameParts = [];

            if ($typePrefix !== null) {
                $nameParts[] = $typePrefix;
            }

            $nameParts[] = $vendor;
            $nameParts[] = $model;

            $name = implode(' ', $nameParts);
        }

        $priceAmount = $this->parsePriceAmount($priceRaw);
        $pictures = $this->extractPictures($xml);
        $description = $this->extractDescription($xml, $pictures);
        $offerContent = $this->extractOfferContent($xml);
        $video = $this->extractVideo($xml);
        $errors = [...$errors, ...$offerContent['errors']];
        $resolvedSku = $this->resolveSku($externalId, $name, $xml);

        return new RecordMappingResult(
            payload: new ProductPayload(
                externalId: $externalId,
                name: $name,
                description: $description,
                brand: $vendor,
                priceAmount: $priceAmount,
                currency: $currency,
                inStock: $offer->available,
                images: $pictures,
                attributes: $offerContent['params'],
                sku: $resolvedSku['sku'],
                instructions: $offerContent['instructions'],
                video: $video,
                source: [
                    'supplier' => $this->profile->supplierKey(),
                    'profile' => $this->profile->profileName(),
                    'format' => 'yml',
                    'offer_type' => $offer->type,
                    'category_id' => $categoryId,
                    'sku_source' => $resolvedSku['source'],
                ],
            ),
            errors: $errors,
        );
    }

    /**
     * @param  array<int, ImportError>  $errors
     */
    private function loadOfferXml(string $xml, array &$errors): ?SimpleXMLElement
    {
        $xml = trim($xml);

        if ($xml === '') {
            $errors[] = new ImportError(
                code: 'empty_offer_xml',
                message: 'XML offer-записи пуст.',
            );

            return null;
        }

        $prev = libxml_use_internal_errors(true);

        try {
            $node = simplexml_load_string($xml);

            if (! $node) {
                $errors[] = new ImportError(
                    code: 'invalid_offer_xml',
                    message: 'XML offer-записи не является корректным XML-документом.',
                );

                return null;
            }

            return $node;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
    }

    private function textOrNull(mixed $value): ?string
    {
        if ($value instanceof SimpleXMLElement) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = preg_replace('/\\s+/u', ' ', $value) ?? $value;
        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function parsePriceAmount(?string $raw): ?int
    {
        if ($raw === null) {
            return null;
        }

        $normalized = str_replace(["\xc2\xa0", ' '], '', $raw);
        $normalized = str_replace(',', '.', $normalized);

        if ($normalized === '' || ! is_numeric($normalized)) {
            return null;
        }

        return (int) round((float) $normalized);
    }

    /**
     * @param  array<int, string>  $pictures
     */
    private function extractDescription(SimpleXMLElement $xml, array $pictures): ?string
    {
        $description = $this->textOrNull($xml->description ?? null);

        if ($description === null || ! str_contains($description, '<img')) {
            return $description;
        }

        $baseUrl = $this->baseUrlFromPictures($pictures);

        if ($baseUrl === null) {
            return $description;
        }

        return preg_replace_callback(
            '/(<img\b[^>]*\bsrc\s*=\s*)(["\']?)([^"\'>\s]+)(\2)/iu',
            fn (array $matches): string => $matches[1]
                .$matches[2]
                .$this->resolveRelativeUrl($matches[3], $baseUrl)
                .$matches[4],
            $description,
        ) ?? $description;
    }

    private function resolveCurrency(mixed $value): ?string
    {
        $currency = $this->textOrNull($value);

        if ($currency !== null) {
            return $currency;
        }

        return $this->textOrNull($this->profile->defaults()['currency'] ?? null);
    }

    private function extractVideo(SimpleXMLElement $xml): ?string
    {
        $videoBlocks = [];
        $seen = [];

        foreach ($xml->video as $videoNode) {
            $value = $this->textOrNull($videoNode);

            if ($value === null) {
                continue;
            }

            foreach ($this->extractRutubeIds($value) as $rutubeId) {
                $key = mb_strtolower($rutubeId);

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $videoBlocks[] = $this->buildRutubeVideoBlock($rutubeId);
            }
        }

        if ($videoBlocks === []) {
            return null;
        }

        return implode('', $videoBlocks);
    }

    /**
     * @return array<int, string>
     */
    private function extractRutubeIds(string $value): array
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');

        $matched = preg_match_all(
            '~https?://(?:www\.)?rutube\.ru/(?:video|play/embed)/([A-Za-z0-9]+)(?:[/?#][^\s<>"\']*)?~iu',
            $value,
            $matches,
        );

        if (! is_int($matched) || $matched < 1) {
            return [];
        }

        $ids = [];

        foreach ($matches[1] ?? [] as $rutubeId) {
            if (! is_string($rutubeId)) {
                continue;
            }

            $rutubeId = trim($rutubeId);

            if ($rutubeId === '') {
                continue;
            }

            $ids[] = $rutubeId;
        }

        return $ids;
    }

    private function buildRutubeVideoBlock(string $rutubeId): string
    {
        return $this->buildCustomBlock(self::RUTUBE_VIDEO_BLOCK_ID, [
            'rutube_id' => $rutubeId,
            'width' => self::RUTUBE_VIDEO_BLOCK_WIDTH,
            'alignment' => self::RUTUBE_VIDEO_BLOCK_ALIGNMENT,
        ]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function buildCustomBlock(string $blockId, array $config): string
    {
        $config = json_encode($config, JSON_UNESCAPED_UNICODE);

        if (! is_string($config) || $config === '') {
            return '';
        }

        return sprintf(
            '<div data-type="customBlock" data-config="%s" data-id="%s"></div>',
            htmlspecialchars($config, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $blockId,
        );
    }

    /**
     * @param  array<int, string>  $pictures
     */
    private function baseUrlFromPictures(array $pictures): ?string
    {
        foreach ($pictures as $picture) {
            $parts = parse_url($picture);

            if (! is_array($parts)) {
                continue;
            }

            $scheme = $parts['scheme'] ?? null;
            $host = $parts['host'] ?? null;

            if (! is_string($scheme) || ! is_string($host) || $scheme === '' || $host === '') {
                continue;
            }

            $authority = $scheme.'://'.$host;
            $port = $parts['port'] ?? null;

            if (is_int($port)) {
                $authority .= ':'.$port;
            }

            $path = isset($parts['path']) && is_string($parts['path']) ? $parts['path'] : '/';
            $directory = rtrim(str_replace('\\', '/', dirname($path)), '/');

            return $directory === '' || $directory === '.'
                ? $authority.'/'
                : $authority.$directory.'/';
        }

        return null;
    }

    /**
     * @return array{sku: string, source: string}
     */
    private function resolveSku(string $externalId, string $name, SimpleXMLElement $xml): array
    {
        $paramSku = $this->findSkuInParams($xml);
        $shopSku = $this->textOrNull($xml->{'shop-sku'} ?? null);
        $vendorCode = $this->textOrNull($xml->vendorCode ?? null);

        $candidates = [
            ['source' => 'shop-sku', 'value' => $shopSku],
            ['source' => 'vendorCode', 'value' => $vendorCode],
            ['source' => 'offer-id', 'value' => $externalId],
            ['source' => 'param', 'value' => $paramSku],
        ];

        foreach ($candidates as $candidate) {
            $normalized = $this->normalizeSku($candidate['value']);

            if ($normalized === null) {
                continue;
            }

            return [
                'sku' => $normalized,
                'source' => $candidate['source'],
            ];
        }

        return [
            'sku' => $this->generateFallbackSku($externalId, $name),
            'source' => 'generated',
        ];
    }

    private function normalizeSku(?string $value): ?string
    {
        $value = $this->textOrNull($value);

        if ($value === null) {
            return null;
        }

        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
        $value = preg_replace('/\s+/u', '-', $value) ?? $value;
        $value = preg_replace('/[^\p{L}\p{N}\-._\/]+/u', '', $value) ?? $value;
        $value = preg_replace('/-{2,}/', '-', $value) ?? $value;
        $value = trim($value, "-._/\t\n\r\0\x0B ");

        if ($value === '') {
            return null;
        }

        return mb_strtoupper($value);
    }

    private function generateFallbackSku(string $externalId, string $name): string
    {
        $hash = strtoupper(substr(hash('sha256', $externalId.'|'.$name), 0, 12));

        return 'YML-'.$hash;
    }

    private function findSkuInParams(SimpleXMLElement $xml): ?string
    {
        $supportedNames = [
            'артикул',
            'sku',
            'код товара',
            'vendor code',
            'vendorcode',
            'part number',
            'partnumber',
        ];
        $lookup = array_fill_keys($supportedNames, true);

        foreach ($xml->param as $paramNode) {
            $name = $this->textOrNull((string) ($paramNode['name'] ?? ''));

            if ($name === null) {
                continue;
            }

            $normalizedName = $this->normalizeParamName($name);

            if (! isset($lookup[$normalizedName])) {
                continue;
            }

            $value = $this->textOrNull($paramNode);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function normalizeParamName(string $name): string
    {
        $name = mb_strtolower($name);
        $name = preg_replace('/[_\-]+/u', ' ', $name) ?? $name;
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;

        return trim($name);
    }

    private function resolveRelativeUrl(string $value, string $baseUrl): string
    {
        $value = trim($value);

        if (
            $value === ''
            || preg_match('/^(?:[a-z][a-z0-9+\-.]*:)?\/\//iu', $value) === 1
            || str_starts_with($value, 'data:')
            || str_starts_with($value, 'mailto:')
            || str_starts_with($value, 'tel:')
            || str_starts_with($value, '#')
        ) {
            return $value;
        }

        $parts = parse_url($baseUrl);

        if (! is_array($parts)) {
            return $value;
        }

        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;

        if (! is_string($scheme) || ! is_string($host) || $scheme === '' || $host === '') {
            return $value;
        }

        $authority = $scheme.'://'.$host;
        $port = $parts['port'] ?? null;

        if (is_int($port)) {
            $authority .= ':'.$port;
        }

        if (str_starts_with($value, '/')) {
            return $authority.$value;
        }

        return rtrim($baseUrl, '/').'/'.$value;
    }

    /**
     * @param  array<int, ImportError>  $errors
     * @param  array<string, string|null>  $values
     */
    private function appendRequiredFieldErrors(array &$errors, ?string $offerType, array $values): void
    {
        if (($this->profile->defaults()['strict_required_fields'] ?? false) !== true) {
            return;
        }

        foreach ($this->profile->requiredFields($offerType) as $field) {
            $value = $values[$field] ?? null;

            if (is_string($value) && trim($value) !== '') {
                continue;
            }

            $errors[] = new ImportError(
                code: 'missing_required_'.$field,
                message: sprintf('В offer-записи отсутствует обязательное поле <%s>.', $field),
            );
        }
    }

    /**
     * @return array<int, string>
     */
    private function extractPictures(SimpleXMLElement $xml): array
    {
        $pictures = [];
        $seen = [];

        foreach ($xml->picture as $pictureNode) {
            $picture = $this->textOrNull($pictureNode);

            if ($picture === null) {
                continue;
            }

            $key = mb_strtolower($picture);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $pictures[] = $picture;
        }

        return $pictures;
    }

    /**
     * @return array{params: array<int, array{name:string,value:string,source:string}>, instructions: ?string, errors: array<int, ImportError>}
     */
    private function extractOfferContent(SimpleXMLElement $xml): array
    {
        $params = [];
        $seenParams = [];
        $documents = [];
        $seenDocuments = [];

        foreach ($xml->param as $paramNode) {
            $name = $this->textOrNull((string) ($paramNode['name'] ?? ''));
            $value = $this->textOrNull($paramNode);

            if ($name === null || $value === null) {
                continue;
            }

            $unit = $this->textOrNull((string) ($paramNode['unit'] ?? ''));

            if ($unit !== null) {
                $value .= ' '.$unit;
            }

            $pdfUrls = $this->extractPdfUrls($name."\n".$value);

            if ($pdfUrls !== []) {
                $this->collectPdfDocuments(
                    documents: $documents,
                    seenDocuments: $seenDocuments,
                    urls: $pdfUrls,
                    linkText: count($pdfUrls) === 1 ? $name : null,
                );

                continue;
            }

            $key = mb_strtolower($name.'::'.$value);

            if (isset($seenParams[$key])) {
                continue;
            }

            $seenParams[$key] = true;
            $params[] = [
                'name' => $name,
                'value' => $value,
                'source' => 'yml',
            ];
        }

        $this->collectPdfDocuments(
            documents: $documents,
            seenDocuments: $seenDocuments,
            urls: $this->extractPdfUrls((string) $xml->asXML()),
        );

        $errors = [];
        $instructions = $this->buildPdfInstructionBlocks($documents, $errors);

        return [
            'params' => $params,
            'instructions' => $instructions,
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<int, array{url:string, link_text:?string}>  $documents
     * @param  array<string, bool>  $seenDocuments
     * @param  array<int, string>  $urls
     */
    private function collectPdfDocuments(
        array &$documents,
        array &$seenDocuments,
        array $urls,
        ?string $linkText = null,
    ): void {
        $normalizedLinkText = $this->normalizePdfLinkText($linkText);

        foreach ($urls as $url) {
            $key = mb_strtolower($url);

            if (isset($seenDocuments[$key])) {
                continue;
            }

            $seenDocuments[$key] = true;
            $documents[] = [
                'url' => $url,
                'link_text' => $normalizedLinkText,
            ];
        }
    }

    /**
     * @return array<int, string>
     */
    private function extractPdfUrls(string $value): array
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');

        $matched = preg_match_all(
            '~https?://[^\r\n<>"\']+?\.pdf(?:\?[^\r\n<>"\']*)?(?:#[^\r\n<>"\']*)?~iu',
            $value,
            $matches,
        );

        if (! is_int($matched) || $matched < 1) {
            return [];
        }

        $urls = [];
        $seen = [];

        foreach ($matches[0] ?? [] as $url) {
            if (! is_string($url)) {
                continue;
            }

            $url = trim($url);

            if ($url === '') {
                continue;
            }

            $key = mb_strtolower($url);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $urls[] = $url;
        }

        return $urls;
    }

    /**
     * @param  array<int, array{url:string, link_text:?string}>  $documents
     * @param  array<int, ImportError>  $errors
     */
    private function buildPdfInstructionBlocks(array $documents, array &$errors): ?string
    {
        if ($documents === []) {
            return null;
        }

        $blocks = [];

        foreach ($documents as $document) {
            $block = $this->buildPdfInstructionBlock(
                url: $document['url'],
                linkText: $document['link_text'],
                errors: $errors,
            );

            if ($block === null) {
                continue;
            }

            $blocks[] = $block;
        }

        return $blocks === [] ? null : implode('', $blocks);
    }

    /**
     * @param  array<int, ImportError>  $errors
     */
    private function buildPdfInstructionBlock(string $url, ?string $linkText, array &$errors): ?string
    {
        try {
            $config = app(PdfLinkBlockConfigNormalizer::class)->normalize([
                'source_type' => PdfLinkBlockConfigNormalizer::SOURCE_DOWNLOAD_URL,
                'target' => PdfLinkBlockConfigNormalizer::TARGET_NEW_TAB,
                'url' => $url,
                'link_text' => $linkText,
            ]);
        } catch (ValidationException $exception) {
            $errors[] = new ImportError(
                code: 'pdf_instruction_import_failed',
                message: 'Не удалось подготовить PDF-документ для инструкции: '.$this->validationExceptionMessage($exception),
                context: ['url' => $url],
            );

            return null;
        } catch (\Throwable) {
            $errors[] = new ImportError(
                code: 'pdf_instruction_import_failed',
                message: 'Не удалось подготовить PDF-документ для инструкции.',
                context: ['url' => $url],
            );

            return null;
        }

        $block = $this->buildCustomBlock(self::PDF_LINK_BLOCK_ID, $config);

        if ($block !== '') {
            return $block;
        }

        $errors[] = new ImportError(
            code: 'pdf_instruction_block_build_failed',
            message: 'Не удалось собрать rich-content блок PDF-документа.',
            context: ['url' => $url],
        );

        return null;
    }

    private function normalizePdfLinkText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $this->textOrNull(
            html_entity_decode($value, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8'),
        );
    }

    private function validationExceptionMessage(ValidationException $exception): string
    {
        foreach ($exception->errors() as $messages) {
            foreach ($messages as $message) {
                if (is_string($message) && $message !== '') {
                    return $message;
                }
            }
        }

        return $exception->getMessage() !== '' ? $exception->getMessage() : 'Некорректный PDF URL.';
    }
}
