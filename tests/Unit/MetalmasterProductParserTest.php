<?php

use App\Support\Metalmaster\MetalmasterProductParser;

it('selects model-specific specs column by matching model name from title and h1', function () {
    $jsonLd = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => 'Листогиб Metal Master Van Mark IM 1465',
        'description' => 'Описание',
        'brand' => [
            '@type' => 'Brand',
            'name' => 'MetalMaster',
        ],
        'offers' => [
            '@type' => 'Offer',
            'priceCurrency' => 'RUB',
            'price' => 0,
            'availability' => 'https://schema.org/InStock',
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $html = '<html><head>'
        .'<title>Ручной листогиб Metal Master Van Mark IM 1465</title>'
        .'<script type="application/ld+json">'.$jsonLd.'</script>'
        .'</head><body>'
        .'<h1>Листогиб Metal Master Van Mark IM 1465</h1>'
        .'<div class="wrapper-characteristics"><table><tbody>'
        .'<tr>'
        .'<th>Модель</th>'
        .'<th>IM 665</th><th>IM 865</th><th>IM 1065</th><th>IM 1265</th><th>IM 1465</th>'
        .'</tr>'
        .'<tr><td>Глубина подачи, мм</td><td colspan="5">520</td></tr>'
        .'<tr><td>Рабочая длина, м</td><td>1,85</td><td>2,6</td><td>3,2</td><td>3,8</td><td>4,4</td></tr>'
        .'<tr><td>Количество С-прижимов, шт</td><td>5</td><td>5</td><td>7</td><td>9</td><td>10</td></tr>'
        .'<tr><td>Габаритные размеры, мм</td><td>457х762х2057</td><td>457х762х2667</td><td>533х762х3276</td><td>533х762х3886</td><td>533х762х4495</td></tr>'
        .'</tbody></table></div>'
        .'</body></html>';

    $parsed = (new MetalmasterProductParser)->parse($html, 'https://metalmaster.ru/ruchnye/van-mark-im-1465/', 'ruchnye');
    $specs = is_array($parsed['specs'] ?? null) ? $parsed['specs'] : [];
    $map = metalmasterSpecsMap($specs);

    expect($map['Глубина подачи, мм'] ?? null)->toBe('520');
    expect($map['Рабочая длина, м'] ?? null)->toBe('4,4');
    expect($map['Количество С-прижимов, шт'] ?? null)->toBe('10');
    expect($map['Габаритные размеры, мм'] ?? null)->toBe('533х762х4495');
    expect($map['Рабочая длина, м'] ?? null)->not->toBe('1,85');
});

it('extracts values from matched model range when header uses colspan', function () {
    $jsonLd = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => 'Ленточнопильный станок METAL MASTER BSM-115',
        'description' => 'Описание',
        'brand' => [
            '@type' => 'Brand',
            'name' => 'MetalMaster',
        ],
        'offers' => [
            '@type' => 'Offer',
            'priceCurrency' => 'RUB',
            'price' => 41965,
            'availability' => 'https://schema.org/InStock',
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $html = '<html><head>'
        .'<title>Ручной ленточнопильный станок Metal Master BSM-115</title>'
        .'<script type="application/ld+json">'.$jsonLd.'</script>'
        .'</head><body>'
        .'<h1>Ленточнопильный станок METAL MASTER BSM-115</h1>'
        .'<div class="wrapper-characteristics"><table><tbody>'
        .'<tr><th>Модель</th><th colspan="4">BSM-115</th></tr>'
        .'<tr><td>Напряжение (В)</td><td colspan="4">220</td></tr>'
        .'<tr><th colspan="5">Режущая способность</th></tr>'
        .'<tr>'
        .'<td>90&deg; (мм)</td>'
        .'<td rowspan="2"><img src="/img-1.png" alt="img"></td>'
        .'<td>100</td>'
        .'<td rowspan="2"><img src="/img-2.png" alt="img"></td>'
        .'<td>100х150</td>'
        .'</tr>'
        .'<tr><td>45&deg; (мм)</td><td>75</td><td>100х75</td></tr>'
        .'<tr><td>Вес (брутто/нетто) (кг)</td><td colspan="4">64/61</td></tr>'
        .'</tbody></table></div>'
        .'</body></html>';

    $parsed = (new MetalmasterProductParser)->parse($html, 'https://metalmaster.ru/ruchnye-lentochnopilnye-stanki/bsm-115/', 'ruchnye');
    $specs = is_array($parsed['specs'] ?? null) ? $parsed['specs'] : [];
    $map = metalmasterSpecsMap($specs);

    expect($map['Напряжение (В)'] ?? null)->toBe('220');
    expect($map['90° (мм)'] ?? null)->toBe('100 / 100х150');
    expect($map['45° (мм)'] ?? null)->toBe('75 / 100х75');
    expect($map['Вес (брутто/нетто) (кг)'] ?? null)->toBe('64/61');
    expect(array_key_exists('Режущая способность', $map))->toBeFalse();
});

it('ignores noisy tables outside wrapper-characteristics block', function () {
    $jsonLd = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => 'Листогиб METAL MASTER LBM 200 PRO',
        'description' => 'Описание',
        'brand' => [
            '@type' => 'Brand',
            'name' => 'MetalMaster',
        ],
        'offers' => [
            '@type' => 'Offer',
            'priceCurrency' => 'RUB',
            'price' => 120000,
            'availability' => 'https://schema.org/InStock',
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $html = '<html><head>'
        .'<title>Листогиб METAL MASTER LBM 200 PRO</title>'
        .'<script type="application/ld+json">'.$jsonLd.'</script>'
        .'</head><body>'
        .'<h1>Листогиб METAL MASTER LBM 200 PRO</h1>'
        .'<div class="wrapper-characteristics"><table><tbody>'
        .'<tr><th>Модель</th><th>LBM 200 PRO</th><th>LBM 250 PRO</th></tr>'
        .'<tr><td>Рабочая длина, мм</td><td>2 150 мм.</td><td>2 650 мм.</td></tr>'
        .'<tr><td>Макс. толщина металла сталь, σв&lt;320 МПа, мм</td><td>0,8</td><td>0,7</td></tr>'
        .'<tr><td>Толщина металла, нержавеющая сталь, σв&lt;600 МПа, мм</td><td>0,6</td><td>0,5</td></tr>'
        .'</tbody></table></div>'
        .'<div class="wrapper__content-product"><table><tbody>'
        .'<tr><td>Вентиляция</td><td>Доборные элементы</td></tr>'
        .'<tr><td>Цена Выберите подходящую Вам модель!</td><td>935 руб.</td></tr>'
        .'</tbody></table></div>'
        .'</body></html>';

    $parsed = (new MetalmasterProductParser)->parse($html, 'https://metalmaster.ru/listogiby/lbm-200-pro/', 'listogiby');
    $specs = is_array($parsed['specs'] ?? null) ? $parsed['specs'] : [];
    $map = metalmasterSpecsMap($specs);

    expect($map['Рабочая длина, мм'] ?? null)->toBe('2 150 мм.');
    expect($map['Макс. толщина металла сталь, σв<320 МПа, мм'] ?? null)->toBe('0,8');
    expect($map['Толщина металла, нержавеющая сталь, σв<600 МПа, мм'] ?? null)->toBe('0,6');
    expect(array_key_exists('Вентиляция', $map))->toBeFalse();
    expect(array_key_exists('Цена Выберите подходящую Вам модель!', $map))->toBeFalse();
});

it('extracts main image from og image and gallery only from product fancybox links', function () {
    $jsonLd = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => 'Листогиб METAL MASTER LBM-200 PRO',
        'description' => 'Описание',
        'brand' => [
            '@type' => 'Brand',
            'name' => 'MetalMaster',
        ],
        'image' => [
            'https://metalmaster.ru/files/products/from-jsonld.1024x1024w.jpg',
        ],
        'offers' => [
            '@type' => 'Offer',
            'priceCurrency' => 'RUB',
            'price' => 239872,
            'availability' => 'https://schema.org/InStock',
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $html = '<html><head>'
        .'<meta property="og:image" content="https://metalmaster.ru/files/products/lbm200pronew-gal1.1024x1024w.jpg?abc123">'
        .'<script type="application/ld+json">'.$jsonLd.'</script>'
        .'</head><body>'
        .'<div class="top__product">'
        .'  <div class="product__img">'
        .'    <a href="/files/products/lbm200pronew-gal1.1024x1024w.jpg?abc123" class="fancybox" data-fancybox="img_gal">'
        .'      <img src="/files/products/lbm200pronew-gal1.392x355w.jpg?thumb">'
        .'    </a>'
        .'  </div>'
        .'  <ul>'
        .'    <li><a href="/files/products/lbm200pronew1.1024x1024w.jpg?1" class="fancybox" data-fancybox="img_gal2"><img src="/files/products/lbm200pronew1.65x65.jpg?1"></a></li>'
        .'    <li><a href="/files/products/lbm200pronew2.1024x1024w.jpg?2" class="fancybox" data-fancybox="img_gal2"><img src="/files/products/lbm200pronew2.65x65.jpg?2"></a></li>'
        .'    <li><a href="/files/products/lbm200pronew2.1024x1024w.jpg?2" class="fancybox" data-fancybox="img_gal"><img src="/files/products/lbm200pronew2.65x65.jpg?2"></a></li>'
        .'    <li><a style="cursor:pointer;" data-type="iframe" data-src="https://vk.com/video_ext.php?id=1" class="fancybox" data-fancybox="img_gal2" data-fancybox-type="iframevk"><img src="/assets/images/screen-video/video-preview.jpg"></a></li>'
        .'  </ul>'
        .'</div>'
        .'<img src="/design/metalmasternew/img/soc_ic_orig/telegram.svg">'
        .'</body></html>';

    $parsed = (new MetalmasterProductParser)->parse($html, 'https://metalmaster.ru/ruchnye/metal-master-lbm-200-pro/', 'ruchnye');
    $gallery = is_array($parsed['gallery'] ?? null) ? $parsed['gallery'] : [];

    expect($parsed['image'] ?? null)->toBe('https://metalmaster.ru/files/products/lbm200pronew-gal1.1024x1024w.jpg?abc123');
    expect($parsed['thumb'] ?? null)->toBe('https://metalmaster.ru/files/products/lbm200pronew-gal1.1024x1024w.jpg?abc123');
    expect($gallery)->toContain('https://metalmaster.ru/files/products/lbm200pronew-gal1.1024x1024w.jpg?abc123');
    expect($gallery)->toContain('https://metalmaster.ru/files/products/lbm200pronew1.1024x1024w.jpg?1');
    expect($gallery)->toContain('https://metalmaster.ru/files/products/lbm200pronew2.1024x1024w.jpg?2');
    expect(collect($gallery)->contains(fn (string $url): bool => str_contains($url, '/assets/images/screen-video/')))->toBeFalse();
    expect(collect($gallery)->contains(fn (string $url): bool => str_contains($url, '/soc_ic_orig/')))->toBeFalse();
    expect($gallery)->toHaveCount(3);
});

it('skips decorative characteristics header row in simple two column specs table', function () {
    $jsonLd = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => 'Листогиб METAL MASTER LBM-2507',
        'description' => 'Описание',
        'brand' => [
            '@type' => 'Brand',
            'name' => 'MetalMaster',
        ],
        'offers' => [
            '@type' => 'Offer',
            'priceCurrency' => 'RUB',
            'price' => 219000,
            'availability' => 'https://schema.org/InStock',
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $html = '<html><head>'
        .'<script type="application/ld+json">'.$jsonLd.'</script>'
        .'</head><body>'
        .'<h1>Листогиб METAL MASTER LBM-2507</h1>'
        .'<div class="wrapper-characteristics"><table><tbody>'
        .'<tr><td><strong>Характеристики</strong></td><td align="center"><strong>LBM-2507</strong></td></tr>'
        .'<tr><td>Длина сгибаемой детали, мм</td><td align="center">2650</td></tr>'
        .'<tr><td>Макс. угол гиба, град</td><td align="center">135</td></tr>'
        .'<tr><td>Масса станка, кг</td><td align="center">300</td></tr>'
        .'</tbody></table></div>'
        .'</body></html>';

    $parsed = (new MetalmasterProductParser)->parse($html, 'https://metalmaster.ru/ruchnye/lbm-2507/', 'ruchnye');
    $specs = is_array($parsed['specs'] ?? null) ? $parsed['specs'] : [];
    $map = metalmasterSpecsMap($specs);

    expect(array_key_exists('Характеристики', $map))->toBeFalse();
    expect($map['Длина сгибаемой детали, мм'] ?? null)->toBe('2650');
    expect($map['Макс. угол гиба, град'] ?? null)->toBe('135');
    expect($map['Масса станка, кг'] ?? null)->toBe('300');
});

/**
 * @param  array<int, array{name?: mixed, value?: mixed}>  $specs
 * @return array<string, string>
 */
function metalmasterSpecsMap(array $specs): array
{
    $map = [];

    foreach ($specs as $spec) {
        $name = trim((string) ($spec['name'] ?? ''));
        $value = trim((string) ($spec['value'] ?? ''));

        if ($name === '' || $value === '') {
            continue;
        }

        $map[$name] = $value;
    }

    return $map;
}
