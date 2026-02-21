<?php

use App\Support\Vactool\VactoolProductParser;

it('parses product payload from jsonld and inertia', function () {
    $inertiaPayload = [
        'props' => [
            'product' => [
                'title' => 'Inertia title should not override JSON-LD title',
                'description' => 'Inertia description',
                'offer' => [
                    'price' => ['unitValue' => 99000, 'currency' => 'RUB'],
                    'available' => 7,
                    'stock' => ['status' => 'in_stock'],
                ],
                'images' => [
                    'https://cdn.example.com/inertia-1.jpg',
                    ['url' => 'https://cdn.example.com/inertia-2.jpg'],
                ],
            ],
            'breadcrumbs' => [
                ['name' => 'Главная'],
                ['name' => 'Каталог'],
            ],
        ],
    ];

    $dataPage = htmlspecialchars(
        json_encode($inertiaPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ENT_QUOTES | ENT_HTML5,
        'UTF-8'
    );

    $jsonLd = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => 'Промышленный пылесос VT-9000',
        'description' => 'Описание из JSON-LD',
        'category' => 'Промышленные пылесосы',
        'brand' => ['name' => 'Vactool'],
        'image' => [
            'https://cdn.example.com/main.jpg',
            'https://cdn.example.com/inertia-1.jpg',
        ],
        'additionalProperty' => [
            ['name' => 'Мощность', 'value' => '2200 Вт'],
            ['name' => 'Мощность', 'value' => '2200 Вт'],
            ['name' => 'Объем бака', 'value' => '80 л'],
        ],
        'offers' => [
            'price' => '12345',
            'priceCurrency' => 'RUB',
            'availability' => 'https://schema.org/InStock',
            'inventoryLevel' => ['value' => 4],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $breadcrumbJsonLd = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            ['position' => 1, 'item' => ['name' => 'Каталог']],
            ['position' => 2, 'item' => ['name' => 'Пылесосы']],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $html = '<html><head>'
        .'<script type="application/ld+json">'.$jsonLd.'</script>'
        .'<script type="application/ld+json">'.$breadcrumbJsonLd.'</script>'
        .'</head><body>'
        .'<div id="app" data-page="'.$dataPage.'"></div>'
        .'</body></html>';

    $parsed = (new VactoolProductParser)->parse($html, 'https://vactool.ru/catalog/product-vt-9000');

    expect($parsed['source']['jsonld'])->toBeTrue();
    expect($parsed['source']['inertia'])->toBeTrue();
    expect($parsed['title'])->toBe('Промышленный пылесос VT-9000');
    expect($parsed['description'])->toBe('Описание из JSON-LD');
    expect($parsed['brand'])->toBe('Vactool');
    expect($parsed['category'])->toBe('Промышленные пылесосы');
    expect($parsed['price'])->toBe('12345');
    expect($parsed['currency'])->toBe('RUB');
    expect($parsed['stock_qty'])->toBe(4);
    expect($parsed['images'])->toContain('https://cdn.example.com/main.jpg');
    expect($parsed['images'])->toContain('https://cdn.example.com/inertia-1.jpg');
    expect($parsed['images'])->toContain('https://cdn.example.com/inertia-2.jpg');
    expect($parsed['specs'])->toHaveCount(2);
    expect($parsed['specs'])->toContain([
        'name' => 'Мощность',
        'value' => '2200 Вт',
        'source' => 'jsonld',
    ]);
    expect($parsed['specs'])->toContain([
        'name' => 'Объем бака',
        'value' => '80 л',
        'source' => 'jsonld',
    ]);
    expect($parsed['breadcrumbs'])->toContain('Каталог');
    expect($parsed['breadcrumbs'])->toContain('Пылесосы');
});

it('extracts specs from html when structured data is missing', function () {
    $html = <<<'HTML'
<html>
<body>
<dl>
    <dt class="list-props__title"><span>Мощность</span></dt>
    <dd class="list-props__value">2200 Вт</dd>
    <dt class="list-props__title"><span>Объем бака</span></dt>
    <dd class="list-props__value">80 л</dd>
</dl>
</body>
</html>
HTML;

    $parsed = (new VactoolProductParser)->parse($html, 'https://vactool.ru/catalog/product-vt-9100');

    expect($parsed['specs'])->toBe([
        ['name' => 'Мощность', 'value' => '2200 Вт', 'source' => 'dom'],
        ['name' => 'Объем бака', 'value' => '80 л', 'source' => 'dom'],
    ]);
});

it('merges specs from structured data and dom without replacing jsonld source', function () {
    $jsonLd = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => 'Пылесос VT-9200',
        'additionalProperty' => [
            ['name' => 'Мощность', 'value' => '2200 Вт'],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $html = '<html><head><script type="application/ld+json">'
        .$jsonLd
        .'</script></head><body>'
        .'<dl>'
        .'<dt class="list-props__title"><span>Мощность</span></dt>'
        .'<dd class="list-props__value">2200 Вт</dd>'
        .'<dt class="list-props__title"><span>Уровень шума</span></dt>'
        .'<dd class="list-props__value">64 дБ</dd>'
        .'</dl>'
        .'</body></html>';

    $parsed = (new VactoolProductParser)->parse($html, 'https://vactool.ru/catalog/product-vt-9200');

    expect($parsed['specs'])->toHaveCount(2);
    expect($parsed['specs'])->toContain([
        'name' => 'Мощность',
        'value' => '2200 Вт',
        'source' => 'jsonld',
    ]);
    expect($parsed['specs'])->toContain([
        'name' => 'Уровень шума',
        'value' => '64 дБ',
        'source' => 'dom',
    ]);
});

it('decodes product payloads with malformed utf8 bytes', function () {
    $invalidSuffix = chr(194);

    $jsonLd = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => 'Промышленный пылесос VACTOOL SA36M60',
        'brand' => ['name' => 'Vactool'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $jsonLd = str_replace('SA36M60', 'SA36M60'.$invalidSuffix, $jsonLd);

    $inertiaPayload = [
        'props' => [
            'product' => [
                'title' => 'Промышленный пылесос VACTOOL SA36M60',
                'brand' => ['name' => 'Vactool'],
            ],
        ],
    ];

    $dataPage = htmlspecialchars(
        json_encode($inertiaPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ENT_QUOTES | ENT_HTML5,
        'UTF-8'
    );

    $dataPage = str_replace('SA36M60', 'SA36M60'.$invalidSuffix, $dataPage);

    $html = '<html><head><script type="application/ld+json">'.$jsonLd.'</script></head><body>'
        .'<div id="app" data-page="'.$dataPage.'"></div>'
        .'</body></html>';

    $parsed = (new VactoolProductParser)->parse($html, 'https://vactool.ru/catalog/product-vt-utf8');

    expect($parsed['source']['jsonld'])->toBeTrue();
    expect($parsed['source']['inertia'])->toBeTrue();
    expect($parsed['title'])->toContain('VACTOOL SA36M60');
    expect($parsed['brand'])->toBe('Vactool');
});

it('falls back to html title when structured payload is invalid', function () {
    $html = <<<'HTML'
<html>
<head>
    <script type="application/ld+json">{"@type":"Product","name":"broken"</script>
    <title>Промышленный пылесос VACTOOL SA36M60 - Vactool</title>
</head>
<body>
    <h1>Промышленный пылесос VACTOOL SA36M60</h1>
</body>
</html>
HTML;

    $parsed = (new VactoolProductParser)->parse($html, 'https://vactool.ru/catalog/product-vt-fallback');

    expect($parsed['source']['jsonld'])->toBeFalse();
    expect($parsed['source']['inertia'])->toBeFalse();
    expect($parsed['title'])->toBe('Промышленный пылесос VACTOOL SA36M60');
});
