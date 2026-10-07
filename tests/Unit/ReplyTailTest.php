<?php

use App\Services\Ai\Support\ReplyTail;

it('оборванную строку убирает целиком вместе с разделителем перед ней', function (): void {
    $text = "- производительность 420 л/мин, ресивер 50 л\n"
        ."- Самый доступный, но поршневой — шумнее винтового.\n\n---\n\n"
        .'Для гаража оптимальнее всего **CrossAir 420** — тихий и с запасом по возду';

    expect(ReplyTail::complete($text))->toBe(
        "- производительность 420 л/мин, ресивер 50 л\n"
        .'- Самый доступный, но поршневой — шумнее винтового.'
    );
});

it('пункт, оборванный на ссылке, не показывает', function (): void {
    $text = "Подойдёт любой из трёх.\n\n"
        .'**4. [Компрессор Hansmann](https://intertooler.ru/product/hansmann-';

    expect(ReplyTail::complete($text))->toBe('Подойдёт любой из трёх.');
});

it('в оборванном абзаце оставляет законченные предложения', function (): void {
    $text = "Подойдут три модели.\n\nCrossAir — 102 337 ₽. HITCOM — 93 150 ₽. А вот **Metal Master** уже на верх";

    expect(ReplyTail::complete($text))->toBe("Подойдут три модели.\n\nCrossAir — 102 337 ₽. HITCOM — 93 150 ₽.");
});

it('точка внутри адреса и незакрытый полужирный — не конец предложения', function (): void {
    $link = "Размеры — в таблице.\n\nПодробнее — [доставка](https://intertooler.ru/page/dostav";
    $bold = "Размеры — в таблице.\n\nЛучший выбор — **CrossAir 420. Он подхо";

    expect(ReplyTail::complete($link))->toBe('Размеры — в таблице.')
        ->and(ReplyTail::complete($bold))->toBe('Размеры — в таблице.');
});

it('целый ответ не трогает, а единственную оборванную строку оставляет как есть', function (): void {
    $whole = "Доставка по Москве — 1 500 ₽.\n\nОформить заказ?";

    expect(ReplyTail::complete($whole))->toBe($whole)
        ->and(ReplyTail::complete('Доставка по Москве стоит полторы тысячи, а за МКА'))->toBe('Доставка по Москве стоит полторы тысячи, а за МКА');
});
