<?php

use App\Services\Ai\Support\OfferedLinkGuard;
use App\Services\Ai\Tools\ToolContext;
use Tests\TestCase;

// Гард пишет в журнал, а журнал — фасад: без поднятого приложения тест падает.
uses(TestCase::class);

test('выдуманная ссылка теряет адрес, а подпись остаётся', function (): void {
    $text = 'Гарантия у каждого товара своя. Подробнее — [Гарантия и сервис](https://intertooler.ru/page/guarantee-service).';

    expect((new OfferedLinkGuard)->strip($text, ['https://intertooler.ru/page/service']))
        ->toBe('Гарантия у каждого товара своя. Подробнее — Гарантия и сервис.');
});

test('ссылка, пришедшая от инструмента, остаётся как есть', function (): void {
    $guard = new OfferedLinkGuard;
    $offered = ['https://intertooler.ru/product/kompressor-dali'];

    $text = 'Есть [Компрессор Dali](https://intertooler.ru/product/kompressor-dali) в наличии.';

    expect($guard->strip($text, $offered))->toBe($text);

    // Хвостовой слеш и якорь — та же страница, а не выдумка.
    expect($guard->strip('[Раздел](https://intertooler.ru/product/kompressor-dali/#specs)', $offered))
        ->toBe('[Раздел](https://intertooler.ru/product/kompressor-dali/#specs)');
});

test('почта и телефон подставляются ботом и под правило не попадают', function (): void {
    $text = 'Напишите на [sales@intertooler.ru](mailto:sales@intertooler.ru) или [позвоните](tel:+79002468660).';

    expect((new OfferedLinkGuard)->strip($text, []))->toBe($text);
});

test('текст без ссылок не трогается', function (): void {
    $text = 'Срок отгрузки — 3–5 рабочих дней (уточнит менеджер).';

    expect((new OfferedLinkGuard)->strip($text, []))->toBe($text);
});

test('контекст хода собирает адреса из результатов инструментов', function (): void {
    $context = new ToolContext;

    $context->noteOfferedUrls("Доставка и оплата\n[ссылка: https://intertooler.ru/page/dostavka-i-oplata]");
    $context->noteOfferedUrls("Компрессоры\nСсылка: https://intertooler.ru/catalog/kompressory.");
    // Повтор того же адреса не задваивается.
    $context->noteOfferedUrls('[ссылка: https://intertooler.ru/page/dostavka-i-oplata]');

    expect($context->offeredUrls)->toBe([
        'https://intertooler.ru/page/dostavka-i-oplata',
        'https://intertooler.ru/catalog/kompressory',
    ]);
});
