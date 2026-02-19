<?php

declare(strict_types=1);

/**
 * One-off parser: build a hierarchy tree from breadcrumb DOM blocks in sitemap pages.
 * Source breadcrumbs selector: ul.track__munu > li
 */
const DEFAULT_SITEMAP_FILE = 'sitemap_metalmaster.xml';
const DEFAULT_OUTPUT_FILE = 'storage/app/parser/metalmaster-breadcrumb-tree.json';
const DEFAULT_ERRORS_FILE = 'storage/app/parser/metalmaster-breadcrumb-fetch-errors.json';
const DEFAULT_CONCURRENCY = 24;
const DEFAULT_CONNECT_TIMEOUT = 8;
const DEFAULT_REQUEST_TIMEOUT = 20;
const DEFAULT_ENQUEUE_DELAY_MS = 0;
const DEFAULT_MAX_URLS = 0;

function stderr(string $message): void
{
    fwrite(STDERR, $message.PHP_EOL);
}

/**
 * @return array{
 *     sitemap_file: string,
 *     output_file: string,
 *     errors_file: string,
 *     urls_file: string,
 *     concurrency: int,
 *     request_timeout: int,
 *     connect_timeout: int,
 *     enqueue_delay_ms: int,
 *     max_urls: int
 * }
 */
function parseCliOptions(array $argv): array
{
    $options = [
        'sitemap_file' => DEFAULT_SITEMAP_FILE,
        'output_file' => DEFAULT_OUTPUT_FILE,
        'errors_file' => DEFAULT_ERRORS_FILE,
        'urls_file' => '',
        'concurrency' => DEFAULT_CONCURRENCY,
        'request_timeout' => DEFAULT_REQUEST_TIMEOUT,
        'connect_timeout' => DEFAULT_CONNECT_TIMEOUT,
        'enqueue_delay_ms' => DEFAULT_ENQUEUE_DELAY_MS,
        'max_urls' => DEFAULT_MAX_URLS,
    ];

    $positionals = [];

    foreach (array_slice($argv, 1) as $argument) {
        if (! is_string($argument) || $argument === '') {
            continue;
        }

        if (! str_starts_with($argument, '--')) {
            $positionals[] = $argument;

            continue;
        }

        [$name, $value] = array_pad(explode('=', substr($argument, 2), 2), 2, '1');

        $value = trim((string) $value);

        if ($name === 'concurrency') {
            $options['concurrency'] = max(1, (int) $value);

            continue;
        }

        if ($name === 'timeout' || $name === 'request-timeout') {
            $options['request_timeout'] = max(1, (int) $value);

            continue;
        }

        if ($name === 'connect-timeout') {
            $options['connect_timeout'] = max(1, (int) $value);

            continue;
        }

        if ($name === 'enqueue-delay-ms') {
            $options['enqueue_delay_ms'] = max(0, (int) $value);

            continue;
        }

        if ($name === 'max-urls') {
            $options['max_urls'] = max(0, (int) $value);

            continue;
        }

        if ($name === 'errors-file') {
            $options['errors_file'] = $value;

            continue;
        }

        if ($name === 'urls-file') {
            $options['urls_file'] = $value;
        }
    }

    if (isset($positionals[0])) {
        $options['sitemap_file'] = (string) $positionals[0];
    }

    if (isset($positionals[1])) {
        $options['output_file'] = (string) $positionals[1];
    }

    return $options;
}

function toAbsolutePath(string $path, string $cwd): string
{
    if ($path === '') {
        return '';
    }

    if (str_starts_with($path, '/')) {
        return $path;
    }

    return $cwd.DIRECTORY_SEPARATOR.$path;
}

/**
 * @return array<int, string>
 */
function readUrlsFromFile(string $path): array
{
    if (! is_file($path)) {
        throw new RuntimeException("URLs file not found: {$path}");
    }

    $raw = file_get_contents($path);

    if (! is_string($raw)) {
        throw new RuntimeException("Unable to read URLs file: {$path}");
    }

    $decoded = json_decode($raw, true);
    $urls = [];

    if (is_array($decoded)) {
        if (array_is_list($decoded)) {
            foreach ($decoded as $item) {
                if (is_string($item)) {
                    $normalized = normalizeUrl($item);

                    if ($normalized !== null) {
                        $urls[$normalized] = true;
                    }
                }

                if (is_array($item)) {
                    $candidate = $item['url'] ?? null;

                    if (is_string($candidate)) {
                        $normalized = normalizeUrl($candidate);

                        if ($normalized !== null) {
                            $urls[$normalized] = true;
                        }
                    }
                }
            }
        }

        $errorRows = $decoded['errors'] ?? $decoded['fetch_errors'] ?? null;

        if (is_array($errorRows)) {
            foreach ($errorRows as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $candidate = $item['url'] ?? null;

                if (! is_string($candidate)) {
                    continue;
                }

                $normalized = normalizeUrl($candidate);

                if ($normalized !== null) {
                    $urls[$normalized] = true;
                }
            }
        }
    }

    if ($urls !== []) {
        return array_keys($urls);
    }

    foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
        $normalized = normalizeUrl((string) $line);

        if ($normalized === null) {
            continue;
        }

        $urls[$normalized] = true;
    }

    return array_keys($urls);
}

function normalizeWhitespace(string $value): string
{
    $value = preg_replace('/\s+/u', ' ', trim($value));

    return is_string($value) ? $value : '';
}

function ensureUtf8(string $html): string
{
    if (mb_detect_encoding($html, 'UTF-8', true) !== false) {
        return $html;
    }

    $converted = mb_convert_encoding($html, 'UTF-8', 'Windows-1251,CP1251,ISO-8859-1,UTF-8');

    return is_string($converted) ? $converted : $html;
}

function normalizePath(string $path): string
{
    $path = preg_replace('~/+~', '/', $path);

    if (! is_string($path) || $path === '') {
        return '/';
    }

    $path = '/'.ltrim($path, '/');

    $segments = explode('/', $path);
    $resolved = [];

    foreach ($segments as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }

        if ($segment === '..') {
            array_pop($resolved);

            continue;
        }

        $resolved[] = $segment;
    }

    $normalized = '/'.implode('/', $resolved);

    if ($normalized !== '/' && str_ends_with($normalized, '/')) {
        $normalized = rtrim($normalized, '/');
    }

    return $normalized;
}

function normalizeUrl(string $url): ?string
{
    $url = trim($url);

    if ($url === '') {
        return null;
    }

    $parts = parse_url($url);

    if (! is_array($parts)) {
        return null;
    }

    $host = strtolower((string) ($parts['host'] ?? ''));

    if ($host === '') {
        return null;
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
    $path = normalizePath((string) ($parts['path'] ?? '/'));

    return sprintf('%s://%s%s', $scheme, $host, $path);
}

function pathFromUrl(string $url): string
{
    $path = (string) (parse_url($url, PHP_URL_PATH) ?? '/');

    return normalizePath($path);
}

function resolveUrl(string $href, string $baseUrl): ?string
{
    $href = trim($href);

    if ($href === '' || str_starts_with($href, '#') || stripos($href, 'javascript:') === 0) {
        return null;
    }

    if (preg_match('~^https?://~i', $href) === 1) {
        return normalizeUrl($href);
    }

    $base = parse_url($baseUrl);

    if (! is_array($base) || empty($base['host'])) {
        return null;
    }

    $scheme = strtolower((string) ($base['scheme'] ?? 'https'));
    $host = strtolower((string) $base['host']);

    if (str_starts_with($href, '//')) {
        return normalizeUrl($scheme.':'.$href);
    }

    if (str_starts_with($href, '/')) {
        return normalizeUrl(sprintf('%s://%s%s', $scheme, $host, $href));
    }

    $basePath = (string) ($base['path'] ?? '/');
    $dir = dirname($basePath);

    if ($dir === '\\' || $dir === '.') {
        $dir = '/';
    }

    $fullPath = normalizePath(rtrim($dir, '/').'/'.$href);

    return normalizeUrl(sprintf('%s://%s%s', $scheme, $host, $fullPath));
}

/**
 * @return array<int, string>
 */
function readSitemapUrls(string $sitemapFile): array
{
    if (! is_file($sitemapFile)) {
        throw new RuntimeException("Sitemap file not found: {$sitemapFile}");
    }

    libxml_use_internal_errors(true);

    $xml = simplexml_load_file($sitemapFile);

    if (! $xml instanceof SimpleXMLElement) {
        throw new RuntimeException("Invalid XML in sitemap file: {$sitemapFile}");
    }

    $nodes = $xml->xpath('/*[local-name()="urlset"]/*[local-name()="url"]/*[local-name()="loc"]');

    if (! is_array($nodes)) {
        throw new RuntimeException('Sitemap has no <urlset>/<url>/<loc> entries.');
    }

    $urls = [];

    foreach ($nodes as $node) {
        $normalized = normalizeUrl((string) $node);

        if ($normalized === null) {
            continue;
        }

        $urls[$normalized] = true;
    }

    return array_keys($urls);
}

/**
 * @return array<int, array{name: string, url: string}>
 */
function extractBreadcrumbItems(string $html, string $pageUrl): array
{
    libxml_use_internal_errors(true);

    $dom = new DOMDocument('1.0', 'UTF-8');
    $html = ensureUtf8($html);

    if (! $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOWARNING | LIBXML_NOERROR)) {
        return [];
    }

    $xpath = new DOMXPath($dom);

    $query = '//ul[contains(concat(" ", normalize-space(@class), " "), " track__munu ")]//li';
    $nodes = $xpath->query($query);

    if (! $nodes || $nodes->length === 0) {
        return [];
    }

    $items = [];

    foreach ($nodes as $node) {
        $name = normalizeWhitespace((string) $xpath->evaluate('string(.//a[1]/span[1])', $node));

        if ($name === '') {
            $name = normalizeWhitespace((string) $xpath->evaluate('string(.//a[1])', $node));
        }

        if ($name === '') {
            $name = normalizeWhitespace((string) $xpath->evaluate('string(.//span[1])', $node));
        }

        if ($name === '') {
            $name = normalizeWhitespace((string) $xpath->evaluate('string(.)', $node));
        }

        if ($name === '' || preg_match('/^\d+$/', $name) === 1) {
            continue;
        }

        $href = normalizeWhitespace((string) $xpath->evaluate('string(.//a[1]/@href)', $node));
        $resolved = $href !== '' ? resolveUrl($href, $pageUrl) : null;

        $items[] = [
            'name' => $name,
            'url' => $resolved ?? '',
        ];
    }

    if ($items === []) {
        return [];
    }

    // Drop explicit home crumb from the start.
    $first = $items[0] ?? null;

    if (is_array($first)) {
        $firstPath = $first['url'] !== '' ? pathFromUrl($first['url']) : '';
        $firstName = mb_strtolower((string) ($first['name'] ?? ''));

        if ($firstPath === '/' || str_contains($firstName, 'главн')) {
            array_shift($items);
        }
    }

    if ($items === []) {
        return [];
    }

    $pageNormalizedUrl = normalizeUrl($pageUrl);

    if ($pageNormalizedUrl === null) {
        return [];
    }

    $lastIndex = count($items) - 1;

    foreach ($items as $index => &$item) {
        if (! is_array($item)) {
            continue;
        }

        if ($item['url'] !== '') {
            continue;
        }

        // Usually only the last breadcrumb item has no link.
        if ($index === $lastIndex) {
            $item['url'] = $pageNormalizedUrl;
        }
    }
    unset($item);

    $items = array_values(array_filter(
        $items,
        static fn (array $item): bool => $item['url'] !== '' && $item['name'] !== ''
    ));

    if (count($items) < 2) {
        return [];
    }

    // De-duplicate consecutive equal URLs in one chain.
    $deduped = [];

    foreach ($items as $item) {
        $previous = $deduped[count($deduped) - 1] ?? null;

        if (is_array($previous) && ($previous['url'] ?? null) === $item['url']) {
            continue;
        }

        $deduped[] = $item;
    }

    return $deduped;
}

/**
 * @param  array<string, array<string, int>>  $nameVotes
 * @return array<string, string>
 */
function pickCanonicalNames(array $nameVotes): array
{
    $result = [];

    foreach ($nameVotes as $url => $votes) {
        if ($votes === []) {
            continue;
        }

        arsort($votes);
        $result[$url] = (string) array_key_first($votes);
    }

    return $result;
}

/**
 * @param  array<string, array<string, int>>  $edgeCounts
 * @return array{parentOf: array<string, string>, childrenOf: array<string, array<int, string>>, edgeScore: array<string, int>, conflicts: array<int, array<string, mixed>>}
 */
function assignParents(array $edgeCounts): array
{
    $edgeList = [];
    $incoming = [];

    foreach ($edgeCounts as $parent => $children) {
        foreach ($children as $child => $count) {
            if ($parent === $child) {
                continue;
            }

            $edgeList[] = [
                'parent' => $parent,
                'child' => $child,
                'count' => $count,
            ];

            $incoming[$child][$parent] = $count;
        }
    }

    usort(
        $edgeList,
        static function (array $left, array $right): int {
            $byCount = ((int) $right['count']) <=> ((int) $left['count']);

            if ($byCount !== 0) {
                return $byCount;
            }

            return strcmp((string) $left['parent'], (string) $right['parent']);
        }
    );

    $parentOf = [];
    $edgeScore = [];

    foreach ($edgeList as $edge) {
        $parent = (string) $edge['parent'];
        $child = (string) $edge['child'];
        $count = (int) $edge['count'];

        if (isset($parentOf[$child])) {
            continue;
        }

        // Prevent cycles.
        $cursor = $parent;
        $isCycle = false;

        while (isset($parentOf[$cursor])) {
            $cursor = $parentOf[$cursor];

            if ($cursor === $child) {
                $isCycle = true;

                break;
            }
        }

        if ($isCycle) {
            continue;
        }

        $parentOf[$child] = $parent;
        $edgeScore[$child] = $count;
    }

    $childrenOf = [];

    foreach ($parentOf as $child => $parent) {
        $childrenOf[$parent] ??= [];
        $childrenOf[$parent][] = $child;
    }

    foreach ($childrenOf as &$children) {
        sort($children);
    }
    unset($children);

    $conflicts = [];

    foreach ($incoming as $child => $parents) {
        if (count($parents) < 2) {
            continue;
        }

        arsort($parents);

        $conflicts[] = [
            'child' => $child,
            'candidates' => $parents,
            'picked' => $parentOf[$child] ?? null,
        ];
    }

    usort(
        $conflicts,
        static fn (array $left, array $right): int => count($right['candidates']) <=> count($left['candidates'])
    );

    return [
        'parentOf' => $parentOf,
        'childrenOf' => $childrenOf,
        'edgeScore' => $edgeScore,
        'conflicts' => $conflicts,
    ];
}

/**
 * @param  array<string, array<int, string>>  $childrenOf
 * @param  array<string, string>  $nameByUrl
 * @param  array<string, true>  $visited
 * @return array<string, mixed>
 */
function buildTreeNode(string $url, array $childrenOf, array $nameByUrl, array &$visited): array
{
    if (isset($visited[$url])) {
        return [
            'title' => $nameByUrl[$url] ?? $url,
            'url' => $url,
            'path' => pathFromUrl($url),
            'children' => [],
            'cycle_cut' => true,
        ];
    }

    $visited[$url] = true;

    $children = $childrenOf[$url] ?? [];

    usort(
        $children,
        static fn (string $left, string $right): int => strcmp($nameByUrl[$left] ?? $left, $nameByUrl[$right] ?? $right)
    );

    $childNodes = [];

    foreach ($children as $childUrl) {
        $childVisited = $visited;
        $childNodes[] = buildTreeNode($childUrl, $childrenOf, $nameByUrl, $childVisited);
    }

    return [
        'title' => $nameByUrl[$url] ?? $url,
        'url' => $url,
        'path' => pathFromUrl($url),
        'children' => $childNodes,
    ];
}

function main(array $argv): int
{
    $cwd = getcwd() ?: '.';

    $options = parseCliOptions($argv);

    $sitemapFile = toAbsolutePath((string) $options['sitemap_file'], $cwd);
    $outputFile = toAbsolutePath((string) $options['output_file'], $cwd);
    $errorsFile = toAbsolutePath((string) $options['errors_file'], $cwd);
    $urlsFile = toAbsolutePath((string) $options['urls_file'], $cwd);

    $concurrency = max(1, (int) $options['concurrency']);
    $requestTimeout = max(1, (int) $options['request_timeout']);
    $connectTimeout = max(1, (int) $options['connect_timeout']);
    $enqueueDelayMs = max(0, (int) $options['enqueue_delay_ms']);
    $maxUrls = max(0, (int) $options['max_urls']);

    $sourceType = $urlsFile !== '' ? 'urls_file' : 'sitemap';
    $urls = $urlsFile !== '' ? readUrlsFromFile($urlsFile) : readSitemapUrls($sitemapFile);

    if ($maxUrls > 0) {
        $urls = array_slice($urls, 0, $maxUrls);
    }

    $total = count($urls);

    if ($total === 0) {
        throw new RuntimeException('No URLs found in sitemap.');
    }

    stderr('Source: '.$sourceType);

    if ($sourceType === 'sitemap') {
        stderr('Reading sitemap: '.$sitemapFile);
    } else {
        stderr('Reading URLs file: '.$urlsFile);
    }

    stderr('URLs in queue: '.$total);
    stderr(sprintf(
        'Fetching pages (concurrency=%d, timeout=%ds, connect_timeout=%ds, enqueue_delay_ms=%d)...',
        $concurrency,
        $requestTimeout,
        $connectTimeout,
        $enqueueDelayMs
    ));

    $multiHandle = curl_multi_init();

    if (! is_resource($multiHandle) && ! $multiHandle instanceof CurlMultiHandle) {
        throw new RuntimeException('Unable to initialize curl_multi.');
    }

    $queue = array_values($urls);
    $active = [];

    $fetchedOk = 0;
    $fetchErrors = [];
    $processed = 0;
    $pagesWithBreadcrumbs = 0;

    $edgeCounts = [];
    $nameVotes = [];
    $allNodeUrls = [];

    $enqueue = static function (string $url) use ($multiHandle, &$active, $connectTimeout, $requestTimeout): void {
        $ch = curl_init($url);

        if ($ch === false) {
            return;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => $requestTimeout,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; MetalmasterBreadcrumbTreeBuilder/1.0; +https://siteko.net)',
            CURLOPT_HTTPHEADER => [
                'Accept-Language: ru-RU,ru;q=0.9,en;q=0.8',
            ],
        ]);

        curl_multi_add_handle($multiHandle, $ch);
        $active[(int) $ch] = [
            'handle' => $ch,
            'url' => $url,
        ];
    };

    $processPage = static function (string $url, string $html) use (&$pagesWithBreadcrumbs, &$edgeCounts, &$nameVotes, &$allNodeUrls): void {
        $items = extractBreadcrumbItems($html, $url);

        if (count($items) < 2) {
            return;
        }

        $pagesWithBreadcrumbs++;

        foreach ($items as $item) {
            $nodeUrl = (string) ($item['url'] ?? '');
            $name = normalizeWhitespace((string) ($item['name'] ?? ''));

            if ($nodeUrl === '' || $name === '') {
                continue;
            }

            $allNodeUrls[$nodeUrl] = true;
            $nameVotes[$nodeUrl] ??= [];
            $nameVotes[$nodeUrl][$name] = (int) ($nameVotes[$nodeUrl][$name] ?? 0) + 1;
        }

        for ($index = 0, $max = count($items) - 1; $index < $max; $index++) {
            $parent = (string) ($items[$index]['url'] ?? '');
            $child = (string) ($items[$index + 1]['url'] ?? '');

            if ($parent === '' || $child === '' || $parent === $child) {
                continue;
            }

            $edgeCounts[$parent] ??= [];
            $edgeCounts[$parent][$child] = (int) ($edgeCounts[$parent][$child] ?? 0) + 1;
        }
    };

    while ($queue !== [] || $active !== []) {
        while (count($active) < $concurrency && $queue !== []) {
            $url = array_shift($queue);

            if (! is_string($url) || $url === '') {
                continue;
            }

            $enqueue($url);

            if ($enqueueDelayMs > 0) {
                usleep($enqueueDelayMs * 1000);
            }
        }

        do {
            $status = curl_multi_exec($multiHandle, $running);
        } while ($status === CURLM_CALL_MULTI_PERFORM);

        if ($running > 0) {
            curl_multi_select($multiHandle, 1.0);
        }

        while ($info = curl_multi_info_read($multiHandle)) {
            if (! is_array($info) || ! isset($info['handle'])) {
                continue;
            }

            $ch = $info['handle'];
            $id = (int) $ch;
            $context = $active[$id] ?? null;

            if (! is_array($context)) {
                curl_multi_remove_handle($multiHandle, $ch);
                curl_close($ch);

                continue;
            }

            $url = (string) $context['url'];
            $content = curl_multi_getcontent($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $curlError = curl_error($ch);

            if ($curlError === '' && $httpCode >= 200 && $httpCode < 300 && is_string($content) && $content !== '') {
                $fetchedOk++;
                $processPage($url, $content);
            } else {
                $fetchErrors[$url] = $curlError !== '' ? $curlError : ('HTTP '.$httpCode);
            }

            $processed++;

            if ($processed % 200 === 0 || $processed === $total) {
                stderr(sprintf(
                    'Progress: %d/%d | ok=%d | errors=%d | with_breadcrumbs=%d',
                    $processed,
                    $total,
                    $fetchedOk,
                    count($fetchErrors),
                    $pagesWithBreadcrumbs
                ));
            }

            unset($active[$id]);
            curl_multi_remove_handle($multiHandle, $ch);
            curl_close($ch);
        }
    }

    curl_multi_close($multiHandle);

    stderr('Resolving tree...');

    $nameByUrl = pickCanonicalNames($nameVotes);
    $relationships = assignParents($edgeCounts);

    $parentOf = $relationships['parentOf'];
    $childrenOf = $relationships['childrenOf'];
    $conflicts = $relationships['conflicts'];

    $allUrls = array_keys($allNodeUrls);

    $rootCandidates = [];

    foreach ($allUrls as $url) {
        if (! isset($parentOf[$url])) {
            $rootCandidates[] = $url;
        }
    }

    usort(
        $rootCandidates,
        static fn (string $left, string $right): int => strcmp($nameByUrl[$left] ?? $left, $nameByUrl[$right] ?? $right)
    );

    $forest = [];

    foreach ($rootCandidates as $rootUrl) {
        $visited = [];
        $forest[] = buildTreeNode($rootUrl, $childrenOf, $nameByUrl, $visited);
    }

    $rootsPreview = array_map(
        static function (string $url) use ($nameByUrl, $childrenOf): array {
            return [
                'title' => $nameByUrl[$url] ?? $url,
                'url' => $url,
                'path' => pathFromUrl($url),
                'children_count' => count($childrenOf[$url] ?? []),
            ];
        },
        array_slice($rootCandidates, 0, 40)
    );

    $payload = [
        'meta' => [
            'generated_at' => date(DATE_ATOM),
            'source_type' => $sourceType,
            'sitemap_file' => $sourceType === 'sitemap' ? $sitemapFile : null,
            'urls_file' => $sourceType === 'urls_file' ? $urlsFile : null,
            'source_urls' => $total,
            'processed_urls' => $processed,
            'fetched_ok' => $fetchedOk,
            'fetch_errors' => count($fetchErrors),
            'pages_with_breadcrumbs' => $pagesWithBreadcrumbs,
            'nodes' => count($allUrls),
            'edges' => count($parentOf),
            'roots' => count($rootCandidates),
            'concurrency' => $concurrency,
            'request_timeout' => $requestTimeout,
            'connect_timeout' => $connectTimeout,
            'enqueue_delay_ms' => $enqueueDelayMs,
            'max_urls' => $maxUrls,
            'errors_file' => $errorsFile,
        ],
        'roots_preview' => $rootsPreview,
        'conflicts_sample' => array_slice($conflicts, 0, 50),
        'fetch_errors_sample' => array_slice(
            array_map(
                static fn (string $url, string $error): array => ['url' => $url, 'error' => $error],
                array_keys($fetchErrors),
                array_values($fetchErrors)
            ),
            0,
            100
        ),
        'tree' => $forest,
    ];

    $fetchErrorRows = array_map(
        static fn (string $url, string $error): array => ['url' => $url, 'error' => $error],
        array_keys($fetchErrors),
        array_values($fetchErrors)
    );

    usort(
        $fetchErrorRows,
        static fn (array $left, array $right): int => strcmp((string) $left['url'], (string) $right['url'])
    );

    $outputDir = dirname($outputFile);
    $errorsDir = dirname($errorsFile);

    if (! is_dir($outputDir) && ! mkdir($outputDir, 0777, true) && ! is_dir($outputDir)) {
        throw new RuntimeException('Unable to create output directory: '.$outputDir);
    }

    if (! is_dir($errorsDir) && ! mkdir($errorsDir, 0777, true) && ! is_dir($errorsDir)) {
        throw new RuntimeException('Unable to create errors directory: '.$errorsDir);
    }

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

    if (! is_string($json)) {
        throw new RuntimeException('Failed to encode output JSON.');
    }

    $errorsPayload = [
        'generated_at' => date(DATE_ATOM),
        'source_type' => $sourceType,
        'sitemap_file' => $sourceType === 'sitemap' ? $sitemapFile : null,
        'urls_file' => $sourceType === 'urls_file' ? $urlsFile : null,
        'source_urls' => $total,
        'processed_urls' => $processed,
        'errors_count' => count($fetchErrorRows),
        'concurrency' => $concurrency,
        'request_timeout' => $requestTimeout,
        'connect_timeout' => $connectTimeout,
        'enqueue_delay_ms' => $enqueueDelayMs,
        'max_urls' => $maxUrls,
        'errors' => $fetchErrorRows,
    ];

    $errorsJson = json_encode($errorsPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

    if (! is_string($errorsJson)) {
        throw new RuntimeException('Failed to encode errors JSON.');
    }

    if (file_put_contents($outputFile, $json) === false) {
        throw new RuntimeException('Failed to write output file: '.$outputFile);
    }

    if (file_put_contents($errorsFile, $errorsJson) === false) {
        throw new RuntimeException('Failed to write errors file: '.$errorsFile);
    }

    stderr('Done.');
    stderr('Output: '.$outputFile);
    stderr('Errors: '.$errorsFile);
    stderr('Nodes: '.count($allUrls).', edges: '.count($parentOf).', roots: '.count($rootCandidates));

    return 0;
}

try {
    exit(main($argv));
} catch (Throwable $exception) {
    fwrite(STDERR, 'ERROR: '.$exception->getMessage().PHP_EOL);

    exit(1);
}
