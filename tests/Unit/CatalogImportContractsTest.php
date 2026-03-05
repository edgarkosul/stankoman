<?php

use App\Support\CatalogImport\Contracts\ImportProcessorInterface;
use App\Support\CatalogImport\Contracts\RecordParserInterface;
use App\Support\CatalogImport\Contracts\SourceResolverInterface;
use App\Support\CatalogImport\Contracts\SupplierAdapterInterface;
use App\Support\CatalogImport\DTO\ImportProcessResult;
use App\Support\CatalogImport\DTO\ProductPayload;
use App\Support\CatalogImport\DTO\ResolvedSource;
use App\Support\CatalogImport\Enums\ImportErrorLevel;
use App\Support\CatalogImport\Enums\ImportRunStatus;
use App\Support\CatalogImport\Yml\YandexMarketFeedAdapter;
use App\Support\CatalogImport\Yml\YmlStreamParser;

it('declares import run lifecycle statuses', function () {
    $statuses = collect(ImportRunStatus::cases())
        ->map(fn (ImportRunStatus $status): string => $status->value)
        ->all();

    expect($statuses)->toBe([
        'pending',
        'dry_run',
        'applied',
        'running',
        'completed',
        'failed',
        'cancelled',
    ]);

    expect(ImportRunStatus::Running->isTerminal())->toBeFalse();
    expect(ImportRunStatus::DryRun->isSuccessful())->toBeTrue();
    expect(ImportRunStatus::Failed->isTerminal())->toBeTrue();
});

it('parses records and maps payloads through shared contracts', function () {
    $parser = new YmlStreamParser;
    $adapter = new YandexMarketFeedAdapter;

    expect($parser)->toBeInstanceOf(RecordParserInterface::class);
    expect($adapter)->toBeInstanceOf(SupplierAdapterInterface::class);

    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<yml_catalog date="2026-03-05 00:00">
  <shop>
    <offers>
      <offer id="A1" available="true">
        <name>Contract Product</name>
        <price>100</price>
        <currencyId>RUB</currencyId>
        <categoryId>1</categoryId>
      </offer>
    </offers>
  </shop>
</yml_catalog>
XML;

    $path = tempnam(sys_get_temp_dir(), 'yml_');
    file_put_contents($path, $xml);

    try {
        $source = new ResolvedSource(
            source: $path,
            resolvedPath: $path,
        );

        $records = iterator_to_array($parser->parse($source));

        expect($records)->toHaveCount(1);

        $result = $adapter->mapRecord($records[0]);

        expect($result->isSuccess())->toBeTrue();
        expect($result->payload?->externalId)->toBe('A1');
        expect($result->payload?->name)->toBe('Contract Product');
        expect($result->errors)->toHaveCount(0);
    } finally {
        @unlink($path);
    }
});

it('returns fatal error for invalid adapter input type', function () {
    $result = (new YandexMarketFeedAdapter)->mapRecord(['id' => 'A1']);

    expect($result->isSuccess())->toBeFalse();
    expect($result->hasFatalError())->toBeTrue();
    expect($result->errors[0]->level)->toBe(ImportErrorLevel::Fatal);
    expect($result->errors[0]->code)->toBe('invalid_record_type');
});

it('defines source resolver and import processor contracts', function () {
    $resolver = new class implements SourceResolverInterface
    {
        public function resolve(string $source, array $options = []): ResolvedSource
        {
            $cacheKey = $options['cache_key'] ?? null;

            return new ResolvedSource(
                source: $source,
                resolvedPath: '/tmp/import-source.xml',
                cacheKey: is_string($cacheKey) ? $cacheKey : null,
                meta: ['transport' => 'http'],
            );
        }
    };

    $processor = new class implements ImportProcessorInterface
    {
        public function process(ProductPayload $payload, array $options = []): ImportProcessResult
        {
            $operation = ($options['dry_run'] ?? false) === true ? 'validated' : 'upserted';

            return new ImportProcessResult(operation: $operation);
        }
    };

    $resolved = $resolver->resolve('https://example.test/feed.xml', ['cache_key' => 'feed-cache']);
    $processed = $processor->process(
        new ProductPayload(
            externalId: 'A1',
            name: 'Demo Product',
        ),
        ['dry_run' => true],
    );

    expect($resolved->cacheKey)->toBe('feed-cache');
    expect($resolved->meta)->toBe(['transport' => 'http']);
    expect($processed->operation)->toBe('validated');
    expect($processed->isSuccess())->toBeTrue();
});
