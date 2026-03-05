<?php

use App\Support\CatalogImport\DTO\ResolvedSource;
use App\Support\CatalogImport\Html\HtmlDomParser;
use App\Support\CatalogImport\Html\HtmlRecord;

it('parses cards by css selector and extracts fields with fallback rules', function () {
    $html = <<<'HTML'
<!doctype html>
<html>
<body>
  <div class="card" data-id="A1">
    <a class="title">Пылесос A1</a>
    <span class="price" data-value="19990">19 990 ₽</span>
  </div>
  <div class="card" data-id="A2">
    <a class="fallback-title">Пылесос A2</a>
  </div>
</body>
</html>
HTML;

    $path = tempnam(sys_get_temp_dir(), 'html_cards_');
    file_put_contents($path, $html);

    try {
        $records = iterator_to_array((new HtmlDomParser)->parse(
            new ResolvedSource(source: $path, resolvedPath: $path),
            [
                'card_selector' => '.card',
                'fields' => [
                    'external_id' => [
                        ['selector' => '.card', 'attribute' => 'data-id'],
                        ['xpath' => './@data-id'],
                    ],
                    'name' => [
                        ['selector' => '.title'],
                        ['selector' => '.fallback-title'],
                    ],
                    'price' => [
                        ['selector' => '.price', 'attribute' => 'data-value'],
                        ['selector' => '.price'],
                    ],
                ],
            ],
        ));

        expect($records)->toHaveCount(2);
        expect($records[0])->toBeInstanceOf(HtmlRecord::class);
        expect($records[0]->fields['external_id'])->toBe('A1');
        expect($records[0]->fields['name'])->toBe('Пылесос A1');
        expect($records[0]->fields['price'])->toBe('19990');
        expect($records[1]->fields['name'])->toBe('Пылесос A2');
        expect($records[1]->fields['price'])->toBeNull();
    } finally {
        @unlink($path);
    }
});

it('supports xpath based card selection and extraction', function () {
    $html = <<<'HTML'
<!doctype html>
<html>
<body>
  <section>
    <article data-kind="product"><h2>Item One</h2></article>
    <article data-kind="product"><h2>Item Two</h2></article>
  </section>
</body>
</html>
HTML;

    $path = tempnam(sys_get_temp_dir(), 'html_xpath_');
    file_put_contents($path, $html);

    try {
        $records = iterator_to_array((new HtmlDomParser)->parse(
            new ResolvedSource(source: $path, resolvedPath: $path),
            [
                'card_xpath' => '//article[@data-kind="product"]',
                'fields' => [
                    'title' => [
                        ['xpath' => './/h2'],
                    ],
                ],
            ],
        ));

        expect($records)->toHaveCount(2);
        expect($records[0]->fields['title'])->toBe('Item One');
        expect($records[1]->fields['title'])->toBe('Item Two');
    } finally {
        @unlink($path);
    }
});

it('throws for invalid card selector', function () {
    $path = tempnam(sys_get_temp_dir(), 'html_invalid_selector_');
    file_put_contents($path, '<html><body><div></div></body></html>');

    try {
        expect(fn () => iterator_to_array((new HtmlDomParser)->parse(
            new ResolvedSource(source: $path, resolvedPath: $path),
            ['card_selector' => 'div['],
        )))->toThrow(\RuntimeException::class);
    } finally {
        @unlink($path);
    }
});
