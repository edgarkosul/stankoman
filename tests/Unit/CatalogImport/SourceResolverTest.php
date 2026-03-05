<?php

use App\Support\CatalogImport\Sources\SourceResolver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

it('resolves existing local file source', function () {
    $path = tempnam(sys_get_temp_dir(), 'catalog_source_');
    file_put_contents($path, '<root/>');

    try {
        $resolved = (new SourceResolver)->resolve($path);

        expect($resolved->source)->toBe($path);
        expect($resolved->resolvedPath)->toBe($path);
        expect(data_get($resolved->meta, 'transport'))->toBe('file');
        expect((int) data_get($resolved->meta, 'size_bytes'))->toBeGreaterThan(0);
    } finally {
        @unlink($path);
    }
});

it('downloads remote source and stores payload in cache', function () {
    Http::preventStrayRequests();

    $attempts = 0;

    Http::fake(function () use (&$attempts) {
        $attempts++;

        if ($attempts === 1) {
            return Http::failedConnection();
        }

        return Http::response('<feed><offer id="A1"/></feed>', 200, [
            'ETag' => '"feed-v1"',
            'Last-Modified' => 'Wed, 05 Mar 2026 00:00:00 GMT',
            'Content-Type' => 'application/xml; charset=utf-8',
        ]);
    });

    $cacheDir = storage_path('framework/testing/catalog-import/'.Str::uuid()->toString());
    $resolver = new SourceResolver;

    try {
        $resolved = $resolver->resolve('https://example.test/feed.xml', [
            'cache_dir' => $cacheDir,
            'retry_times' => 2,
            'retry_sleep_ms' => 1,
            'timeout' => 5,
            'connect_timeout' => 5,
        ]);

        expect($attempts)->toBe(2);
        expect($resolved->cacheKey)->not->toBeNull();
        expect(is_file($resolved->resolvedPath))->toBeTrue();
        expect(file_get_contents($resolved->resolvedPath))->toContain('<offer id="A1"');
        expect(data_get($resolved->meta, 'transport'))->toBe('http');
        expect(data_get($resolved->meta, 'cached'))->toBeFalse();
        expect(data_get($resolved->meta, 'etag'))->toBe('"feed-v1"');
    } finally {
        File::deleteDirectory($cacheDir);
    }
});

it('sends conditional headers and reuses cache when source responds with 304', function () {
    Http::preventStrayRequests();

    $cacheDir = storage_path('framework/testing/catalog-import/'.Str::uuid()->toString());
    $resolver = new SourceResolver;

    try {
        Http::fake([
            'https://example.test/feed.xml' => Http::sequence()
                ->push('<feed><offer id="A1"/></feed>', 200, [
                    'ETag' => '"feed-v1"',
                    'Last-Modified' => 'Wed, 05 Mar 2026 00:00:00 GMT',
                ])
                ->push('', 304),
        ]);

        $first = $resolver->resolve('https://example.test/feed.xml', [
            'cache_dir' => $cacheDir,
        ]);

        $second = $resolver->resolve('https://example.test/feed.xml', [
            'cache_dir' => $cacheDir,
        ]);

        Http::assertSentCount(2);
        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://example.test/feed.xml'
                && $request->hasHeader('If-None-Match', '"feed-v1"')
                && $request->hasHeader('If-Modified-Since', 'Wed, 05 Mar 2026 00:00:00 GMT');
        });

        expect($second->resolvedPath)->toBe($first->resolvedPath);
        expect(data_get($second->meta, 'cached'))->toBeTrue();
        expect((int) data_get($second->meta, 'status'))->toBe(304);
    } finally {
        File::deleteDirectory($cacheDir);
    }
});

it('throws for missing local source', function () {
    $missing = '/tmp/catalog-import-missing-'.Str::uuid()->toString().'.xml';

    expect(fn () => (new SourceResolver)->resolve($missing))
        ->toThrow(\RuntimeException::class);
});
