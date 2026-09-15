<?php

use App\Services\Kb\HtmlKbTextExtractor;
use App\Services\Kb\TiptapTextExtractor;
use Tests\TestCase;

uses(TestCase::class);

/*
 * Страницы intertooler хранят HTML из редактора. Фрагменты разметки ниже —
 * из настоящих страниц белого списка (dev-база, 15.09.2026), сокращённые.
 */

$extract = fn (?string $html): string => (new HtmlKbTextExtractor(new TiptapTextExtractor))->toText($html);

it('сводит заголовки к двум уровням, по которым режет чанкер', function () use ($extract): void {
    $result = $extract('<h1>Оплата</h1><p>Текст</p><h3>Наличными</h3><h4>По счёту</h4>');

    // h1 внутри содержимого — раздел, а не заголовок документа: чанкер
    // выбрасывает «# …» как дубль крошек, а «## …» режет.
    expect($result)->toBe("## Оплата\n\nТекст\n\n### Наличными\n\n### По счёту");
});

it('раскрывает списки в строки с маркерами', function () use ($extract): void {
    $result = $extract('<ul><li><p>Первое</p></li><li>Второе</li></ul><ol><li>Шаг</li><li>Ещё шаг</li></ol>');

    expect($result)->toBe("- Первое\n- Второе\n\n1. Шаг\n2. Ещё шаг");
});

it('дописывает адрес после текста ссылки', function () use ($extract): void {
    $result = $extract('<p>Смотрите <a href="https://intertooler.ru/page/kontakty">контакты</a> магазина</p>');

    expect($result)->toBe('Смотрите контакты (https://intertooler.ru/page/kontakty) магазина');
});

it('показывает то, что написано в ссылке, а не адрес за mailto', function () use ($extract): void {
    $result = $extract(
        '<p>E-mail: <a href="mailto:r_kodachenko@mail.ru" target="_blank">sales@intertooler.ru</a><br></p>'
        .'<p>🌐 Сайт: <a href="https://intertooler.ru/"><strong><u>https://intertooler.ru/</u></strong></a></p>'
    );

    // Страница контактов: в тексте sales@, за ссылкой — личная почта. Покупатель
    // видит sales@, и бот должен называть его же. А ссылку, обёрнутую в
    // <strong><u>, адрес в скобках не повторяет — текст и есть адрес.
    expect($result)->toBe("E-mail: sales@intertooler.ru\n\n🌐 Сайт: https://intertooler.ru/");
});

it('бережёт переносы строк внутри абзаца', function () use ($extract): void {
    $result = $extract('<p><strong>ИНН 231102927496</strong><br>📍 Адрес: Краснодар<br> 📞 Телефон: +7-900-246-86-60</p>');

    expect($result)->toBe("ИНН 231102927496\n📍 Адрес: Краснодар\n📞 Телефон: +7-900-246-86-60");
});

it('разбирает таблицу построчно, а не одной строкой', function () use ($extract): void {
    $result = $extract(
        '<table><tbody><tr><td>Доставка по Краснодару</td><td>1 день</td></tr>'
        .'<tr><th>Длина</th><th>Ширина</th><th>Высота</th></tr></tbody></table>'
    );

    expect($result)->toBe("Доставка по Краснодару: 1 день\nДлина | Ширина | Высота");
});

it('не пускает в текст скрипты, картинки и конфиг блоков редактора', function () use ($extract): void {
    $result = $extract(
        '<div class="lead"><p>Наш адрес: Краснодар</p>'
        .'<div data-type="customBlock" data-config="{&quot;map_url&quot;:&quot;https:\/\/yandex.com\/map-widget&quot;}" data-id="yandex-map"></div></div>'
        .'<script>alert(1)</script><iframe src="https://example.com"></iframe><img src="a.png" alt="01KSK2ZKF6.png">'
        .'<p>ИНН 2311386255</p>'
    );

    expect($result)->toBe("Наш адрес: Краснодар\n\nИНН 2311386255");
});

it('берёт подпись картинки из блока редактора, если её написали', function () use ($extract): void {
    $result = $extract(
        '<div data-type="customBlock" data-id="image" data-config="{&quot;file&quot;:&quot;pics\/a.png&quot;,&quot;alt&quot;:&quot;Деловые Линии&quot;}"></div>'
        .'<div data-type="customBlock" data-id="image" data-config="{&quot;file&quot;:&quot;pics\/b.png&quot;,&quot;alt&quot;:null}"></div>'
    );

    expect($result)->toBe('Деловые Линии');
});

it('отдаёт текст из блока произвольного html', function () use ($extract): void {
    $result = $extract(
        '<div data-type="customBlock" data-id="raw-html" data-config="{&quot;html&quot;:&quot;&lt;p&gt;Первая строка&lt;/p&gt;&lt;b&gt;Вторая&lt;/b&gt;&quot;}"></div>'
    );

    expect($result)->toBe("Первая строка\nВторая");
});

it('отдаёт пустоту на пустой странице', function () use ($extract): void {
    // Шесть опубликованных страниц сайта — ровно `<p></p>`.
    expect($extract('<p></p>'))->toBe('')
        ->and($extract('<p><br></p><p>&nbsp;</p>'))->toBe('')
        ->and($extract(null))->toBe('');
});

it('читает сущности и пробелы так, как их показывает браузер', function () use ($extract): void {
    $result = $extract("<p>Цена&nbsp;по   запросу &amp; доставка\n   по России</p>");

    expect($result)->toBe('Цена по запросу & доставка по России');
});

it('снимает оформление, оставляя текст', function () use ($extract): void {
    $result = $extract('<p>Вы можете выбрать <span data-color="red" class="color" style="--color: oklch(0.577 0.245 27.325)">мы подберем оптимальную</span>.</p>');

    expect($result)->toBe('Вы можете выбрать мы подберем оптимальную.');
});

it('собирает в абзацы текст без обёртки и не плодит пустых', function () use ($extract): void {
    $result = $extract("<div>Текст <b>жирный</b></div>\n<p>Абзац</p>хвост");

    expect($result)->toBe("Текст жирный\n\nАбзац\n\nхвост");
});

it('не выдаёт жирный абзац за заголовок', function () use ($extract): void {
    // «Доставка и оплата» размечена так вместо <h2>. Угадывать заголовки
    // по оформлению не берёмся: «ИП Кодаченко» жирным тоже стал бы разделом.
    // Настоящие заголовки на странице — задача контента, а не разбора.
    $result = $extract('<p><strong>Доставка:</strong></p><ul><li><p>по всей России</p></li></ul>');

    expect($result)->toBe("Доставка:\n\n- по всей России");
});
