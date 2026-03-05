<?php

use App\Support\CatalogImport\DTO\ResolvedSource;
use App\Support\CatalogImport\Xml\XmlRecord;
use App\Support\CatalogImport\Xml\XmlStreamParser;

it('streams selected repeating xml node as records', function () {
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<catalog>
  <products>
    <product id="100">
      <name>Product 100</name>
    </product>
    <product id="200">
      <name>Product 200</name>
    </product>
  </products>
</catalog>
XML;

    $path = tempnam(sys_get_temp_dir(), 'xml_stream_');
    file_put_contents($path, $xml);

    try {
        $records = iterator_to_array((new XmlStreamParser)->parse(
            new ResolvedSource(source: $path, resolvedPath: $path),
            ['record_node' => 'product'],
        ));

        expect($records)->toHaveCount(2);
        expect($records[0])->toBeInstanceOf(XmlRecord::class);
        expect($records[0]->index)->toBe(0);
        expect($records[0]->nodeName)->toBe('product');
        expect($records[0]->attributes)->toBe(['id' => '100']);
        expect($records[0]->xml)->toContain('<name>Product 100</name>');
        expect($records[1]->attributes)->toBe(['id' => '200']);
    } finally {
        @unlink($path);
    }
});

it('detects source encoding and converts record xml to utf8', function () {
    $xmlUtf8 = <<<'XML'
<?xml version="1.0" encoding="windows-1251"?>
<catalog>
  <offer id="1">
    <name>Пылесос тестовый</name>
  </offer>
</catalog>
XML;

    $xmlCp1251 = mb_convert_encoding($xmlUtf8, 'Windows-1251', 'UTF-8');
    $path = tempnam(sys_get_temp_dir(), 'xml_cp1251_');
    file_put_contents($path, $xmlCp1251);

    try {
        $records = iterator_to_array((new XmlStreamParser)->parse(
            new ResolvedSource(source: $path, resolvedPath: $path),
            ['record_node' => 'offer', 'convert_to_utf8' => true],
        ));

        expect($records)->toHaveCount(1);
        expect(mb_check_encoding($records[0]->xml, 'UTF-8'))->toBeTrue();
        expect($records[0]->xml)->toContain('Пылесос тестовый');
    } finally {
        @unlink($path);
    }
});

it('throws when record node option is empty', function () {
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<catalog><offer id="1"/></catalog>
XML;

    $path = tempnam(sys_get_temp_dir(), 'xml_invalid_');
    file_put_contents($path, $xml);

    try {
        expect(fn () => iterator_to_array((new XmlStreamParser)->parse(
            new ResolvedSource(source: $path, resolvedPath: $path),
            ['record_node' => '  '],
        )))->toThrow(\RuntimeException::class);
    } finally {
        @unlink($path);
    }
});
