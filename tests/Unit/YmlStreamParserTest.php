<?php

use App\Support\CatalogImport\Yml\YandexMarketFeedAdapter;
use App\Support\CatalogImport\Yml\YmlStreamParser;

it('streams categories and offers from yml feed', function () {
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<yml_catalog date="2026-03-05 00:00">
  <shop>
    <name>Test</name>
    <company>Test</company>
    <url>https://example.test</url>
    <currencies>
      <currency id="RUB" rate="1"/>
    </currencies>
    <categories>
      <category id="1">Category 1</category>
      <category id="2" parentId="1">Category 2</category>
    </categories>
    <offers>
      <offer id="A1" available="true">
        <name>Simple Product</name>
        <price>123</price>
        <currencyId>RUB</currencyId>
        <categoryId>1</categoryId>
      </offer>
      <offer id="A2" type="vendor.model" available="false">
        <typePrefix>Пылесос</typePrefix>
        <vendor>Vactool</vendor>
        <model>VT-9000</model>
        <categoryId>2</categoryId>
      </offer>
    </offers>
  </shop>
</yml_catalog>
XML;

    $path = tempnam(sys_get_temp_dir(), 'yml_');
    file_put_contents($path, $xml);

    try {
        $stream = (new YmlStreamParser)->open($path);

        expect($stream->categories)->toBe([
            1 => 'Category 1',
            2 => 'Category 2',
        ]);
        expect($stream->categoryParents)->toBe([
            1 => null,
            2 => 1,
        ]);

        $offers = iterator_to_array($stream->offers);

        expect($offers)->toHaveCount(2);

        expect($offers[0]->id)->toBe('A1');
        expect($offers[0]->type)->toBeNull();
        expect($offers[0]->available)->toBeTrue();
        expect($offers[0]->categoryId)->toBe(1);

        expect($offers[1]->id)->toBe('A2');
        expect($offers[1]->type)->toBe('vendor.model');
        expect($offers[1]->available)->toBeFalse();
        expect($offers[1]->categoryId)->toBe(2);
    } finally {
        @unlink($path);
    }
});

it('parses all categories and offers from minified yml feed', function () {
    $xml = '<?xml version="1.0" encoding="UTF-8"?><yml_catalog date="2026-03-15 00:00"><shop><categories><category id="16">Круглопильные станки</category><category id="17">Ленточнопильные станки</category><category id="18">Фуговальные станки</category><category id="19">Рейсмусовые станки</category><category id="21">Фрезерные станки</category><category id="20">Фуговально-рейсмусовые станки</category><category id="22">Шлифовальные станки</category><category id="93">Сверлильные станки</category><category id="23">Токарные станки</category><category id="69">Торцовочные пилы</category><category id="5014">Лобзиковые станки</category><category id="70">Вытяжные установки (стружкоотсосы)</category><category id="12785">Долбежно-пазовальные станки</category></categories><offers><offer id="A1" available="true"><name>Станок 1</name><price>100</price><currencyId>RUB</currencyId><categoryId>16</categoryId></offer><offer id="A2" available="true"><name>Станок 2</name><price>200</price><currencyId>RUB</currencyId><categoryId>17</categoryId></offer><offer id="A3" available="true"><name>Станок 3</name><price>300</price><currencyId>RUB</currencyId><categoryId>18</categoryId></offer><offer id="A4" available="true"><name>Станок 4</name><price>400</price><currencyId>RUB</currencyId><categoryId>19</categoryId></offer></offers></shop></yml_catalog>';

    $path = tempnam(sys_get_temp_dir(), 'yml_');
    file_put_contents($path, $xml);

    try {
        $stream = (new YmlStreamParser)->open($path);

        expect($stream->categories)->toBe([
            16 => 'Круглопильные станки',
            17 => 'Ленточнопильные станки',
            18 => 'Фуговальные станки',
            19 => 'Рейсмусовые станки',
            21 => 'Фрезерные станки',
            20 => 'Фуговально-рейсмусовые станки',
            22 => 'Шлифовальные станки',
            93 => 'Сверлильные станки',
            23 => 'Токарные станки',
            69 => 'Торцовочные пилы',
            5014 => 'Лобзиковые станки',
            70 => 'Вытяжные установки (стружкоотсосы)',
            12785 => 'Долбежно-пазовальные станки',
        ]);

        $offers = iterator_to_array($stream->offers);

        expect(array_map(fn ($offer) => $offer->id, $offers))->toBe([
            'A1',
            'A2',
            'A3',
            'A4',
        ]);
        expect(array_map(fn ($offer) => $offer->categoryId, $offers))->toBe([
            16,
            17,
            18,
            19,
        ]);
    } finally {
        @unlink($path);
    }
});

it('maps simplified and vendor.model offers into product payloads', function () {
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<yml_catalog date="2026-03-05 00:00">
  <shop>
    <categories>
      <category id="1">Category 1</category>
    </categories>
    <offers>
      <offer id="A1" available="true">
        <name>Simple Product</name>
        <description>Simple description.</description>
        <price>123</price>
        <currencyId>RUB</currencyId>
        <param name="Мощность" unit="Вт">1200</param>
        <param name="Цвет">  Красный </param>
        <param name="Цвет">Красный</param>
        <param name="">ignored</param>
        <picture>https://example.test/images/simple-1.jpg</picture>
        <picture>https://example.test/images/simple-2.jpg</picture>
        <categoryId>1</categoryId>
      </offer>
      <offer id="A2" type="vendor.model" available="false">
        <typePrefix>Пылесос</typePrefix>
        <vendor>Vactool</vendor>
        <model>VT-9000</model>
        <description>Vendor model description.</description>
        <price>999</price>
        <currencyId>RUB</currencyId>
        <param name="Напряжение" unit="В">220</param>
        <param name="Комплектация">Шланг</param>
        <picture>https://example.test/images/vm-1.jpg</picture>
        <picture>
          https://example.test/images/vm-2.jpg
        </picture>
        <categoryId>1</categoryId>
      </offer>
    </offers>
  </shop>
</yml_catalog>
XML;

    $path = tempnam(sys_get_temp_dir(), 'yml_');
    file_put_contents($path, $xml);

    try {
        $stream = (new YmlStreamParser)->open($path);
        $offers = iterator_to_array($stream->offers);

        $adapter = new YandexMarketFeedAdapter;

        $simple = $adapter->mapOffer($offers[0]);
        expect($simple->isSuccess())->toBeTrue();
        expect($simple->payload?->externalId)->toBe('A1');
        expect($simple->payload?->name)->toBe('Simple Product');
        expect($simple->payload?->description)->toBe('Simple description.');
        expect($simple->payload?->priceAmount)->toBe(123);
        expect($simple->payload?->currency)->toBe('RUB');
        expect($simple->payload?->sku)->toBe('A1');
        expect($simple->payload?->inStock)->toBeTrue();
        expect($simple->payload?->images)->toBe([
            'https://example.test/images/simple-1.jpg',
            'https://example.test/images/simple-2.jpg',
        ]);
        expect($simple->payload?->attributes)->toBe([
            ['name' => 'Мощность', 'value' => '1200 Вт', 'source' => 'yml'],
            ['name' => 'Цвет', 'value' => 'Красный', 'source' => 'yml'],
        ]);

        $vendorModel = $adapter->mapOffer($offers[1]);
        expect($vendorModel->isSuccess())->toBeTrue();
        expect($vendorModel->payload?->externalId)->toBe('A2');
        expect($vendorModel->payload?->name)->toBe('Пылесос Vactool VT-9000');
        expect($vendorModel->payload?->description)->toBe('Vendor model description.');
        expect($vendorModel->payload?->brand)->toBe('Vactool');
        expect($vendorModel->payload?->priceAmount)->toBe(999);
        expect($vendorModel->payload?->currency)->toBe('RUB');
        expect($vendorModel->payload?->sku)->toBe('A2');
        expect($vendorModel->payload?->inStock)->toBeFalse();
        expect($vendorModel->payload?->images)->toBe([
            'https://example.test/images/vm-1.jpg',
            'https://example.test/images/vm-2.jpg',
        ]);
        expect($vendorModel->payload?->attributes)->toBe([
            ['name' => 'Напряжение', 'value' => '220 В', 'source' => 'yml'],
            ['name' => 'Комплектация', 'value' => 'Шланг', 'source' => 'yml'],
        ]);
    } finally {
        @unlink($path);
    }
});

it('falls back to <name> for vendor.model offers when <model> is missing', function () {
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<yml_catalog date="2026-03-05 00:00">
  <shop>
    <offers>
      <offer id="A2" type="vendor.model" available="true">
        <typePrefix>Компрессоры</typePrefix>
        <vendor>FoxWeld</vendor>
        <name>Компрессор безмасляный AERO 180/0F N10</name>
        <price>8130</price>
        <currencyId>RUB</currencyId>
        <categoryId>137</categoryId>
      </offer>
    </offers>
  </shop>
</yml_catalog>
XML;

    $path = tempnam(sys_get_temp_dir(), 'yml_');
    file_put_contents($path, $xml);

    try {
        $stream = (new YmlStreamParser)->open($path);
        $offers = iterator_to_array($stream->offers);

        $result = (new YandexMarketFeedAdapter)->mapOffer($offers[0]);

        expect($result->isSuccess())->toBeTrue();
        expect($result->payload?->name)->toBe('Компрессор безмасляный AERO 180/0F N10');
        expect($result->payload?->brand)->toBe('FoxWeld');
        expect($result->payload?->priceAmount)->toBe(8130);
    } finally {
        @unlink($path);
    }
});

it('converts relative image sources in html description to absolute urls using picture host', function () {
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<yml_catalog date="2026-03-05 00:00">
  <shop>
    <offers>
      <offer id="A1" available="true">
        <name>Simple Product</name>
        <description><![CDATA[<p>Описание</p><img width="410" src="/upload/medialibrary/93c/example.png" alt="test"><img src="gallery/inner.png">]]></description>
        <price>123</price>
        <currencyId>RUB</currencyId>
        <picture>https://startweld.ru/upload/iblock/77a/77a3f1bf177c65e85fe180fc0ca1e98c.png</picture>
        <categoryId>1</categoryId>
      </offer>
    </offers>
  </shop>
</yml_catalog>
XML;

    $path = tempnam(sys_get_temp_dir(), 'yml_');
    file_put_contents($path, $xml);

    try {
        $stream = (new YmlStreamParser)->open($path);
        $offers = iterator_to_array($stream->offers);

        $result = (new YandexMarketFeedAdapter)->mapOffer($offers[0]);

        expect($result->isSuccess())->toBeTrue();
        expect($result->payload?->description)->toBe(
            '<p>Описание</p><img width="410" src="https://startweld.ru/upload/medialibrary/93c/example.png" alt="test"><img src="https://startweld.ru/upload/iblock/77a/gallery/inner.png">'
        );
    } finally {
        @unlink($path);
    }
});

it('maps rutube video links into native rich-content custom blocks', function () {
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<yml_catalog date="2026-03-05 00:00">
  <shop>
    <offers>
      <offer id="A1" available="true">
        <name>Simple Product</name>
        <price>123</price>
        <currencyId>RUB</currencyId>
        <video>
          Видеообзор:
          https://rutube.ru/video/8fb51708251cc2c2fa776f4778a4f1ef/?r=wd
          https://www.youtube.com/watch?v=ignored
          https://rutube.ru/video/8fb51708251cc2c2fa776f4778a4f1ef/
        </video>
        <video><![CDATA[См. также https://rutube.ru/video/c6a86f440e1437f9a65dd893d50aaabd/ и https://rutube.ru/play/embed/c6a86f440e1437f9a65dd893d50aaabd]]></video>
        <categoryId>1</categoryId>
      </offer>
    </offers>
  </shop>
</yml_catalog>
XML;

    $path = tempnam(sys_get_temp_dir(), 'yml_');
    file_put_contents($path, $xml);

    try {
        $stream = (new YmlStreamParser)->open($path);
        $offers = iterator_to_array($stream->offers);

        $result = (new YandexMarketFeedAdapter)->mapOffer($offers[0]);

        expect($result->isSuccess())->toBeTrue();
        expect($result->payload?->video)->toBe(
            yandexRutubeVideoBlock('8fb51708251cc2c2fa776f4778a4f1ef')
            .yandexRutubeVideoBlock('c6a86f440e1437f9a65dd893d50aaabd')
        );
    } finally {
        @unlink($path);
    }
});

it('skips vendor.model offers when required fields are missing', function () {
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<yml_catalog date="2026-03-05 00:00">
  <shop>
    <offers>
      <offer id="A2" type="vendor.model" available="true">
        <typePrefix>Пылесос</typePrefix>
        <model>VT-9000</model>
        <price>999</price>
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
        $stream = (new YmlStreamParser)->open($path);
        $offers = iterator_to_array($stream->offers);

        $adapter = new YandexMarketFeedAdapter;
        $result = $adapter->mapOffer($offers[0]);

        expect($result->isSuccess())->toBeFalse();
        expect($result->payload)->toBeNull();
        expect(collect($result->errors)->pluck('code')->all())->toContain('missing_required_vendor');
    } finally {
        @unlink($path);
    }
});

it('skips simplified offers when <name> is missing', function () {
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<yml_catalog date="2026-03-05 00:00">
  <shop>
    <offers>
      <offer id="A1" available="true">
        <price>123</price>
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
        $stream = (new YmlStreamParser)->open($path);
        $offers = iterator_to_array($stream->offers);

        $adapter = new YandexMarketFeedAdapter;
        $result = $adapter->mapOffer($offers[0]);

        expect($result->isSuccess())->toBeFalse();
        expect($result->payload)->toBeNull();
        expect(collect($result->errors)->pluck('code')->all())->toContain('missing_required_name');
    } finally {
        @unlink($path);
    }
});

it('resolves sku using priority and deterministic fallback', function () {
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<yml_catalog date="2026-03-05 00:00">
  <shop>
    <categories>
      <category id="1">Category 1</category>
    </categories>
    <offers>
      <offer id="A1" available="true">
        <name>Shop SKU</name>
        <shop-sku> sh 001 </shop-sku>
        <vendorCode>vc-1</vendorCode>
        <price>100</price>
        <currencyId>RUB</currencyId>
        <categoryId>1</categoryId>
      </offer>
      <offer id="id 2" available="true">
        <name>Offer ID SKU</name>
        <vendorCode>vc-2</vendorCode>
        <price>101</price>
        <currencyId>RUB</currencyId>
        <categoryId>1</categoryId>
      </offer>
      <offer id="###" available="true">
        <name>Vendor Code SKU</name>
        <vendorCode> vc 77 </vendorCode>
        <price>102</price>
        <currencyId>RUB</currencyId>
        <categoryId>1</categoryId>
      </offer>
      <offer id="@@@" available="true">
        <name>Param SKU</name>
        <param name="Part Number"> abc 12 </param>
        <price>103</price>
        <currencyId>RUB</currencyId>
        <categoryId>1</categoryId>
      </offer>
      <offer id="***" available="true">
        <name>Generated SKU</name>
        <param name="Model">M-1</param>
        <price>104</price>
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
        $stream = (new YmlStreamParser)->open($path);
        $offers = iterator_to_array($stream->offers);
        $adapter = new YandexMarketFeedAdapter;

        $first = $adapter->mapOffer($offers[0]);
        $second = $adapter->mapOffer($offers[1]);
        $third = $adapter->mapOffer($offers[2]);
        $fourth = $adapter->mapOffer($offers[3]);
        $fifth = $adapter->mapOffer($offers[4]);

        expect($first->payload?->sku)->toBe('SH-001');
        expect($first->payload?->source['sku_source'] ?? null)->toBe('shop-sku');

        expect($second->payload?->sku)->toBe('VC-2');
        expect($second->payload?->source['sku_source'] ?? null)->toBe('vendorCode');

        expect($third->payload?->sku)->toBe('VC-77');
        expect($third->payload?->source['sku_source'] ?? null)->toBe('vendorCode');

        expect($fourth->payload?->sku)->toBe('ABC-12');
        expect($fourth->payload?->source['sku_source'] ?? null)->toBe('param');

        $expectedGeneratedSku = 'YML-'.strtoupper(substr(hash('sha256', '***|Generated SKU'), 0, 12));
        expect($fifth->payload?->sku)->toBe($expectedGeneratedSku);
        expect($fifth->payload?->source['sku_source'] ?? null)->toBe('generated');
    } finally {
        @unlink($path);
    }
});

function yandexRutubeVideoBlock(string $rutubeId): string
{
    return '<div data-type="customBlock" data-config="{&quot;rutube_id&quot;:&quot;'
        .$rutubeId
        .'&quot;,&quot;width&quot;:640,&quot;alignment&quot;:&quot;center&quot;}" data-id="rutube-video"></div>';
}
