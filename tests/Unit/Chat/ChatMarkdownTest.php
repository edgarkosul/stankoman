<?php

use App\Services\Chat\ChatMarkdown;

// Разметка в ленте — чистая функция над строкой: ни базы, ни сети.

function chatMarkdownHtml(string $text, string $appUrl = 'https://intertooler.ru', array $ownHosts = []): string
{
    return (new ChatMarkdown($appUrl, $ownHosts))->toHtml($text)->toHtml();
}

it('рисует абзацы, списки и выделение', function (): void {
    $html = chatMarkdownHtml("Оплатить можно так:\n\n- по счёту\n- картой\n\nСрок — **один день**.");

    expect($html)->toContain('<ul>')
        ->and($html)->toContain('<li>по счёту</li>')
        ->and($html)->toContain('<strong>один день</strong>');
});

it('делает кликабельной ссылку на свой сайт и открывает её в новой вкладке', function (): void {
    // Чат живёт в углу страницы: уход по ссылке в том же окне сворачивает
    // разговор там, где покупатель как раз пошёл смотреть товар.
    $html = chatMarkdownHtml('Смотрите [карточку товара](https://intertooler.ru/product/it-4500).');

    expect($html)->toContain('href="https://intertooler.ru/product/it-4500"')
        ->and($html)->toContain('>карточку товара</a>')
        ->and($html)->toContain('target="_blank"')
        ->and($html)->toContain('rel="noopener"');
});

it('чужую ссылку показывает текстом вместе с адресом', function (): void {
    // Главный риск разметки — не XSS, а подменённый текст ссылки:
    // «Оплатить заказ» может вести куда угодно, а источник — описание
    // товара от поставщика, которое бот прочитал.
    $html = chatMarkdownHtml('[Оплатить заказ](http://evil.ru/pay)');

    expect($html)->not->toContain('<a ')
        ->and($html)->toContain('Оплатить заказ (http://evil.ru/pay)');
});

it('делает кликабельным голый адрес своего сайта', function (): void {
    expect(chatMarkdownHtml('Вот он: https://intertooler.ru/catalog/kompressory'))
        ->toContain('href="https://intertooler.ru/catalog/kompressory"');
});

it('экранирует сырой HTML и режет javascript-ссылку', function (): void {
    // Отдельными строками: строка, начатая с <script>, — это HTML-блок
    // до конца строки, и ссылка рядом с ним не разбиралась бы вовсе.
    $html = chatMarkdownHtml('<script>alert(1)</script> и <b>жирный</b>');

    expect($html)->not->toContain('<script')
        ->and($html)->not->toContain('<b>')
        ->and($html)->toContain('&lt;script&gt;')
        ->and(chatMarkdownHtml('[клик](javascript:alert(1))'))->not->toContain('javascript:alert');
});

it('выбрасывает картинки, заголовки и таблицы', function (): void {
    // Картинка — запрос на чужой сервер с IP посетителя, таблица в панели
    // шириной 380 px нечитаема.
    $html = chatMarkdownHtml("# Оплата\n\n![пиксель](http://evil.ru/track.png)\n\n| a | b |\n|---|---|\n| 1 | 2 |");

    expect($html)->not->toContain('<img')
        ->and($html)->not->toContain('<h1')
        ->and($html)->not->toContain('<table')
        ->and($html)->toContain('Оплата');
});

it('пустой текст остаётся пустым', function (): void {
    expect(chatMarkdownHtml('   '))->toBe('');
});

it('текстовая версия разворачивает список в строки и не оставляет сущностей', function (): void {
    $markdown = new ChatMarkdown('https://intertooler.ru');

    expect($markdown->toPlainText("Условия:\n\n- по счёту\n- картой\n\nСрок — **один день**."))
        ->toBe("Условия:\n\nпо счёту\nкартой\n\nСрок — один день.")
        ->and($markdown->toPlainText('счёт «на 5 000 ₽» & доставка'))
        ->toBe('счёт «на 5 000 ₽» & доставка');
});

it('на деве оставляет кликабельными ссылки на боевой домен из списка своих', function (): void {
    // APP_URL дева — localhost:8103, и поддоменом intertooler.ru он не является:
    // без списка своих хостов ссылки на товары приходили бы на приёмке текстом.
    $html = chatMarkdownHtml('[Станок](https://intertooler.ru/product/it-4500)', 'http://localhost:8103', ['intertooler.ru']);

    expect($html)->toContain('<a');
});

it('не пускает в кликабельные чужой хост, притворяющийся своим', function (): void {
    $html = chatMarkdownHtml('[Оплатить](https://intertooler.ru.evil.ru/pay)', 'http://localhost:8103', ['intertooler.ru', '', '  ']);

    expect($html)->not->toContain('<a')
        ->and($html)->toContain('intertooler.ru.evil.ru');
});
