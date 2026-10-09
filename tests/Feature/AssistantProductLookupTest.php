<?php

use App\Models\Category;
use App\Models\Product;
use App\Services\Ai\Data\ProductQuery;
use App\Services\Ai\Data\SpecCondition;
use App\Services\Ai\Data\SpecQuery;
use App\Services\Ai\Support\ProductTextExtractor;
use App\Shop\CatalogSections;
use App\Shop\EloquentProductLookup;
use App\Support\Products\ProductSpecs;
use App\Support\Search\ProductTextSearch;
use Illuminate\Support\Str;

/*
 * Шов «товар и каталог» на живых моделях.
 *
 * Здесь проверяется самое опасное место порта — ценовая политика. Магазин
 * прячет от гостя СУММУ скидки для зарегистрированных, но показывает её
 * ПРОЦЕНТ (DiscountVisibility, решение заказчика 20.08.2026), и на деве
 * такая скидка стоит у 1 897 товаров из 4 001. Ошибиться можно в обе
 * стороны: назвать членскую цену — нарушить политику, промолчать о скидке —
 * потерять регистрацию, ради которой политика и заведена.
 *
 * Драйвер поиска в тестах — `collection` (phpunit.xml), поэтому фильтры
 * Meilisearch здесь не работают: строка фильтра проверяется отдельно,
 * без базы. Проба слов для повтора запроса у этого драйвера тоже своя —
 * там, где она нужна, она подменяется.
 */

function assistantLookup(int $specsInList = 8, ?ProductTextSearch $search = null): EloquentProductLookup
{
    $search ??= new ProductTextSearch;

    return new EloquentProductLookup(
        search: $search,
        sections: new CatalogSections($search),
        specs: new ProductSpecs,
        extractor: new ProductTextExtractor,
        descriptionLimit: 3000,
        specsInList: $specsInList,
        vatRate: 22,
    );
}

function assistantProduct(array $overrides = []): Product
{
    return Product::query()->create(array_merge([
        'name' => 'Винтовой компрессор Hansmann RSE 7.5-8',
        'slug' => 'vintovoi-kompressor-hansmann-rse-7-5-8',
        'sku' => 'RSE 7.5-8',
        'brand' => 'Hansmann',
        'price_amount' => 108596,
        'discount_price' => 103166,
        'currency' => 'RUB',
        'in_stock' => true,
        'is_active' => true,
        'warranty' => '24',
        'specs' => [
            ['name' => 'Мощность двигателя, кВт', 'value' => '7.5'],
            ['name' => 'Давление, бар', 'value' => '8'],
        ],
        'description' => '<p>Ёмкость из нержавеющей стали AISI 304.</p>',
    ], $overrides));
}

it('гостю называет базовую цену и процент скидки, но не сумму со скидкой', function (): void {
    assistantProduct();

    $text = assistantLookup()->find('RSE 7.5-8', '', seesDiscounts: false)->toPromptText();

    expect($text)->toContain('Цена: 108 596 руб. (НДС 22% в том числе)')
        ->and($text)->toContain('значок «−5%»')
        ->and($text)->toContain('Зарегистрируйтесь и получите скидку или войдите')
        ->and($text)->toContain('не называй и не вычисляй её')
        // Сумма со скидкой не должна встречаться ни в одной записи.
        ->and($text)->not->toContain('103 166')
        ->and($text)->not->toContain('103166');
});

it('вошедшему называет цену со скидкой и зачёркнутую базовую', function (): void {
    assistantProduct();

    $text = assistantLookup()->find('RSE 7.5-8', '', seesDiscounts: true)->toPromptText();

    expect($text)->toContain('Цена: 103 166 руб. (НДС 22% в том числе)')
        ->and($text)->toContain('это УЖЕ цена со скидкой 5% для зарегистрированных')
        ->and($text)->toContain('без скидки 108 596 руб.')
        ->and($text)->toContain('потому что вошёл в аккаунт');
});

it('отдаёт скрытую от гостя сумму отдельно — для выходной проверки ответа', function (): void {
    $product = assistantProduct();

    // В карточку это число не попадает никогда; его знает только
    // ReplyFormatter, чтобы выбросить предложение, если модель его назвала.
    expect(assistantLookup()->memberPrices([$product->id]))->toBe([103166])
        ->and(assistantLookup()->memberPrices([]))->toBe([]);
});

it('товар без скидки идёт без всякой приписки о цене', function (): void {
    assistantProduct(['discount_price' => null]);

    $card = assistantLookup()->find('RSE 7.5-8', '', seesDiscounts: false);

    expect($card->price)->toBe(108596)
        ->and($card->priceNote)->toBe('')
        ->and(assistantLookup()->memberPrices([$card->id]))->toBe([]);
});

it('нулевую цену отдаёт как «Цена по запросу», а не как ноль рублей', function (): void {
    assistantProduct(['price_amount' => 0, 'discount_price' => null]);

    $card = assistantLookup()->find('RSE 7.5-8', '', seesDiscounts: false);

    expect($card->price)->toBeNull()
        ->and($card->toPromptText())->toContain('«Цена по запросу»');
});

it('в списке выдачи характеристики обрезаны, у одной карточки — полные', function (): void {
    $rows = [];

    for ($i = 1; $i <= 12; $i++) {
        $rows[] = ['name' => 'Параметр '.$i, 'value' => (string) $i];
    }

    assistantProduct(['specs' => $rows]);

    // Пять карточек по 67 строк (наш максимум) утопили бы ответ, поэтому
    // в списке строк меньше. А у get_product спросили про ОДИН товар.
    $inList = assistantLookup(specsInList: 3)->search(new ProductQuery(text: 'Hansmann', limit: 5))->cards;
    $single = assistantLookup(specsInList: 3)->find('RSE 7.5-8', '', seesDiscounts: false);

    expect($inList[0]->specs)->toHaveCount(3)
        ->and($single->specs)->toHaveCount(12)
        // Описание — только у одной карточки, в списке его нет.
        ->and($single->description)->toContain('AISI 304')
        ->and($inList[0]->description)->toBeNull();
});

it('в списке описание получает только товар, названный обозначением', function (): void {
    // Обратная сторона предыдущего теста. Описание в выдачу поиска попадает,
    // но по делу: покупатель спросил про конкретную машину по шильдику.
    // На обычном «компрессор» описание привешивалось бы к каждому поиску.
    assistantProduct();

    $byDesignation = assistantLookup()->search(new ProductQuery(text: 'RSE 7.5-8'))->cards;
    $byKind = assistantLookup()->search(new ProductQuery(text: 'компрессор'))->cards;

    expect($byDesignation[0]->description)->toContain('AISI 304')
        ->and($byKind[0]->description)->toBeNull();
});

it('находит товар по обозначению с шильдика, а не только по артикулу', function (): void {
    // Покупатель называет машину так, как она написана на корпусе.
    // У донора отказ по существующему товару был худшим видом ошибки:
    // покупатель уходит к тому, у кого «есть».
    assistantProduct(['sku' => 'VK41001510']);

    $byDesignation = assistantLookup()->find('RSE 7.5-8', '', seesDiscounts: false);
    $bySku = assistantLookup()->find('VK41001510', '', seesDiscounts: false);

    expect($byDesignation?->sku)->toBe('VK41001510')
        ->and($bySku?->sku)->toBe('VK41001510')
        // Соседняя модель серии — это другая машина и другие деньги.
        ->and(assistantLookup()->find('RSE 7.5-10', '', seesDiscounts: false))->toBeNull();
});

it('снятый с публикации товар не отдаётся', function (): void {
    assistantProduct(['is_active' => false]);

    expect(assistantLookup()->find('RSE 7.5-8', '', seesDiscounts: false))->toBeNull()
        ->and(assistantLookup()->search(new ProductQuery(text: 'Hansmann'))->cards)->toBe([]);
});

it('незнакомое слово не обнуляет выдачу, и выдача говорит, без каких слов собрана', function (): void {
    /*
     * Живой запрос с прода: «бензогенератор tehnotek» давал 0 при 78 товарах
     * бренда — Meilisearch выбрасывает слова с конца, а незнакомое стояло
     * первым. Бот ищет той же точкой входа, что и витрина, и должен знать,
     * что выдача собрана без слова: иначе подаст её как точный ответ.
     *
     * Проба слов подменена: у драйвера collection её нет.
     */
    assistantProduct([
        'name' => 'Генератор Tehnotek T1500',
        'slug' => 'generator-tehnotek-t1500',
        'sku' => 'T1500',
        'brand' => 'Tehnotek',
    ]);

    $search = new ProductTextSearch(
        wordHits: static fn (array $words): array => array_map(
            static fn (string $word): int => $word === 'benzogenerator' ? 0 : 78,
            $words,
        ),
    );

    $matches = assistantLookup(search: $search)->search(new ProductQuery(text: 'бензогенератор tehnotek'));

    expect($matches->cards)->toHaveCount(1)
        ->and($matches->cards[0]->name)->toBe('Генератор Tehnotek T1500')
        ->and($matches->relaxed)->toBeTrue()
        ->and($matches->unmatched)->toBe(['бензогенератор'])
        ->and($matches->searchedText)->toBe('tehnotek');
});

it('кириллическое прочтение бренда находит товар так же, как на сайте', function (): void {
    // «Сталекс» буквальной транслитерацией — staleks, в каталоге — Stalex.
    assistantProduct([
        'name' => 'Ленточнопильный станок Stalex BS-712N',
        'slug' => 'stalex-bs-712n',
        'sku' => 'BS-712N',
        'brand' => 'Stalex',
    ]);

    $matches = assistantLookup()->search(new ProductQuery(text: 'сталекс'));

    expect($matches->cards)->toHaveCount(1)
        ->and($matches->searchedText)->toBe('stalex')
        ->and($matches->relaxed)->toBeFalse();
});

it('отдаёт листовые разделы с числом товаров и ссылкой', function (): void {
    $root = Category::query()->create([
        'name' => 'Клининговое оборудование',
        'slug' => 'kliningovoe-oborudovanie',
        'parent_id' => Category::defaultParentKey(),
        'order' => 1,
        'is_active' => true,
    ]);

    $leaf = Category::query()->create([
        'name' => 'Промышленные пылесосы',
        'slug' => 'promyshlennye-pylesosy',
        'parent_id' => $root->id,
        'order' => 1,
        'is_active' => true,
    ]);

    $product = Product::query()->create([
        'name' => 'Промышленный пылесос VACTOOL SA36M60',
        'slug' => 'pylesos-sa36m60',
        'price_amount' => 73036,
        'currency' => 'RUB',
        'is_active' => true,
    ]);

    $leaf->products()->attach($product->id, ['is_primary' => true]);

    $sections = assistantLookup()->sections('пылесос', 6);

    expect($sections)->toHaveCount(1)
        ->and($sections[0]->id)->toBe($leaf->id)
        ->and($sections[0]->path)->toBe('Клининговое оборудование › Промышленные пылесосы')
        ->and($sections[0]->productsCount)->toBe(1)
        ->and($sections[0]->url)->toContain('/catalog/kliningovoe-oborudovanie/promyshlennye-pylesosy');
});

it('витрину бренда за раздел техники не принимает', function (): void {
    // «Выбор по производителю › …» — способ показать поставщика, а не
    // развилка по виду техники, и по числу товаров он всегда был бы сверху.
    $brands = Category::query()->create([
        'name' => 'Выбор по производителю',
        'slug' => 'vybor-po-proizvoditelyu',
        'parent_id' => Category::defaultParentKey(),
        'order' => 1,
        'is_active' => true,
    ]);

    $vendor = Category::query()->create([
        'name' => 'Пылесосы Vactool',
        'slug' => 'pylesosy-vactool',
        'parent_id' => $brands->id,
        'order' => 1,
        'is_active' => true,
    ]);

    $product = Product::query()->create([
        'name' => 'Промышленный пылесос VACTOOL SA36M60',
        'slug' => 'pylesos-sa36m60',
        'price_amount' => 73036,
        'currency' => 'RUB',
        'is_active' => true,
    ]);

    $vendor->products()->attach($product->id, ['is_primary' => true]);

    expect(assistantLookup()->sections('пылесос', 6))->toBe([]);
});

it('бренды каталога отдаёт без мусора', function (): void {
    assistantProduct(['brand' => 'Термит ']);
    assistantProduct(['slug' => 'p2', 'sku' => 'B', 'brand' => 'Hansmann']);
    assistantProduct(['slug' => 'p3', 'sku' => 'C', 'brand' => '']);
    assistantProduct(['slug' => 'p4', 'sku' => 'D', 'brand' => 'Hansmann', 'is_active' => false]);

    $brands = assistantLookup()->brands();
    sort($brands);

    expect($brands)->toBe(['Hansmann', 'Термит']);
});

it('строит фильтр Meilisearch из запроса', function (): void {
    // Единственное место, где фильтры вообще проверяемы: драйвер поиска
    // в тестах — collection, и options(['filter']) он игнорирует.
    expect(EloquentProductLookup::filterFor(new ProductQuery(text: 'компрессор')))->toBe('')
        ->and(EloquentProductLookup::filterFor(new ProductQuery(
            text: 'компрессор',
            inStockOnly: true,
            priceMin: 100000,
            priceMax: 200000,
            categoryId: 52,
            sectionIds: [57, 58, 59],
        )))->toBe('in_stock = true AND price >= 100000 AND price <= 200000'
            .' AND category_ids = 52 AND category_ids IN [57, 58, 59]');
});

/**
 * Раздел с домкратами для подбора по характеристикам.
 *
 * @param  array<string, array{0: int, 1: list<array{name: string, value: string}>}>  $jacks  имя => [цена, характеристики]
 */
function specSection(array $jacks): Category
{
    $root = Category::query()->create([
        'name' => 'Гидравлический инструмент',
        'slug' => 'gidravlicheskii-instrument',
        'parent_id' => Category::defaultParentKey(),
        'order' => 1,
        'is_active' => true,
    ]);

    $leaf = Category::query()->create([
        'name' => 'Домкраты гидравлические',
        'slug' => 'domkraty-gidravlicheskie',
        'parent_id' => $root->id,
        'order' => 1,
        'is_active' => true,
    ]);

    foreach ($jacks as $name => [$price, $specs]) {
        $product = Product::query()->create([
            'name' => $name,
            'slug' => Str::slug($name),
            'price_amount' => $price,
            'currency' => 'RUB',
            'in_stock' => true,
            'is_active' => true,
            'specs' => $specs,
        ]);

        $leaf->products()->attach($product->id, ['is_primary' => true]);
    }

    return $leaf;
}

it('подбор по характеристике смотрит весь раздел и отдаёт подходящие от дешёвых', function (): void {
    $leaf = specSection([
        'Домкрат 50 т' => [20000, [['name' => 'Грузоподьемность, т', 'value' => '50'], ['name' => 'Вес, кг', 'value' => '12']]],
        'Домкрат 200 т' => [90000, [['name' => 'Грузоподьемность, т', 'value' => '200'], ['name' => 'Вес, кг', 'value' => '70']]],
        'Домкрат 100 т' => [40000, [['name' => 'Грузоподьемность, т', 'value' => '100'], ['name' => 'Вес, кг', 'value' => '30']]],
        'Домкрат без карточки' => [10000, [['name' => 'Вес, кг', 'value' => '5']]],
    ]);

    // Не из раздела — даже подходящий по числу не в счёт.
    assistantProduct(['name' => 'Чужой домкрат 500 т', 'slug' => 'chuzhoi', 'specs' => [['name' => 'Грузоподьемность, т', 'value' => '500']]]);

    $matches = assistantLookup()->bySpec(new SpecQuery(
        conditions: [new SpecCondition(['грузоподъёмность'], min: 100, unit: 'т')],
        sectionIds: [$leaf->id],
    ));

    expect(array_map(fn ($card) => $card->name, $matches->cards))->toBe(['Домкрат 100 т', 'Домкрат 200 т'])
        ->and($matches->scanned)->toBe(4)
        ->and($matches->passed)->toBe(2)
        ->and($matches->failed)->toBe(1)
        ->and($matches->unknown())->toBe(1)
        // У карточки — только строка, по которой шёл отбор, а не весь список.
        ->and($matches->cards[0]->specs)->toBe([['name' => 'Грузоподьемность, т', 'value' => '100']]);
});

it('когда не подошло ничего, отдаёт ближайших к порогу, а не случайных', function (): void {
    // 09.10.2026: на «домкрат на 1000 тонн» бот назвал «самыми мощными» 200 т
    // из поиска по словам, хотя в разделе стояли 300 т.
    $leaf = specSection([
        'Домкрат 200 т' => [90000, [['name' => 'Грузоподьемность, т', 'value' => '200']]],
        'Домкрат 300 т' => [160000, [['name' => 'Грузоподьемность, т', 'value' => '300']]],
        'Домкрат 50 т' => [20000, [['name' => 'Грузоподьемность, т', 'value' => '50']]],
    ]);

    $matches = assistantLookup()->bySpec(new SpecQuery(
        conditions: [new SpecCondition(['грузоподъемность'], min: 1000, unit: 'т')],
        sectionIds: [$leaf->id],
    ));

    expect($matches->cards)->toBe([])
        ->and(array_map(fn ($card) => $card->name, $matches->nearest))->toBe(['Домкрат 300 т', 'Домкрат 200 т', 'Домкрат 50 т']);
});

it('при двух условиях товар обязан пройти оба', function (): void {
    $leaf = specSection([
        'Тяжёлый 100 т' => [30000, [['name' => 'Грузоподьемность, т', 'value' => '100'], ['name' => 'Вес, кг', 'value' => '90']]],
        'Лёгкий 100 т' => [45000, [['name' => 'Грузоподьемность, т', 'value' => '100'], ['name' => 'Вес, кг', 'value' => '40']]],
    ]);

    $matches = assistantLookup()->bySpec(new SpecQuery(
        conditions: [
            new SpecCondition(['грузоподъемность'], min: 100, unit: 'т'),
            new SpecCondition(['вес'], max: 60, unit: 'кг'),
        ],
        sectionIds: [$leaf->id],
    ));

    expect(array_map(fn ($card) => $card->name, $matches->cards))->toBe(['Лёгкий 100 т'])
        // Ближайшие при двух условиях не определены — и не выдумываются.
        ->and($matches->nearest)->toBe([]);
});
