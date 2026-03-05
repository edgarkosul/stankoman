<?php

namespace App\Support\CatalogImport\Sources;

use App\Support\CatalogImport\Contracts\SourceResolverInterface;
use App\Support\CatalogImport\DTO\ResolvedSource;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class SourceResolver implements SourceResolverInterface
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function resolve(string $source, array $options = []): ResolvedSource
    {
        if ($this->isHttpSource($source)) {
            return $this->resolveHttpSource($source, $options);
        }

        return $this->resolveLocalSource($source, $options);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function resolveLocalSource(string $source, array $options): ResolvedSource
    {
        $resolvedPath = $this->resolveLocalPath($source);

        if ($resolvedPath === null || ! is_file($resolvedPath) || ! is_readable($resolvedPath)) {
            throw new RuntimeException("Local source was not found or is not readable: {$source}");
        }

        $cacheKey = $this->stringOption($options, 'cache_key');
        $sizeBytes = @filesize($resolvedPath);
        $modifiedAt = @filemtime($resolvedPath);

        $meta = [
            'transport' => 'file',
        ];

        if (is_int($sizeBytes)) {
            $meta['size_bytes'] = $sizeBytes;
        }

        if (is_int($modifiedAt)) {
            $meta['modified_at'] = date(DATE_ATOM, $modifiedAt);
        }

        return new ResolvedSource(
            source: $source,
            resolvedPath: $resolvedPath,
            cacheKey: $cacheKey,
            meta: $meta,
        );
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function resolveHttpSource(string $source, array $options): ResolvedSource
    {
        $cacheKey = $this->resolveCacheKey($source, $options);
        $cacheDirectory = $this->resolveCacheDirectory($options);
        $payloadPath = $cacheDirectory.'/'.$cacheKey.'.payload';
        $metaPath = $cacheDirectory.'/'.$cacheKey.'.meta.json';

        $this->ensureDirectory($cacheDirectory);

        $cachedMeta = $this->readCacheMeta($metaPath);
        $headers = $this->resolveRequestHeaders($options, $cachedMeta);
        $timeout = $this->floatOption($options, 'timeout', 30.0);
        $connectTimeout = $this->floatOption($options, 'connect_timeout', 10.0);
        $retryTimes = $this->intOption($options, 'retry_times', 3);
        $retrySleepMs = $this->intOption($options, 'retry_sleep_ms', 200);

        try {
            $response = Http::timeout($timeout)
                ->connectTimeout($connectTimeout)
                ->retry($retryTimes, $retrySleepMs, function ($exception): bool {
                    return $exception instanceof ConnectionException;
                }, throw: false)
                ->withHeaders($headers)
                ->get($source);
        } catch (ConnectionException $exception) {
            throw new RuntimeException("Unable to download source because of connection failure: {$source}", 0, $exception);
        }

        if ($response->status() === 304) {
            if (! is_file($payloadPath)) {
                throw new RuntimeException("Source responded with 304, but cached payload was not found: {$source}");
            }

            return new ResolvedSource(
                source: $source,
                resolvedPath: $payloadPath,
                cacheKey: $cacheKey,
                meta: array_merge($cachedMeta, [
                    'transport' => 'http',
                    'status' => 304,
                    'cached' => true,
                    'url' => $source,
                ]),
            );
        }

        if (! $response->successful()) {
            throw new RuntimeException("Unable to download source: {$source} (HTTP {$response->status()})");
        }

        $payload = $response->body();

        if (@file_put_contents($payloadPath, $payload) === false) {
            throw new RuntimeException("Unable to store downloaded source to cache path: {$payloadPath}");
        }

        $meta = $this->buildHttpMeta($source, $response->status(), $response->header('ETag'), $response->header('Last-Modified'), $response->header('Content-Type'));
        $this->writeCacheMeta($metaPath, $meta);

        return new ResolvedSource(
            source: $source,
            resolvedPath: $payloadPath,
            cacheKey: $cacheKey,
            meta: array_merge($meta, [
                'cached' => false,
            ]),
        );
    }

    private function isHttpSource(string $source): bool
    {
        if (filter_var($source, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = parse_url($source, PHP_URL_SCHEME);

        if (! is_string($scheme)) {
            return false;
        }

        return in_array(strtolower($scheme), ['http', 'https'], true);
    }

    private function resolveLocalPath(string $source): ?string
    {
        $candidates = [$source];

        if (! $this->isAbsolutePath($source)) {
            $candidates[] = base_path($source);
        }

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            if (! file_exists($candidate)) {
                continue;
            }

            $realPath = realpath($candidate);

            return is_string($realPath) ? $realPath : $candidate;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function resolveCacheKey(string $source, array $options): string
    {
        $cacheKey = $this->stringOption($options, 'cache_key');

        if ($cacheKey !== null) {
            return preg_replace('/[^A-Za-z0-9._-]/', '_', $cacheKey) ?: sha1($source);
        }

        return sha1($source);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function resolveCacheDirectory(array $options): string
    {
        $cacheDirectory = $this->stringOption($options, 'cache_dir');

        if ($cacheDirectory !== null) {
            return rtrim($cacheDirectory, DIRECTORY_SEPARATOR);
        }

        return storage_path('app/catalog-import/sources');
    }

    private function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (! @mkdir($path, 0775, true) && ! is_dir($path)) {
            throw new RuntimeException("Unable to create source cache directory: {$path}");
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readCacheMeta(string $metaPath): array
    {
        if (! is_file($metaPath)) {
            return [];
        }

        $raw = @file_get_contents($metaPath);

        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function writeCacheMeta(string $metaPath, array $meta): void
    {
        $payload = json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (! is_string($payload) || @file_put_contents($metaPath, $payload) === false) {
            throw new RuntimeException("Unable to write source cache metadata: {$metaPath}");
        }
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $cachedMeta
     * @return array<string, string>
     */
    private function resolveRequestHeaders(array $options, array $cachedMeta): array
    {
        $headers = $this->normalizeHeaders($options['headers'] ?? []);
        $useConditional = $this->boolOption($options, 'use_conditional_headers', true);

        if (! $useConditional) {
            return $headers;
        }

        $etag = is_string($cachedMeta['etag'] ?? null) ? trim((string) $cachedMeta['etag']) : null;
        $lastModified = is_string($cachedMeta['last_modified'] ?? null) ? trim((string) $cachedMeta['last_modified']) : null;

        if ($etag !== null && $etag !== '' && ! $this->hasHeader($headers, 'If-None-Match')) {
            $headers['If-None-Match'] = $etag;
        }

        if ($lastModified !== null && $lastModified !== '' && ! $this->hasHeader($headers, 'If-Modified-Since')) {
            $headers['If-Modified-Since'] = $lastModified;
        }

        return $headers;
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function hasHeader(array $headers, string $name): bool
    {
        $name = strtolower($name);

        foreach ($headers as $headerName => $value) {
            if (strtolower($headerName) === $name && trim($value) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    private function normalizeHeaders(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $headers = [];

        foreach ($value as $name => $headerValue) {
            if (! is_string($name) || trim($name) === '') {
                continue;
            }

            if (! is_scalar($headerValue)) {
                continue;
            }

            $headers[$name] = (string) $headerValue;
        }

        return $headers;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildHttpMeta(
        string $url,
        int $status,
        mixed $etag,
        mixed $lastModified,
        mixed $contentType,
    ): array {
        $meta = [
            'transport' => 'http',
            'url' => $url,
            'status' => $status,
            'etag' => $this->normalizedHeaderValue($etag),
            'last_modified' => $this->normalizedHeaderValue($lastModified),
            'content_type' => $this->normalizedHeaderValue($contentType),
            'downloaded_at' => date(DATE_ATOM),
        ];

        return array_filter($meta, function (mixed $value): bool {
            return $value !== null && $value !== '';
        });
    }

    private function normalizedHeaderValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function stringOption(array $options, string $key): ?string
    {
        $value = $options[$key] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function intOption(array $options, string $key, int $default): int
    {
        $value = $options[$key] ?? null;

        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return $default;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function floatOption(array $options, string $key, float $default): float
    {
        $value = $options[$key] ?? null;

        if (is_float($value) && $value > 0) {
            return $value;
        }

        if (is_int($value) && $value > 0) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value) && (float) $value > 0) {
            return (float) $value;
        }

        return $default;
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

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1;
    }
}
