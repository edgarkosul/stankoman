<?php

use App\Services\Ai\Contracts\ProductLookup;
use App\Services\Ai\Data\CatalogSection;
use App\Services\Ai\Data\ProductCard;
use App\Services\Ai\Data\ProductMatches;
use App\Services\Ai\Data\ProductQuery;
use App\Services\Ai\Tools\SearchProductsTool;
use App\Services\Ai\Tools\ToolContext;
use App\Services\Catalog\CatalogBrands;

/*
 * Что выдача поиска говорит модели о себе.
 *
 * Здесь не поиск, а подписи к нему: каталог подменён. Каждая подпись
 * появилась потому, что без неё модель делала из верных данных неверный
 * вывод — «такого нет» при найденном товаре или точный ответ по выдаче,
 * собранной без половины запроса.
 */

/**
 * @param  Closure(ProductQuery): ProductMatches  $onSearch
 * @param  list<CatalogSection>  $sections
 */
function searchToolWith(Closure $onSearch, array $sections = []): SearchProductsTool
{
    $lookup = new class($onSearch, $sections) implements ProductLookup
    {
        public function __construct(private Closure $onSearch, private array $sectionList) {}

        public function find(string $sku, string $slug, bool $seesDiscounts): ?ProductCard
        {
            return null;
        }

        public function search(ProductQuery $query): ProductMatches
        {
            return ($this->onSearch)($query);
        }

        public function sections(string $query, int $limit): array
        {
            return $this->sectionList;
        }

        public function brands(): array
        {
            return [];
        }

        public function memberPrices(array $productIds): array
        {
            return [];
        }
    };

    return new SearchProductsTool($lookup, new CatalogBrands($lookup));
}

function searchToolCard(string $name = 'Генератор Tehnotek T1500'): ProductCard
{
    return new ProductCard(
        id: 1,
        name: $name,
        url: 'https://intertooler.ru/product/generator-tehnotek-t1500',
        sku: 'T1500',
        brand: 'Tehnotek',
        inStock: true,
        price: 19990,
        priceNote: '',
        vatNote: 'НДС 22% в том числе',
        warranty: '12 мес.',
        specs: [],
        description: null,
    );
}

it('говорит, что выдача собрана без незнакомых слов', function (): void {
    // «Электропитбайк white siberia belluga» поиск отдаёт электромотоциклами:
    // слова «электропитбайк» нет ни в одном товаре. Без подписи модель подаёт
    // такую выдачу как точный ответ; витрина в этом месте пишет «Не нашлось».
    $tool = searchToolWith(static fn (): ProductMatches => new ProductMatches(
        [searchToolCard()],
        'tehnotek',
        ['бензогенератор'],
        relaxed: true,
    ));

    $result = $tool->run(['query' => 'бензогенератор tehnotek'], new ToolContext);

    expect($result)->toContain('«бензогенератор» — таких слов нет ни в одном товаре')
        ->and($result)->toContain('выдача собрана БЕЗ НИХ')
        ->and($result)->toContain('не выдавай найденное за то, что он просил');
});

it('на пустой выдаче называет слова, которых нет в каталоге', function (): void {
    // Незнакомое слово — чаще всего вид техники, названный не так, как
    // в названиях товаров. Назвать его модели — дать ей второй поиск
    // вместо ответа «такого у нас нет».
    $tool = searchToolWith(static fn (): ProductMatches => new ProductMatches([], 'gbo ballon', ['гбо']));

    $result = $tool->run(['query' => 'гбо баллон'], new ToolContext);

    expect($result)->toContain('В каталоге ничего не нашлось')
        ->and($result)->toContain('Слов «гбо» нет ни в одном товаре')
        ->and($result)->toContain('Не предполагай, что товар есть');
});

it('без незнакомых слов пустая выдача о них молчит', function (): void {
    $tool = searchToolWith(static fn (): ProductMatches => new ProductMatches([], 'kompressor'));

    expect($tool->run(['query' => 'компрессор'], new ToolContext))
        ->not->toContain('нет ни в одном товаре');
});

it('подписывает запрос тем текстом, что реально ушёл в индекс', function (): void {
    // «Сталекс» буквальной транслитерацией — staleks, а ищется stalex:
    // подпись о смене алфавита обязана показывать второе, иначе модель
    // не свяжет написание в вопросе с брендом в выдаче.
    $tool = searchToolWith(static fn (): ProductMatches => new ProductMatches(
        [searchToolCard('Ленточнопильный станок Stalex BS-712N')],
        'stalex',
    ));

    expect($tool->run(['query' => 'сталекс'], new ToolContext))
        ->toContain('искали в латинской записи «stalex»');
});

it('латинский запрос подписью об алфавите не сопровождает', function (): void {
    $tool = searchToolWith(static fn (): ProductMatches => new ProductMatches([searchToolCard()], 'tehnotek t1500'));

    expect($tool->run(['query' => 'tehnotek t1500'], new ToolContext))
        ->not->toContain('искали в латинской записи');
});

it('пустоту от фильтра по типу не выдаёт за пустой каталог', function (): void {
    // Замер 15.09.2026: «ленточнопильный станок» с типом «ручной» давал
    // пустоту, хотя подходящих товаров 141. Фильтр ставил не бот, и снять
    // его модель не может — повтор без типа делает инструмент.
    $asked = [];

    $tool = searchToolWith(
        static function (ProductQuery $query) use (&$asked): ProductMatches {
            $asked[] = $query->sectionIds;

            return new ProductMatches($query->sectionIds === [] ? [searchToolCard('Ленточнопильный станок BSM-115')] : [], 'stanok');
        },
        [new CatalogSection(128, 'Сварочное оборудование › Аппараты ручной лазерной сварки', 'https://x/128', 12)],
    );

    $result = $tool->run(['query' => 'ленточнопильный станок', 'section' => 'ручной'], new ToolContext);

    expect($asked)->toBe([[128], []])
        ->and($result)->toContain('тип «ручной» НЕ применён')
        ->and($result)->toContain('Ленточнопильный станок BSM-115');
});
