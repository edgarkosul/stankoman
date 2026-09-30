<?php

use App\Services\Kb\TiptapTextExtractor;
use Tests\TestCase;

uses(TestCase::class);

$extract = fn (array $doc): string => (new TiptapTextExtractor)->toText($doc);

$doc = fn (array ...$content): array => ['type' => 'doc', 'content' => $content];
$text = fn (string $value, array $marks = []): array => array_filter([
    'type' => 'text', 'text' => $value, 'marks' => $marks ?: null,
]);
$para = fn (array ...$content): array => ['type' => 'paragraph', 'content' => $content];

it('превращает заголовки в markdown нужного уровня', function () use ($extract, $doc, $text): void {
    $result = $extract($doc(
        ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [$text('Оплата')]],
        ['type' => 'heading', 'attrs' => ['level' => 3], 'content' => [$text('Наличными')]],
    ));

    // Уровень заголовка — не косметика: по «##» режет чанкер.
    expect($result)->toBe("## Оплата\n\n### Наличными");
});

it('раскрывает списки в строки с маркерами', function () use ($extract, $doc, $text, $para): void {
    $item = fn (string $value): array => ['type' => 'listItem', 'content' => [$para($text($value))]];

    $result = $extract($doc(
        ['type' => 'bulletList', 'content' => [$item('Первое'), $item('Второе')]],
        ['type' => 'orderedList', 'content' => [$item('Шаг'), $item('Ещё шаг')]],
    ));

    expect($result)->toBe("- Первое\n- Второе\n\n1. Шаг\n2. Ещё шаг");
});

it('дописывает адрес после текста ссылки', function () use ($extract, $doc, $text, $para): void {
    $result = $extract($doc($para(
        $text('Смотрите '),
        $text('контакты', [['type' => 'link', 'attrs' => ['href' => 'https://intertooler.ru/page/kontakty']]]),
    )));

    // Без адреса бот знает, что «подробности на странице контактов»,
    // но дать ссылку не может.
    expect($result)->toBe('Смотрите контакты (https://intertooler.ru/page/kontakty)');
});

it('не дублирует mailto, tel и ссылку на саму себя', function () use ($extract, $doc, $text, $para): void {
    $link = fn (string $value, string $href): array => $text($value, [['type' => 'link', 'attrs' => ['href' => $href]]]);

    $result = $extract($doc(
        $para($link('sales@intertooler.ru', 'mailto:sales@intertooler.ru')),
        $para($link('+7 900 246 86 60', 'tel:+79002468660')),
        $para($link('https://intertooler.ru/page/kontakty', 'https://intertooler.ru/page/kontakty/')),
        $para($link('наверх', '#top')),
    ));

    // Почта, размеченная ссылкой на себя, при наивной подстановке даёт
    // «адрес (mailto:адрес)» — чистый шум, вытесняющий из эмбеддинга
    // полезные слова.
    expect($result)->toBe("sales@intertooler.ru\n\n+7 900 246 86 60\n\nhttps://intertooler.ru/page/kontakty\n\nнаверх");
});

it('пропускает колонки вёрстки насквозь', function () use ($extract, $doc, $text, $para): void {
    $result = $extract($doc([
        'type' => 'grid',
        'content' => [
            ['type' => 'gridColumn', 'content' => [$para($text('Слева'))]],
            ['type' => 'gridColumn', 'content' => [$para($text('Справа'))]],
        ],
    ]));

    expect($result)->toBe("Слева\n\nСправа");
});

it('выбрасывает картинки без подписи и разделители, не оставляя пустых абзацев', function () use ($extract, $doc, $text, $para): void {
    $result = $extract($doc(
        $para($text('До')),
        ['type' => 'image', 'attrs' => ['src' => '/img.png']],
        ['type' => 'horizontalRule'],
        $para($text('После')),
    ));

    expect($result)->toBe("До\n\nПосле");
});

/*
 * Подписи к картинкам. Случай не гипотетический: у kratonshop транспортные
 * компании на странице доставки были показаны одними логотипами, и бот,
 * не найдя названий в тексте, вывел их из доменов в адресах ссылок.
 */
it('забирает подпись картинки из alt', function () use ($extract, $doc, $text, $para): void {
    $result = $extract($doc(
        $para($text('Наши основные партнеры')),
        ['type' => 'image', 'attrs' => ['src' => '/dl.png', 'alt' => 'Деловые Линии']],
    ));

    expect($result)->toBe("Наши основные партнеры\n\nДеловые Линии");
});

it('берёт title, если alt пуст', function () use ($extract, $doc): void {
    expect($extract($doc(
        ['type' => 'image', 'attrs' => ['src' => '/p.png', 'alt' => '', 'title' => 'ПЭК']],
    )))->toBe('ПЭК');
});

it('не путает пустую подпись с отсутствующей', function () use ($extract, $doc): void {
    expect($extract($doc(
        ['type' => 'image', 'attrs' => ['src' => '/x.png', 'alt' => '   ']],
    )))->toBe('');
});

it('читает подпись у картинки внутри абзаца', function () use ($extract, $doc, $text, $para): void {
    $result = $extract($doc($para(
        $text('Отправляем через '),
        ['type' => 'image', 'attrs' => ['src' => '/b.png', 'alt' => 'Байкал-Сервис']],
    )));

    expect($result)->toBe('Отправляем через Байкал-Сервис');
});

it('схлопывает пробелы вокруг переноса строки внутри абзаца', function () use ($extract, $doc, $text, $para): void {
    $result = $extract($doc($para($text('Телефон '), ['type' => 'hardBreak'], $text(' +7 900 246 86 60'))));

    expect($result)->toBe("Телефон\n+7 900 246 86 60");
});

it('отдаёт ссылку на документ из блока pdf-link', function () use ($extract, $doc): void {
    $result = $extract($doc([
        'type' => 'customBlock',
        'attrs' => [
            'id' => 'pdf-link',
            'config' => ['source_type' => 'direct_url', 'url' => 'https://intertooler.ru/docs/warranty.pdf', 'link_text' => 'Гарантийный талон'],
        ],
    ]));

    expect($result)->toBe('Гарантийный талон (https://intertooler.ru/docs/warranty.pdf)');
});

it('снимает теги с произвольного html, сохраняя переносы', function () use ($extract, $doc): void {
    $result = $extract($doc([
        'type' => 'customBlock',
        'attrs' => ['id' => 'raw-html', 'config' => ['html' => '<p>Первая строка</p><br><b>Вторая</b>']],
    ]));

    // Без замены <br> и </p> на переносы соседние строки склеились бы
    // в «Первая строкаВторая» — одно несуществующее слово в эмбеддинге.
    expect($result)->toBe("Первая строка\n\nВторая");
});

it('игнорирует слайдеры, галереи, карты и незнакомые блоки', function () use ($extract, $doc, $text, $para): void {
    $block = fn (string $id): array => ['type' => 'customBlock', 'attrs' => ['id' => $id, 'config' => ['x' => 1]]];

    $result = $extract($doc(
        $para($text('Текст')),
        $block('hero-slider'),
        $block('image_gallery'),
        $block('yandex-map'),
        $block('seller-requisites'),
    ));

    expect($result)->toBe('Текст');
});

it('не падает на незнакомом узле, а спускается внутрь него', function () use ($extract, $doc, $text, $para): void {
    // Админ добавит новый блок в редактор — индексация не должна встать.
    $result = $extract($doc([
        'type' => 'somethingBrandNew',
        'content' => [$para($text('Всё равно виден'))],
    ]));

    expect($result)->toBe('Всё равно виден');
});

it('принимает документ строкой JSON и переживает мусор', function (): void {
    $extractor = new TiptapTextExtractor;

    expect($extractor->toText('{"type":"doc","content":[{"type":"paragraph","content":[{"type":"text","text":"Ок"}]}]}'))->toBe('Ок')
        ->and($extractor->toText('не json'))->toBe('')
        ->and($extractor->toText(null))->toBe('');
});
