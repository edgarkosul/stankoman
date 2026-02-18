<?php

namespace App\Support\Metalmaster;

use DOMDocument;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Str;
use RuntimeException;

class MetalmasterProductParser
{
    public function parse(string $html, string $url, string $bucket): array
    {
        return $this->parseProductPage($html, $url, $bucket);
    }

    private function parseProductPage(string $html, string $url, string $bucket): array
    {
        [, $xpath] = $this->makeDom($html);

        $jsonLdObjects = $this->extractJsonLdObjects($xpath);
        $productJson = $this->findProductJsonLd($jsonLdObjects);

        if ($productJson === []) {
            throw new RuntimeException('JSON-LD Product not found.');
        }

        $slug = $this->extractSlugFromUrl($url);

        $h1 = $this->firstText($xpath, '//h1');
        $metaTitle = $this->metaContent($xpath, 'title') ?: $this->metaProperty($xpath, 'og:title');
        $metaDescription = $this->metaContent($xpath, 'description') ?: $this->metaProperty($xpath, 'og:description');

        $name = $this->stringOrNull($productJson['name'] ?? null) ?: $h1 ?: Str::title(str_replace('-', ' ', $slug));
        $title = $h1 ?: $name;

        $description = $this->stringOrNull($productJson['description'] ?? null)
            ?: $this->extractDescriptionFromDom($xpath)
            ?: null;

        $specsJsonLd = $this->extractSpecsFromJsonLd($productJson);
        $specsDom = $this->extractSpecsFromDom(
            $xpath,
            implode(' ', array_filter([$name, $title, $h1, $metaTitle, $slug]))
        );

        $specs = $this->dedupeSpecs(array_merge($specsJsonLd, $specsDom));

        $ogImage = $this->resolveOgImage($xpath, $url);
        $gallery = $this->extractGallery($xpath, $productJson, $url, $ogImage);
        $image = $ogImage ?? ($gallery[0] ?? null);
        $thumb = $image;

        [$priceAmount, $currency, $discountPrice] = $this->extractPrices($xpath, $productJson);
        [$inStock, $qty] = $this->extractStock($xpath, $productJson, $html);

        return [
            'name' => $name,
            'name_normalized' => $this->normalizeName($name),
            'title' => $title,
            'slug' => $slug,

            'sku' => $this->extractSku($xpath, $specs),
            'brand' => $this->extractBrand($productJson, $specs),
            'country' => $this->extractCountry($productJson, $specs),

            'price_amount' => $priceAmount,
            'discount_price' => $discountPrice,
            'currency' => $currency ?: 'RUB',
            'in_stock' => $inStock,
            'qty' => $qty,

            'short' => $description ? Str::limit(trim(strip_tags($description)), 500) : null,
            'description' => $description,
            'extra_description' => null,

            'specs' => $specs === [] ? null : $specs,

            'promo_info' => null,

            'image' => $image,
            'thumb' => $thumb,
            'gallery' => $gallery === [] ? null : $gallery,

            'meta_title' => $metaTitle ?: $title,
            'meta_description' => $metaDescription ?: ($description ? Str::limit(trim(strip_tags($description)), 250) : null),
        ];
    }

    private function makeDom(string $html): array
    {
        libxml_use_internal_errors(true);

        $dom = new DOMDocument('1.0', 'UTF-8');
        $html = $this->ensureUtf8($html);

        // важно: добавляем xml пролог для корректной UTF-8 загрузки
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
        $xpath = new DOMXPath($dom);

        return [$dom, $xpath];
    }

    private function ensureUtf8(string $html): string
    {
        if (mb_detect_encoding($html, 'UTF-8', true) !== false) {
            return $html;
        }

        return mb_convert_encoding($html, 'UTF-8', 'Windows-1251,CP1251,ISO-8859-1,UTF-8');
    }

    private function extractJsonLdObjects(DOMXPath $xpath): array
    {
        $result = [];

        $nodes = $xpath->query('//script[@type="application/ld+json"]');
        if (! $nodes) {
            return $result;
        }

        foreach ($nodes as $node) {
            $raw = trim((string) $node->textContent);
            if ($raw === '') {
                continue;
            }

            $decoded = $this->tryDecodeJson($raw);
            if ($decoded === null) {
                continue;
            }

            $items = $this->flattenJsonLd($decoded);
            foreach ($items as $item) {
                if (is_array($item)) {
                    $result[] = $item;
                }
            }
        }

        return $result;
    }

    private function tryDecodeJson(string $raw): mixed
    {
        $raw = trim($raw);

        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        $decoded = json_decode(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        $clean = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $clean);

        $decoded = json_decode((string) $clean, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        return null;
    }

    private function flattenJsonLd(mixed $decoded): array
    {
        if (! is_array($decoded)) {
            return [];
        }

        // @graph
        if (array_key_exists('@graph', $decoded) && is_array($decoded['@graph'])) {
            return array_values($decoded['@graph']);
        }

        // list of entities
        if (array_is_list($decoded)) {
            return $decoded;
        }

        // single object
        return [$decoded];
    }

    private function findProductJsonLd(array $objects): array
    {
        foreach ($objects as $obj) {
            $type = $obj['@type'] ?? null;

            if (is_string($type) && mb_strtolower($type) === 'product') {
                return $obj;
            }

            if (is_array($type)) {
                $types = array_map(fn ($v) => mb_strtolower((string) $v), $type);
                if (in_array('product', $types, true)) {
                    return $obj;
                }
            }
        }

        return [];
    }

    private function extractSpecsFromJsonLd(array $productJson): array
    {
        $specs = [];
        $props = $productJson['additionalProperty'] ?? null;

        if (! is_array($props)) {
            return $specs;
        }

        foreach ($props as $p) {
            if (! is_array($p)) {
                continue;
            }

            $name = $this->cleanSpecName((string) ($p['name'] ?? ''));
            $value = trim((string) ($p['value'] ?? ''));

            if ($name === '' || $value === '') {
                continue;
            }

            $specs[] = [
                'name' => $name,
                'value' => $value,
                'source' => 'jsonld',
            ];
        }

        return $specs;
    }

    private function extractSpecsFromDom(DOMXPath $xpath, string $modelContext): array
    {
        $specs = [];
        $specTables = $this->specificationTables($xpath);
        $modelTableExtraction = $this->extractSpecsFromModelTables($xpath, $modelContext, $specTables);

        // 1) dt/dd пары (как на vactool)
        $dtNodes = $xpath->query("//dt[contains(concat(' ', normalize-space(@class), ' '), ' list-props__title ')]");
        if ($dtNodes) {
            foreach ($dtNodes as $dt) {
                $name = $this->cleanSpecName($this->nodeText($dt));
                if ($name === '') {
                    continue;
                }

                $value = trim((string) $xpath->evaluate(
                    "string(following-sibling::dd[contains(concat(' ', normalize-space(@class), ' '), ' list-props__value ')][1])",
                    $dt
                ));

                if ($value === '') {
                    continue;
                }

                $specs[] = [
                    'name' => $name,
                    'value' => $value,
                    'source' => 'dom',
                ];
            }
        }

        $specs = array_merge($specs, $modelTableExtraction['specs']);

        // 2) generic table tr td/th fallback (кроме уже обработанных model-таблиц)
        foreach ($specTables as $table) {
            $tablePath = $table->getNodePath();

            if ($tablePath !== null && isset($modelTableExtraction['table_paths'][$tablePath])) {
                continue;
            }

            foreach ($this->tableRows($xpath, $table) as $row) {
                $cells = $xpath->query('./th|./td', $row);

                if (! $cells || $cells->length < 2) {
                    continue;
                }

                $name = $this->cleanSpecName($this->nodeText($cells->item(0)));
                $value = trim($this->nodeText($cells->item(1)));

                if ($name === '' || $value === '') {
                    continue;
                }

                if ($this->isDecorativeSpecRow($name, $value)) {
                    continue;
                }

                if (mb_strlen($name) > 120 || mb_strlen($value) > 2000) {
                    continue;
                }

                $specs[] = [
                    'name' => $name,
                    'value' => $value,
                    'source' => 'dom',
                ];
            }
        }

        return $specs;
    }

    /**
     * @param  array<int, DOMNode>  $tables
     * @return array{
     *     specs: array<int, array{name: string, value: string, source: string}>,
     *     table_paths: array<string, true>
     * }
     */
    private function extractSpecsFromModelTables(DOMXPath $xpath, string $modelContext, array $tables): array
    {
        $specs = [];
        $tablePaths = [];

        foreach ($tables as $table) {
            $rows = $this->tableRows($xpath, $table);

            if (count($rows) < 2) {
                continue;
            }

            $headerCells = $xpath->query('./th|./td', $rows[0]);
            if (! $headerCells || $headerCells->length < 2) {
                continue;
            }

            $firstHeaderText = mb_strtolower($this->cleanSpecName($this->nodeText($headerCells->item(0))));
            if (! Str::contains($firstHeaderText, ['модель', 'model'])) {
                continue;
            }

            $tablePath = $table->getNodePath();

            if (is_string($tablePath) && $tablePath !== '') {
                $tablePaths[$tablePath] = true;
            }

            $columnsByModelLabel = [];
            $column = 1;

            foreach ($headerCells as $headerCell) {
                $label = $this->cleanSpecName($this->nodeText($headerCell));
                $colspan = max(1, (int) ($headerCell->attributes?->getNamedItem('colspan')?->nodeValue ?? 1));

                for ($offset = 0; $offset < $colspan; $offset++) {
                    if ($column > 1 && $label !== '') {
                        $columnsByModelLabel[$column] = $label;
                    }

                    $column++;
                }
            }

            $targetColumns = $this->resolveModelColumns($columnsByModelLabel, $modelContext);
            if ($targetColumns === []) {
                continue;
            }

            $tableGrid = $this->buildTableGrid($xpath, $table);
            if (count($tableGrid) < 2) {
                continue;
            }

            foreach (array_slice($tableGrid, 1) as $rowGrid) {
                $name = $this->cleanSpecName((string) ($rowGrid[1] ?? ''));
                if ($name === '' || mb_strtolower($name) === 'модель') {
                    continue;
                }

                $values = [];

                foreach ($targetColumns as $targetColumn) {
                    $value = trim((string) ($rowGrid[$targetColumn] ?? ''));

                    if ($value === '' || mb_strtolower($value) === mb_strtolower($name)) {
                        continue;
                    }

                    $values[mb_strtolower($value)] = $value;
                }

                if ($values === []) {
                    continue;
                }

                $value = implode(' / ', array_values($values));

                if (mb_strlen($name) > 120 || mb_strlen($value) > 2000) {
                    continue;
                }

                $specs[] = [
                    'name' => $name,
                    'value' => $value,
                    'source' => 'dom',
                ];
            }

        }

        return [
            'specs' => $specs,
            'table_paths' => $tablePaths,
        ];
    }

    /**
     * @return array<int, DOMNode>
     */
    private function specificationTables(DOMXPath $xpath): array
    {
        $tables = [];
        $seenPaths = [];

        $specTables = $xpath->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' wrapper-characteristics ')]//table");

        if ($specTables && $specTables->length > 0) {
            foreach ($specTables as $table) {
                $path = $table->getNodePath();

                if (! is_string($path) || $path === '' || isset($seenPaths[$path])) {
                    continue;
                }

                $seenPaths[$path] = true;
                $tables[] = $table;
            }

            if ($tables !== []) {
                return $tables;
            }
        }

        $fallbackTables = $xpath->query('//table');

        if (! $fallbackTables) {
            return [];
        }

        foreach ($fallbackTables as $table) {
            $tables[] = $table;
        }

        return $tables;
    }

    /**
     * @param  array<int, string>  $columnsByModelLabel
     * @return array<int, int>
     */
    private function resolveModelColumns(array $columnsByModelLabel, string $modelContext): array
    {
        if ($columnsByModelLabel === []) {
            return [];
        }

        $labels = array_values(array_unique(array_filter($columnsByModelLabel, fn (string $label): bool => $label !== '')));

        if ($labels === []) {
            return [];
        }

        if (count($labels) === 1) {
            $singleLabel = $labels[0];

            return array_values(array_keys($columnsByModelLabel, $singleLabel, true));
        }

        $normalizedContext = $this->normalizeModelMatchValue($modelContext);
        $bestLabel = null;
        $bestScore = 0;

        foreach ($labels as $label) {
            $normalizedLabel = $this->normalizeModelMatchValue($label);
            if ($normalizedLabel === '') {
                continue;
            }

            $score = 0;

            if ($normalizedContext !== '' && Str::contains($normalizedContext, $normalizedLabel)) {
                $score += 100 + mb_strlen($normalizedLabel);
            }

            foreach ($this->tokenizeModelMatchValue($label) as $token) {
                if (mb_strlen($token) < 3) {
                    continue;
                }

                if (Str::contains($normalizedContext, $token)) {
                    $score += mb_strlen($token);
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestLabel = $label;
            }
        }

        if ($bestLabel === null || $bestScore <= 0) {
            return [];
        }

        return array_values(array_keys($columnsByModelLabel, $bestLabel, true));
    }

    /**
     * @return array<int, DOMNode>
     */
    private function tableRows(DOMXPath $xpath, DOMNode $table): array
    {
        $rows = $xpath->query('./thead/tr|./tbody/tr|./tfoot/tr|./tr', $table);

        if (! $rows) {
            return [];
        }

        $result = [];

        foreach ($rows as $row) {
            $result[] = $row;
        }

        return $result;
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function buildTableGrid(DOMXPath $xpath, DOMNode $table): array
    {
        $rows = $this->tableRows($xpath, $table);
        $gridRows = [];

        /** @var array<int, array{rows_left: int, text: string}> $activeRowSpans */
        $activeRowSpans = [];

        foreach ($rows as $row) {
            $grid = [];

            foreach (array_keys($activeRowSpans) as $column) {
                $grid[$column] = $activeRowSpans[$column]['text'];
                $activeRowSpans[$column]['rows_left']--;

                if ($activeRowSpans[$column]['rows_left'] <= 0) {
                    unset($activeRowSpans[$column]);
                }
            }

            $column = 1;
            $cells = $xpath->query('./th|./td', $row);

            if (! $cells) {
                $gridRows[] = $grid;

                continue;
            }

            foreach ($cells as $cell) {
                while (array_key_exists($column, $grid)) {
                    $column++;
                }

                $text = trim($this->nodeText($cell));
                $colspan = max(1, (int) ($cell->attributes?->getNamedItem('colspan')?->nodeValue ?? 1));
                $rowspan = max(1, (int) ($cell->attributes?->getNamedItem('rowspan')?->nodeValue ?? 1));

                for ($offset = 0; $offset < $colspan; $offset++) {
                    $targetColumn = $column + $offset;
                    $grid[$targetColumn] = $text;

                    if ($rowspan > 1) {
                        $activeRowSpans[$targetColumn] = [
                            'rows_left' => $rowspan - 1,
                            'text' => $text,
                        ];
                    }
                }

                $column += $colspan;
            }

            ksort($grid);
            $gridRows[] = $grid;
        }

        return $gridRows;
    }

    private function normalizeModelMatchValue(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = str_replace('ё', 'е', $value);

        return (string) preg_replace('/[^a-zа-я0-9]+/iu', '', $value);
    }

    /**
     * @return array<int, string>
     */
    private function tokenizeModelMatchValue(string $value): array
    {
        preg_match_all('/[a-zа-я0-9]+/iu', mb_strtolower($value), $matches);

        $tokens = $matches[0] ?? [];

        return array_values(array_filter(array_map(
            fn (string $token): string => trim($token),
            $tokens
        ), fn (string $token): bool => $token !== ''));
    }

    private function isDecorativeSpecRow(string $name, string $value): bool
    {
        $normalizedName = mb_strtolower($this->cleanSpecName($name), 'UTF-8');
        $normalizedValue = mb_strtolower(trim($value), 'UTF-8');

        if (in_array($normalizedName, ['характеристики', 'спецификации', 'спецификация', 'параметры'], true)) {
            return true;
        }

        if ($normalizedName === 'модель' && $normalizedValue !== '') {
            return true;
        }

        return false;
    }

    private function dedupeSpecs(array $specs): array
    {
        $map = [];

        foreach ($specs as $s) {
            $name = $this->cleanSpecName((string) ($s['name'] ?? ''));
            $value = trim((string) ($s['value'] ?? ''));
            $source = (string) ($s['source'] ?? 'dom');

            if ($name === '' || $value === '') {
                continue;
            }

            $k = mb_strtolower($name).'::'.mb_strtolower($value);

            // при дубле сохраняем запись jsonld приоритетнее dom
            if (! isset($map[$k]) || ($map[$k]['source'] === 'dom' && $source === 'jsonld')) {
                $map[$k] = [
                    'name' => $name,
                    'value' => $value,
                    'source' => $source,
                ];
            }
        }

        return array_values($map);
    }

    private function extractGallery(DOMXPath $xpath, array $productJson, string $baseUrl, ?string $ogImage = null): array
    {
        $gallery = [];

        if ($ogImage !== null) {
            $gallery[] = $ogImage;
        }

        foreach ($this->extractDomProductGalleryLinks($xpath, $baseUrl) as $domImage) {
            $gallery[] = $domImage;
        }

        if ($gallery === []) {
            $image = $productJson['image'] ?? null;
            foreach ($this->flattenImageField($image) as $img) {
                $abs = $this->absoluteUrl($baseUrl, $img);
                if (! $abs || ! $this->isLikelyImageUrl($abs)) {
                    continue;
                }

                $gallery[] = $abs;
            }
        }

        if ($gallery === []) {
            $domMain = $xpath->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' product__img ')]//a[@href]");
            if ($domMain) {
                foreach ($domMain as $anchorNode) {
                    $href = trim((string) ($anchorNode->attributes?->getNamedItem('href')?->nodeValue ?? ''));
                    if ($href === '' || Str::startsWith(mb_strtolower($href), 'javascript:')) {
                        continue;
                    }

                    $abs = $this->absoluteUrl($baseUrl, $href);
                    if (! $abs || ! $this->isLikelyImageUrl($abs)) {
                        continue;
                    }

                    $gallery[] = $abs;
                }
            }
        }

        $gallery = array_values(array_unique($gallery));

        // ограничим, чтобы не тащить лишнее
        return array_slice($gallery, 0, 40);
    }

    /**
     * @return array<int, string>
     */
    private function extractDomProductGalleryLinks(DOMXPath $xpath, string $baseUrl): array
    {
        $gallery = [];
        $links = $xpath->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' top__product ')]//a[contains(concat(' ', normalize-space(@class), ' '), ' fancybox ')][@href]");

        if (! $links) {
            return $gallery;
        }

        foreach ($links as $linkNode) {
            $href = trim((string) ($linkNode->attributes?->getNamedItem('href')?->nodeValue ?? ''));
            if ($href === '' || Str::startsWith(mb_strtolower($href), 'javascript:')) {
                continue;
            }

            $dataFancybox = mb_strtolower(trim((string) ($linkNode->attributes?->getNamedItem('data-fancybox')?->nodeValue ?? '')));
            if ($dataFancybox !== '' && ! preg_match('/^img_gal\d*$/u', $dataFancybox)) {
                continue;
            }

            $dataType = mb_strtolower(trim((string) ($linkNode->attributes?->getNamedItem('data-type')?->nodeValue ?? '')));
            $fancyboxType = mb_strtolower(trim((string) ($linkNode->attributes?->getNamedItem('data-fancybox-type')?->nodeValue ?? '')));
            if (Str::contains($dataType, 'iframe') || Str::contains($fancyboxType, 'iframe')) {
                continue;
            }

            $abs = $this->absoluteUrl($baseUrl, $href);
            if (! $abs || ! $this->isLikelyImageUrl($abs)) {
                continue;
            }

            $gallery[] = $abs;
        }

        return array_values(array_unique($gallery));
    }

    private function resolveOgImage(DOMXPath $xpath, string $baseUrl): ?string
    {
        $ogImage = $this->metaProperty($xpath, 'og:image');
        if (! is_string($ogImage) || trim($ogImage) === '') {
            return null;
        }

        $abs = $this->absoluteUrl($baseUrl, $ogImage);
        if (! $abs || ! $this->isLikelyImageUrl($abs)) {
            return null;
        }

        return $abs;
    }

    private function flattenImageField(mixed $image): array
    {
        $out = [];

        if (is_string($image)) {
            return [$image];
        }

        if (is_array($image)) {
            foreach ($image as $v) {
                if (is_string($v)) {
                    $out[] = $v;
                } elseif (is_array($v)) {
                    if (isset($v['url']) && is_string($v['url'])) {
                        $out[] = $v['url'];
                    }
                }
            }
        }

        return $out;
    }

    private function extractPrices(DOMXPath $xpath, array $productJson): array
    {
        $priceAmount = null;
        $currency = null;
        $discountPrice = null;

        $offers = $productJson['offers'] ?? null;
        if (is_array($offers)) {
            // offers может быть объектом либо списком
            if (array_is_list($offers)) {
                $offers = $offers[0] ?? [];
            }

            $rawPrice = $offers['price'] ?? null;
            $rawCurrency = $offers['priceCurrency'] ?? null;

            if ($rawPrice !== null && is_numeric((string) $rawPrice)) {
                $priceAmount = (int) round((float) $rawPrice);
            }

            if (is_string($rawCurrency) && $rawCurrency !== '') {
                $currency = mb_strtoupper($rawCurrency);
            }
        }

        // DOM fallback: первое число перед ₽
        if ($priceAmount === null) {
            $text = $this->nodeText($xpath->query('//body')?->item(0));
            if (preg_match('/(\d[\d\s]{2,})\s*₽/u', $text, $m)) {
                $num = (int) preg_replace('/\D+/', '', $m[1]);
                if ($num > 0) {
                    $priceAmount = $num;
                }
            }
        }

        // Попытка вытащить old price из DOM классов
        $oldPriceNodes = $xpath->query("//*[contains(translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'old') and contains(text(),'₽')]");
        if ($oldPriceNodes && $oldPriceNodes->length > 0) {
            $txt = $this->nodeText($oldPriceNodes->item(0));
            if (preg_match('/(\d[\d\s]{2,})/u', $txt, $m)) {
                $num = (int) preg_replace('/\D+/', '', $m[1]);
                if ($num > 0) {
                    $discountPrice = $num;
                }
            }
        }

        return [$priceAmount, $currency, $discountPrice];
    }

    private function extractStock(DOMXPath $xpath, array $productJson, string $html): array
    {
        $inStock = 1; // default as in your schema
        $qty = null;

        $offers = $productJson['offers'] ?? null;
        if (is_array($offers)) {
            if (array_is_list($offers)) {
                $offers = $offers[0] ?? [];
            }

            $availability = (string) ($offers['availability'] ?? '');
            if ($availability !== '') {
                $av = mb_strtolower($availability);
                if (Str::contains($av, 'instock')) {
                    $inStock = 1;
                } elseif (Str::contains($av, 'outofstock')) {
                    $inStock = 0;
                }
            }

            $invVal = $offers['inventoryLevel']['value'] ?? null;
            if (is_numeric((string) $invVal)) {
                $qty = max(0, (int) $invVal);
            }
        }

        if ($qty === null) {
            $txt = mb_strtolower(strip_tags($html));
            if (Str::contains($txt, 'нет в наличии')) {
                $inStock = 0;
            } elseif (Str::contains($txt, 'в наличии')) {
                $inStock = 1;
            }
        }

        return [$inStock, $qty];
    }

    private function extractSku(DOMXPath $xpath, array $specs): ?string
    {
        // 1) пытаемся найти в specs
        foreach ($specs as $s) {
            $name = mb_strtolower((string) ($s['name'] ?? ''));
            if (Str::contains($name, ['артикул', 'sku', 'код товара', 'модель'])) {
                $v = trim((string) ($s['value'] ?? ''));
                if ($v !== '') {
                    return $v;
                }
            }
        }

        // 2) meta[itemprop=sku]
        $node = $xpath->query("//*[@itemprop='sku']")?->item(0);
        $val = $node ? trim($this->nodeText($node)) : '';

        return $val !== '' ? $val : null;
    }

    private function extractBrand(array $productJson, array $specs): ?string
    {
        $brand = $productJson['brand']['name'] ?? $productJson['brand'] ?? null;
        if (is_string($brand) && trim($brand) !== '') {
            return trim($brand);
        }

        foreach ($specs as $s) {
            $name = mb_strtolower((string) ($s['name'] ?? ''));
            if (Str::contains($name, ['бренд', 'brand', 'производитель'])) {
                $v = trim((string) ($s['value'] ?? ''));
                if ($v !== '') {
                    return $v;
                }
            }
        }

        return null;
    }

    private function extractCountry(array $productJson, array $specs): ?string
    {
        // В JSON-LD часто бывает страна доставки, не страна производства.
        // Поэтому сначала пробуем именно в specs.
        foreach ($specs as $s) {
            $name = mb_strtolower((string) ($s['name'] ?? ''));
            if (Str::contains($name, ['страна', 'country', 'происхождения', 'производства'])) {
                $v = trim((string) ($s['value'] ?? ''));
                if ($v !== '') {
                    return $v;
                }
            }
        }

        return null;
    }

    private function extractDescriptionFromDom(DOMXPath $xpath): ?string
    {
        // Попробуем типовые блоки описания
        $candidates = [
            "//*[contains(translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'description')]",
            "//*[contains(translate(@id,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'description')]",
            '//article',
        ];

        foreach ($candidates as $expr) {
            $node = $xpath->query($expr)?->item(0);
            if (! $node) {
                continue;
            }

            $text = trim($this->nodeText($node));
            if (mb_strlen($text) >= 60) {
                return $text;
            }
        }

        return null;
    }

    private function extractSlugFromUrl(string $url): string
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        if ($path === '') {
            return Str::random(12);
        }

        $parts = array_values(array_filter(explode('/', $path)));
        $slug = end($parts) ?: $path;

        return Str::of($slug)->lower()->trim()->toString();
    }

    private function normalizeName(string $name): string
    {
        $n = trim(strip_tags($name));
        $n = preg_replace('/\s+/u', ' ', $n) ?? $n;

        return mb_strtolower($n);
    }

    private function cleanSpecName(string $name): string
    {
        $name = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $name = preg_replace('/<\s*\/?\s*[a-z][a-z0-9:-]*(?:\s+[^<>]*?)?>/iu', '', $name) ?? $name;
        $name = trim($name);
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
        $name = trim($name, " \t\n\r\0\x0B:;");

        return $name;
    }

    private function firstText(DOMXPath $xpath, string $expr): ?string
    {
        $node = $xpath->query($expr)?->item(0);
        if (! $node) {
            return null;
        }

        $txt = trim($this->nodeText($node));

        return $txt !== '' ? $txt : null;
    }

    private function metaContent(DOMXPath $xpath, string $name): ?string
    {
        if ($name === 'title') {
            $node = $xpath->query('//title')?->item(0);
            $txt = $node ? trim($this->nodeText($node)) : '';

            return $txt !== '' ? $txt : null;
        }

        $expr = "//meta[translate(@name,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')='".mb_strtolower($name)."']/@content";
        $attr = $xpath->query($expr)?->item(0);
        $val = $attr ? trim((string) $attr->nodeValue) : '';

        return $val !== '' ? $val : null;
    }

    private function metaProperty(DOMXPath $xpath, string $property): ?string
    {
        $expr = "//meta[translate(@property,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')='".mb_strtolower($property)."']/@content";
        $attr = $xpath->query($expr)?->item(0);
        $val = $attr ? trim((string) $attr->nodeValue) : '';

        return $val !== '' ? $val : null;
    }

    private function nodeText(?DOMNode $node): string
    {
        if (! $node) {
            return '';
        }
        $txt = trim((string) $node->textContent);
        $txt = preg_replace('/\s+/u', ' ', $txt) ?? $txt;

        return trim($txt);
    }

    private function stringOrNull(mixed $v): ?string
    {
        if (! is_string($v)) {
            return null;
        }
        $v = trim($v);

        return $v !== '' ? $v : null;
    }

    private function absoluteUrl(string $baseUrl, string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('~^https?://~i', $value)) {
            return $value;
        }

        $base = parse_url($baseUrl);
        if (! $base || empty($base['host'])) {
            return null;
        }

        $scheme = $base['scheme'] ?? 'https';
        $host = $base['host'];
        $port = isset($base['port']) ? ':'.$base['port'] : '';

        if (Str::startsWith($value, '//')) {
            return $scheme.':'.$value;
        }

        if (Str::startsWith($value, '/')) {
            return "{$scheme}://{$host}{$port}{$value}";
        }

        $path = $base['path'] ?? '/';
        $dir = trim(str_replace('\\', '/', dirname($path)), '/');
        $prefix = $dir !== '' ? "/{$dir}/" : '/';

        return "{$scheme}://{$host}{$port}{$prefix}{$value}";
    }

    private function isLikelyImageUrl(string $url): bool
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return (bool) preg_match('/\.(jpe?g|png|webp|gif|avif|svg)$/i', $path);
    }

    private function filterNulls(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            if ($v !== null) {
                $out[$k] = $v;
            }
        }

        return $out;
    }
}
