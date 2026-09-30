<?php

use App\Services\Ai\Data\ProductCard;

/*
 * Текст карточки для модели. Здесь проверяется не форматирование, а обещание
 * контракта: в карточке ровно одна цена, и это та, что покупатель видит
 * на витрине. Как она выбирается — в tests/Feature/AssistantProductLookupTest.php.
 */

function assistantCard(array $overrides = []): ProductCard
{
    return new ProductCard(...array_merge([
        'id' => 1,
        'name' => 'Винтовой компрессор Hansmann RSE 7.5-8',
        'url' => 'https://intertooler.ru/product/rse-7-5-8',
        'sku' => 'RSE 7.5-8',
        'brand' => 'Hansmann',
        'inStock' => true,
        'price' => 108596,
        'priceNote' => '',
        'vatNote' => 'НДС 22% в том числе',
        'warranty' => '24 мес.',
        'specs' => [['name' => 'Мощность двигателя, кВт', 'value' => '7.5']],
        'description' => null,
    ], $overrides));
}

it('даёт имя товара сразу ссылкой', function (): void {
    // Чтобы назвать товар без ссылки, модели надо разобрать разметку
    // на части; скопировать целиком проще, а копирует она охотно.
    expect(assistantCard()->toPromptText())
        ->toContain('Название: [Винтовой компрессор Hansmann RSE 7.5-8](https://intertooler.ru/product/rse-7-5-8)');
});

it('печатает цену со ставкой НДС, как под ценой на карточке', function (): void {
    expect(assistantCard()->toPromptText())->toContain('Цена: 108 596 руб. (НДС 22% в том числе)');
});

it('приписку о скидке даёт текстом, а не вторым числом', function (): void {
    $text = assistantCard([
        'priceNote' => 'рядом с ценой на карточке стоит значок «−5%», а под ней — '
            .'«Зарегистрируйтесь и получите скидку или войдите»: скидка 5% действует '
            .'для зарегистрированных покупателей',
    ])->toPromptText();

    expect($text)->toContain('Цена: 108 596 руб. (НДС 22% в том числе) — рядом с ценой')
        ->and($text)->toContain('значок «−5%»');
});

it('«Цена по запросу» не превращается в ноль рублей', function (): void {
    // Таких товаров на деве четыре, и «0 руб.» из них — готовая неправда.
    $text = assistantCard(['price' => null, 'vatNote' => '', 'priceNote' => ''])->toPromptText();

    expect($text)->toContain('Цена: на сайте стоит «Цена по запросу» — цену называет менеджер')
        ->and($text)->not->toContain('0 руб.')
        ->and($text)->not->toContain('НДС');
});

it('наличие пересказывает словами карточки', function (): void {
    // На сайте написано «Нет в наличии». «Под заказ» из уст бота было бы
    // обещанием поставки, которого магазин не давал.
    expect(assistantCard(['inStock' => false])->toPromptText())
        ->toContain('Наличие: нет в наличии — срок поставки уточняет менеджер');
});

it('незаполненную гарантию не выдаёт за отсутствие гарантии', function (): void {
    // На деве гарантия пуста у 337 активных товаров, и «гарантии нет» —
    // другое утверждение, чем «на сайте не указана».
    expect(assistantCard(['warranty' => null])->toPromptText())
        ->toContain('Гарантия производителя: не указана на сайте — уточняет менеджер');
});

it('описание отдаёт с заголовком, а без описания молчит', function (): void {
    // Без заголовка модель принимает описание за продолжение списка
    // характеристик и отвечает рекламным абзацем на вопрос о числе.
    expect(assistantCard(['description' => 'Ёмкость из нержавеющей стали AISI 304.'])->toPromptText())
        ->toContain('Описание товара с сайта')
        ->and(assistantCard()->toPromptText())->not->toContain('Описание товара с сайта');
});

it('характеристики перечисляет строками карточки', function (): void {
    expect(assistantCard()->toPromptText())->toContain("Характеристики с карточки:\n- Мощность двигателя, кВт: 7.5");
});

it('второго поля с ценой в карточке не существует', function (): void {
    // Тест не про строку, а про устройство: пока в DTO одно число, текста
    // с членской ценой из него не собрать — ни промптом, ни ошибкой модели.
    $fields = array_map(
        static fn (ReflectionProperty $property): string => $property->getName(),
        (new ReflectionClass(ProductCard::class))->getProperties(),
    );

    expect(array_values(array_filter(
        $fields,
        static fn (string $field): bool => str_contains(mb_strtolower($field), 'price'),
    )))->toBe(['price', 'priceNote']);
});
