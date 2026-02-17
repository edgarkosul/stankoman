<?php

namespace App\Console\Commands;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class ParseProductsCommand extends Command
{
    protected $signature = 'parser:parse-products
        {--bucket= : category bucket, e.g. magnitnye}
        {--limit=0 : max products to parse (0 = all)}
        {--buckets-file= : absolute path to buckets json}
        {--timeout=25 : request timeout in seconds}
        {--sleep=250 : delay between requests in ms}
        {--dry-run=0 : parse only, do not write DB}';

    protected $description = 'Parse product pages from metalmaster buckets and save to products table';

    public function handle(): int
    {
        $bucketsFile = (string) ($this->option('buckets-file') ?: storage_path('app/parser/metalmaster-buckets.json'));
        $bucketFilter = trim((string) $this->option('bucket'));
        $limit = (int) $this->option('limit');
        $timeout = (int) $this->option('timeout');
        $sleepMs = max(0, (int) $this->option('sleep'));
        $dryRun = (bool) ((int) $this->option('dry-run'));

        if (!is_file($bucketsFile)) {
            $this->error("Buckets file not found: {$bucketsFile}");
            $this->line('Run: a parser:sitemap-buckets --sitemap=https://metalmaster.ru/sitemap.xml');
            return self::FAILURE;
        }

        $raw = file_get_contents($bucketsFile);
        $buckets = json_decode($raw ?: '[]', true);
        if (!is_array($buckets)) {
            $this->error("Invalid JSON in: {$bucketsFile}");
            return self::FAILURE;
        }

        $targets = collect($buckets)
            ->filter(function (array $row) use ($bucketFilter) {
                if ($bucketFilter === '') {
                    return true;
                }
                return (($row['bucket'] ?? '') === $bucketFilter);
            })
            ->flatMap(function (array $row) {
                $bucket = (string) ($row['bucket'] ?? '');
                $urls = is_array($row['product_urls'] ?? null) ? $row['product_urls'] : [];

                return collect($urls)->map(fn ($u) => [
                    'bucket' => $bucket,
                    'url' => (string) $u,
                ]);
            })
            ->filter(fn (array $x) => filter_var($x['url'], FILTER_VALIDATE_URL))
            ->unique('url')
            ->values();

        if ($limit > 0) {
            $targets = $targets->take($limit)->values();
        }

        if ($targets->isEmpty()) {
            $this->warn('No product URLs found for selected bucket/filter.');
            return self::SUCCESS;
        }

        $this->info('Products to parse: ' . $targets->count());
        $bar = $this->output->createProgressBar($targets->count());
        $bar->start();

        $created = 0;
        $updated = 0;
        $failed = 0;

        foreach ($targets as $item) {
            $url = $item['url'];
            $bucket = $item['bucket'];

            try {
                $resp = Http::withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; SitekoParser/1.0; +https://siteko.net)',
                    'Accept-Language' => 'ru-RU,ru;q=0.9,en;q=0.8',
                ])->timeout($timeout)
                  ->retry(2, 300)
                  ->get($url);

                if (!$resp->ok()) {
                    throw new \RuntimeException("HTTP {$resp->status()}");
                }

                $html = (string) $resp->body();
                $parsed = $this->parseProductPage($html, $url, $bucket);

                if ($dryRun) {
                    $this->newLine();
                    $this->line("DRY {$parsed['slug']}: {$parsed['name']}");
                } else {
                    $result = $this->saveProduct($parsed);
                    if ($result === 'created') {
                        $created++;
                    } elseif ($result === 'updated') {
                        $updated++;
                    }
                }
            } catch (Throwable $e) {
                $failed++;
                $this->newLine();
                $this->error("ERR {$url} | {$e->getMessage()}");
            }

            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Done. created={$created}, updated={$updated}, failed={$failed}" . ($dryRun ? ' (dry-run)' : ''));

        return self::SUCCESS;
    }

    private function parseProductPage(string $html, string $url, string $bucket): array
    {
        [$dom, $xpath] = $this->makeDom($html);

        $jsonLdObjects = $this->extractJsonLdObjects($xpath);
        $productJson = $this->findProductJsonLd($jsonLdObjects);

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
        $specsDom = $this->extractSpecsFromDom($xpath);

        $specs = $this->dedupeSpecs(array_merge($specsJsonLd, $specsDom));

        $gallery = $this->extractGallery($xpath, $productJson, $url);
        $image = $gallery[0] ?? null;
        $thumb = $gallery[0] ?? null;

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

            'specs' => !empty($specs) ? json_encode($specs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,

            'promo_info' => null,

            'image' => $image,
            'thumb' => $thumb,
            'gallery' => !empty($gallery)
                ? json_encode($gallery, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null,

            'meta_title' => $metaTitle ?: $title,
            'meta_description' => $metaDescription ?: ($description ? Str::limit(trim(strip_tags($description)), 250) : null),

            // Поля из твоей структуры, которые лучше не трогать лишний раз:
            // popularity, is_active, is_in_yml_feed, with_dns оставляем дефолтами БД.
            // bucket/category можно хранить отдельно при необходимости.
        ];
    }

    private function saveProduct(array $parsed): string
    {
        $slug = (string) ($parsed['slug'] ?? '');
        if ($slug === '') {
            throw new \RuntimeException('Empty slug');
        }

        $now = now();

        $existing = DB::table('products')
            ->select('id')
            ->where('slug', $slug)
            ->first();

        if ($existing) {
            $update = $this->filterNulls($parsed);
            unset($update['slug']);

            if (!empty($update)) {
                $update['updated_at'] = $now;
                DB::table('products')->where('id', $existing->id)->update($update);
            }

            return 'updated';
        }

        $insert = array_merge([
            'name' => $parsed['name'] ?? Str::title(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'currency' => 'RUB',
            'price_amount' => 0,
            'in_stock' => 1,
            'popularity' => 0,
            'is_active' => 1,
            'is_in_yml_feed' => 1,
            'with_dns' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ], $this->filterNulls($parsed));

        DB::table('products')->insert($insert);

        return 'created';
    }

    private function makeDom(string $html): array
    {
        libxml_use_internal_errors(true);

        $dom = new DOMDocument('1.0', 'UTF-8');
        $html = $this->ensureUtf8($html);

        // важно: добавляем xml пролог для корректной UTF-8 загрузки
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
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
        if (!$nodes) {
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
        if (!is_array($decoded)) {
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

        if (!is_array($props)) {
            return $specs;
        }

        foreach ($props as $p) {
            if (!is_array($p)) {
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

    private function extractSpecsFromDom(DOMXPath $xpath): array
    {
        $specs = [];

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

        // 2) table tr td/th (как на metalmaster_demo)
        $rows = $xpath->query('//table//tr');
        if ($rows) {
            foreach ($rows as $row) {
                $cells = $xpath->query('./th|./td', $row);
                if (!$cells || $cells->length < 2) {
                    continue;
                }

                $name = $this->cleanSpecName($this->nodeText($cells->item(0)));
                $value = trim($this->nodeText($cells->item(1)));

                if ($name === '' || $value === '') {
                    continue;
                }

                // фильтр от мусорных строк таблиц
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

            $k = mb_strtolower($name) . '::' . mb_strtolower($value);

            // при дубле сохраняем запись jsonld приоритетнее dom
            if (!isset($map[$k]) || ($map[$k]['source'] === 'dom' && $source === 'jsonld')) {
                $map[$k] = [
                    'name' => $name,
                    'value' => $value,
                    'source' => $source,
                ];
            }
        }

        return array_values($map);
    }

    private function extractGallery(DOMXPath $xpath, array $productJson, string $baseUrl): array
    {
        $gallery = [];

        // JSON-LD image
        $image = $productJson['image'] ?? null;
        foreach ($this->flattenImageField($image) as $img) {
            $abs = $this->absoluteUrl($baseUrl, $img);
            if ($abs && $this->isLikelyImageUrl($abs)) {
                $gallery[] = $abs;
            }
        }

        // og:image fallback
        $ogImage = $this->metaProperty($xpath, 'og:image');
        if ($ogImage) {
            $abs = $this->absoluteUrl($baseUrl, $ogImage);
            if ($abs) {
                $gallery[] = $abs;
            }
        }

        // DOM fallback (главные картинки товара)
        $imgNodes = $xpath->query("//img[@src]");
        if ($imgNodes) {
            foreach ($imgNodes as $imgNode) {
                $src = trim((string) ($imgNode->attributes?->getNamedItem('src')?->nodeValue ?? ''));
                if ($src === '') {
                    continue;
                }

                $abs = $this->absoluteUrl($baseUrl, $src);
                if (!$abs || !$this->isLikelyImageUrl($abs)) {
                    continue;
                }

                // отсечем явные системные/иконки
                if (preg_match('~/(logo|icon|sprite|favicon|avatar)~i', $abs)) {
                    continue;
                }

                $gallery[] = $abs;
            }
        }

        $gallery = array_values(array_unique($gallery));

        // ограничим, чтобы не тащить лишнее
        return array_slice($gallery, 0, 40);
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
            "//article",
        ];

        foreach ($candidates as $expr) {
            $node = $xpath->query($expr)?->item(0);
            if (!$node) {
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
        $name = trim(strip_tags($name));
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
        $name = trim($name, " \t\n\r\0\x0B:;");
        return $name;
    }

    private function firstText(DOMXPath $xpath, string $expr): ?string
    {
        $node = $xpath->query($expr)?->item(0);
        if (!$node) {
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

        $expr = "//meta[translate(@name,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')='" . mb_strtolower($name) . "']/@content";
        $attr = $xpath->query($expr)?->item(0);
        $val = $attr ? trim((string) $attr->nodeValue) : '';

        return $val !== '' ? $val : null;
    }

    private function metaProperty(DOMXPath $xpath, string $property): ?string
    {
        $expr = "//meta[translate(@property,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')='" . mb_strtolower($property) . "']/@content";
        $attr = $xpath->query($expr)?->item(0);
        $val = $attr ? trim((string) $attr->nodeValue) : '';

        return $val !== '' ? $val : null;
    }

    private function nodeText(?DOMNode $node): string
    {
        if (!$node) {
            return '';
        }
        $txt = trim((string) $node->textContent);
        $txt = preg_replace('/\s+/u', ' ', $txt) ?? $txt;
        return trim($txt);
    }

    private function stringOrNull(mixed $v): ?string
    {
        if (!is_string($v)) {
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
        if (!$base || empty($base['host'])) {
            return null;
        }

        $scheme = $base['scheme'] ?? 'https';
        $host = $base['host'];
        $port = isset($base['port']) ? ':' . $base['port'] : '';

        if (Str::startsWith($value, '//')) {
            return $scheme . ':' . $value;
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
