<?php

use App\Services\Catalog\CatalogBrands;

/*
 * Сопоставление запроса со списком брендов — чистая функция, база не нужна.
 *
 * Половина файла проверяет не логику, а КАЛИБРОВКУ на живом списке: правила
 * перенесены от донора, а список брендов у нас свой, и на нём донорские
 * правила ошибались (замер 15.09.2026, 60 брендов дева, 134 вопроса).
 * Список ниже — снимок того дня; новый поставщик с именем-словом требует
 * прогнать этот файл заново.
 */

$catalog = ['Dali', 'ПТК', 'LTT', 'REALREZ', 'START', 'Harrison', 'Vektor', 'CrossAir', 'Инстан',
    'Tornado', 'TSS', 'Stalex', 'MetalMaster', 'BELMASH', 'EFCO', 'Энкор Корвет', 'JIB', 'Tehnotek',
    'Aurora', 'KROM', 'Vactool', 'MetMachine', 'Spitzenreiter', 'Metaltec', 'Warrior', 'ВедКом',
    'Хайтек Инструмент', 'HITCOM', 'Borey', 'KEOS', 'WHITE SIBERIA', 'TRITON', 'Bycon', 'Diamaster',
    'Ironmac', 'Hansmann', 'Velargos', 'Дастпром', 'Shelf', 'Zero', 'Degeshi', 'Everlast', 'Tech-Nick',
    'ESAB', 'Hualian', 'TOR', 'Термит ', 'KEN', 'IRON MAC', 'SIBERTON', 'EVOline', 'Dexi', 'Diam',
    'Xeleron', 'KMT', 'Hualian Machinery', 'Восточные тигры', 'Banso', 'Remeza', 'JULI'];

/** Слова-двойники по умолчанию — те же, что в config/ai_support.php. */
$lookalikes = ['стал', 'металл', 'сверл', 'зерн'];

it('узнаёт бренд, написанный кириллицей', function () use ($catalog, $lookalikes): void {
    // Тот самый случай, на котором 07.09.2026 донорский бот заявил, что
    // бренда нет, а в каталоге его 237 товаров.
    expect(CatalogBrands::match('компрессор хансман', $catalog, $lookalikes))->toBe('Hansmann')
        ->and(CatalogBrands::match('нужен ремеза 100 литров', $catalog, $lookalikes))->toBe('Remeza')
        ->and(CatalogBrands::match('станок металтек', $catalog, $lookalikes))->toBe('Metaltec')
        ->and(CatalogBrands::match('белмаш', $catalog, $lookalikes))->toBe('BELMASH')
        // Бренды, записанные кириллицей в самом каталоге, тоже узнаются.
        ->and(CatalogBrands::match('компрессор ведком', $catalog, $lookalikes))->toBe('ВедКом')
        ->and(CatalogBrands::match('пылесос дастпром', $catalog, $lookalikes))->toBe('Дастпром');
});

it('узнаёт бренд из нескольких слов только целиком', function () use ($catalog, $lookalikes): void {
    expect(CatalogBrands::match('станок энкор корвет', $catalog, $lookalikes))->toBe('Энкор Корвет')
        // «корвет» сам по себе — половина составного имени, и совпадает
        // со словом из вопроса слишком легко.
        ->and(CatalogBrands::match('нужен корвет', $catalog, $lookalikes))->toBeNull();
});

it('не принимает за бренд короткие слова из вопроса', function () use ($catalog, $lookalikes): void {
    // Короче четырёх знаков в каталоге семь брендов: JIB, KEN, KMT, LTT,
    // TOR, TSS, ПТК. Порог их теряет, и это дешевле, чем ловить «квт»
    // и «тор» из каждого второго вопроса о мощности и оснастке.
    expect(CatalogBrands::match('компрессор 11 квт', $catalog, $lookalikes))->toBeNull()
        ->and(CatalogBrands::match('нужен домкрат tor', $catalog, $lookalikes))->toBeNull()
        ->and(CatalogBrands::match('птк', $catalog, $lookalikes))->toBeNull();
});

it('прощает опечатку так же, как её прощает индекс', function () use ($catalog, $lookalikes): void {
    // «Харсман есть?» у донора 09.09.2026: Meilisearch мерит опечатки
    // по началу слова, где замена одна, и находит; справочник обязан
    // считать так же, иначе фильтр слетает не тогда, когда нужно.
    expect(CatalogBrands::match('компрессор hansman', $catalog, $lookalikes))->toBe('Hansmann')
        ->and(CatalogBrands::match('Харсман есть?', $catalog, $lookalikes))->toBe('Hansmann')
        ->and(CatalogBrands::match('аврора полуавтомат', $catalog, $lookalikes))->toBe('Aurora');
});

it('начало слова доводит до бренда, но только от пяти знаков', function () use ($catalog, $lookalikes): void {
    /*
     * У донора хватало четырёх, у нас нет: мягкий знак транслитератор
     * отдаёт апострофом, слово режется по нему, и «сталь» приходит как
     * «stal» — начало Stalex. Пять знаков сохраняют донорский случай
     * и убирают четырёхбуквенные огрызки.
     */
    expect(CatalogBrands::match('хансм', $catalog, $lookalikes))->toBe('Hansmann')
        ->and(CatalogBrands::match('ханс', $catalog, $lookalikes))->toBeNull();
});

it('точное написание побеждает опечатку, где бы оно ни лежало в списке', function (): void {
    // Иначе метод возвращает не то имя: «huter» отстоит от начала
    // «cuteral» на одну замену, а CUTERAL в списке стоит раньше.
    expect(CatalogBrands::match('генератор Huter', ['CUTERAL', 'Huter']))->toBe('Huter');
});

it('не принимает за бренд слова, без которых магазин станков не разговаривает', function () use ($catalog, $lookalikes): void {
    /*
     * Ровно те 13 случаев из замера 15.09.2026, которые донорские правила
     * относили к брендам. Цена ошибки — снятый фильтр по типу техники:
     * «станок для резки металла» с типом «ленточнопильный» терял бы тип
     * там, где он нужнее всего.
     */
    $questions = ['резка стали', 'пила по стали', 'нержавеющая сталь', 'стальной лист',
        'станок для стали', 'стали 3 мм', 'станок для резки металла', 'металла 5 мм',
        'толщина металла', 'диаметр сверла', 'зерно абразива', 'сверло по металлу',
        'ленточнопильный станок по металлу'];

    foreach ($questions as $question) {
        expect(CatalogBrands::match($question, $catalog, $lookalikes))
            ->toBeNull("«{$question}» не должен считаться брендовым запросом");
    }
});

it('без списка двойников те же слова снова становятся брендами', function () use ($catalog): void {
    // Тест держит причину, по которой список вообще есть: правилами эти
    // случаи не отделить — «стали» отстоит от «stalex» на одну замену
    // ровно так же, как «харсман» от «hansmann».
    expect(CatalogBrands::match('резка стали', $catalog))->toBe('Stalex')
        ->and(CatalogBrands::match('толщина металла', $catalog))->toBe('MetalMaster')
        ->and(CatalogBrands::match('зерно абразива', $catalog))->toBe('Zero');
});

it('на обычном вопросе о технике молчит', function () use ($catalog, $lookalikes): void {
    $questions = ['компрессор для покраски', 'сварочный полуавтомат', 'винтовой компрессор 7.5 квт',
        'пылесос для мастерской', 'мойка высокого давления', 'фуговальный станок', 'рейсмус',
        'домкрат гидравлический 10 тонн', 'как оплатить по счёту', 'гарантия на компрессор',
        'доставка в краснодар', 'плазменная резка', 'торцовочная пила', 'аргонодуговая сварка',
        'осушитель воздуха', 'поломоечная машина', 'культиватор', 'рольганг', 'краги сварочные',
        'кромкооблицовочный станок', ''];

    foreach ($questions as $question) {
        expect(CatalogBrands::match($question, $catalog, $lookalikes))
            ->toBeNull("«{$question}» не должен считаться брендовым запросом");
    }
});

it('известные и принятые потери: бренд, совпавший с обычным словом', function () use ($catalog, $lookalikes): void {
    /*
     * Dali (607 товаров, крупнейший бренд каталога) и START пишутся
     * ровно как русские слова «дали» и «старт». Здесь мы ошибаемся
     * сознательно в сторону бренда: так покупатель пишет и сам бренд,
     * а последствие ошибки мягкое — несработавший фильтр по типу техники,
     * а не потерянная выдача. Тест стоит, чтобы это решение не выглядело
     * случайным, когда кто-то на него наткнётся.
     */
    expect(CatalogBrands::match('мне дали ссылку на товар', $catalog, $lookalikes))->toBe('Dali')
        ->and(CatalogBrands::match('кнопка старт не работает', $catalog, $lookalikes))->toBe('START');
});

it('переживает мусор в самом справочнике', function () use ($lookalikes): void {
    // В каталоге лежат «Термит » с хвостовым пробелом, «IRON MAC» рядом
    // с «Ironmac» и «Hualian» рядом с «Hualian Machinery».
    $messy = ['Термит ', 'IRON MAC', 'Ironmac', 'Hualian', 'Hualian Machinery', 'Tech-Nick'];

    expect(CatalogBrands::match('нужен термит', $messy, $lookalikes))->toBe('Термит ')
        ->and(CatalogBrands::match('айронмак? нет, iron mac', $messy, $lookalikes))->toBe('IRON MAC')
        ->and(CatalogBrands::match('запайщик hualian', $messy, $lookalikes))->toBe('Hualian')
        ->and(CatalogBrands::match('tech-nick пила', $messy, $lookalikes))->toBe('Tech-Nick');
});
