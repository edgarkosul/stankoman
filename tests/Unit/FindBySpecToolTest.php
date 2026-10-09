<?php

use App\Services\Ai\Contracts\ProductLookup;
use App\Services\Ai\Data\CatalogSection;
use App\Services\Ai\Data\ProductCard;
use App\Services\Ai\Data\ProductMatches;
use App\Services\Ai\Data\ProductQuery;
use App\Services\Ai\Data\SpecMatches;
use App\Services\Ai\Data\SpecQuery;
use App\Services\Ai\Tools\FindBySpecTool;
use App\Services\Ai\Tools\ToolContext;

/*
 * Что подбор по характеристике говорит модели о себе.
 *
 * Каталог подменён: здесь проверяются не числа, а подписи и маршрут.
 * Каждая подпись появилась после замера 09.10.2026 на 46 вопросах с порогом:
 * без неё модель делала из верной выдачи неверный вывод.
 */

/**
 * @param  list<CatalogSection>  $sections
 */
function specTool(SpecMatches $matches, array $sections, ?SpecQuery &$asked = null): FindBySpecTool
{
    $lookup = new class($matches, $sections, $asked) implements ProductLookup
    {
        public function __construct(private SpecMatches $matches, private array $sectionList, public ?SpecQuery &$asked) {}

        public function find(string $sku, string $slug, bool $seesDiscounts): ?ProductCard
        {
            return null;
        }

        public function search(ProductQuery $query): ProductMatches
        {
            return new ProductMatches([], '');
        }

        public function bySpec(SpecQuery $query): SpecMatches
        {
            $this->asked = $query;

            return $this->matches;
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

    return new FindBySpecTool($lookup);
}

function specCard(string $name, int $price, string $line): ProductCard
{
    return new ProductCard(
        id: $price,
        name: $name,
        url: 'https://intertooler.ru/product/'.$price,
        sku: '',
        brand: '',
        inStock: true,
        price: $price,
        priceNote: '',
        vatNote: 'НДС 22% в том числе',
        warranty: null,
        specs: [['name' => 'Ширина реза', 'value' => $line]],
        description: null,
    );
}

function specSectionOf(int $id, string $path): CatalogSection
{
    return new CatalogSection(id: $id, path: $path, url: 'https://intertooler.ru/catalog/'.$id, productsCount: 10);
}

$planer = ['kind' => 'фуговальный станок', 'conditions' => [['names' => ['ширина строгания'], 'min' => 400, 'unit' => 'мм']]];

it('берёт только разделы, названные словами вида техники', function (): void {
    // 09.10.2026: на «токарный станок по металлу» поиск разделов принёс и станки
    // по дереву, подбор просмотрел их целиком, и бот предложил станок по дереву.
    $asked = null;
    $tool = specTool(new SpecMatches([], 0, 0, 0, [0], []), [
        specSectionOf(1, 'Металлообработка › Токарные станки по металлу'),
        specSectionOf(2, 'Деревообработка › Токарные станки по дереву'),
    ], $asked);

    $tool->run(['kind' => 'токарный станок по металлу', 'conditions' => [['names' => ['между центрами'], 'min' => 1000, 'unit' => 'мм']]], new ToolContext);

    expect($asked->sectionIds)->toBe([1]);
});

it('незнакомую единицу не сравнивает «как есть», а просит переписать', function (): void {
    $asked = null;
    $tool = specTool(new SpecMatches([], 0, 0, 0, [0], []), [specSectionOf(1, 'Компрессоры › Винтовые компрессоры')], $asked);

    $result = $tool->run(['kind' => 'винтовой компрессор', 'conditions' => [['names' => ['производительность'], 'min' => 3, 'unit' => 'кубов в минуту']]], new ToolContext);

    // «3» против «3600 л/мин» прошло бы у всего раздела.
    expect($result)->toContain('не распознана')
        ->and($asked)->toBeNull();
});

it('условие без порога не принимает', function (): void {
    $tool = specTool(new SpecMatches([], 0, 0, 0, [0], []), [specSectionOf(1, 'Компрессоры › Передвижные компрессоры')]);

    expect($tool->run(['kind' => 'передвижной компрессор', 'conditions' => [['names' => ['передвижной']]]], new ToolContext))
        ->toContain('порог — min, max или оба');
});

it('говорит, сколько не проверить, и что это не «нет в магазине»', function () use ($planer): void {
    $tool = specTool(new SpecMatches(
        cards: [specCard('LTT MB524', 166750, '400 мм'), specCard('LTT MB525', 201250, '510 мм')],
        scanned: 71, passed: 12, failed: 50, noData: [9], names: [],
    ), [specSectionOf(1, 'Деревообработка › Фуговальные станки')]);

    $context = new ToolContext;
    $result = $tool->run($planer, $context);

    expect($result)->toContain('Просмотрены ВСЕ 71 товаров')
        ->toContain('Подошли по числу: 12. Не подошли: 50. Не проверить — нужной строки или числа в карточке нет: 9')
        ->toContain('не «отсутствуют в магазине»')
        ->toContain('- [LTT MB524](https://intertooler.ru/product/166750) — 166 750 руб.')
        ->toContain('  · Ширина реза: 400 мм')
        ->toContain('тот же вызов с price_min = 201251')
        // Ссылка, которую модель перенесёт в ответ, известна стражу ссылок.
        ->and(array_column($context->shownProducts, 'url'))->toContain('https://intertooler.ru/product/166750');
});

it('когда строки нет у многих, сначала велит повторить — с похожими названиями первыми', function () use ($planer): void {
    // 09.10.2026: «ширина строгания» нашлась у 33 фуговальных станков из 71,
    // у 34 та же величина записана «Ширина реза», а модель ответила «подошёл один».
    $matches = new SpecMatches(
        cards: [specCard('REALREZ RWPT 410', 281796, '410 мм')],
        scanned: 71, passed: 1, failed: 32, noData: [38],
        names: [['name' => 'Мощность, кВт', 'count' => 60], ['name' => 'Ширина реза', 'count' => 34]],
    );
    $tool = specTool($matches, [specSectionOf(1, 'Деревообработка › Фуговальные станки')]);

    $first = $tool->run($planer, new ToolContext);

    expect($first)->toStartWith('СНАЧАЛА ПОВТОРИ ВЫЗОВ')
        ->toContain('Похожие на твою: «Ширина реза» (34)')
        ->toContain('Остальные с числами: «Мощность, кВт» (60)');

    // Повтор уже был — «повтори ещё» гоняло бы модель до потолка шагов.
    $context = new ToolContext;
    $context->recordCall('find_by_spec', ['kind' => 'фуговальный станок']);

    expect($tool->run($planer, $context))
        ->not->toContain('СНАЧАЛА ПОВТОРИ ВЫЗОВ')
        ->toContain('иначе отвечай по тому, что подошло');
});

it('ничего не подошло — отдаёт ближайших к порогу и велит брать их, а не поиск по словам', function (): void {
    $tool = specTool(new SpecMatches(
        cards: [], scanned: 89, passed: 0, failed: 89, noData: [0], names: [],
        nearest: [specCard('Домкрат 300 т', 161173, '300')],
    ), [specSectionOf(1, 'Гидравлический инструмент › Домкраты гидравлические')]);

    $result = $tool->run(['kind' => 'гидравлический домкрат', 'conditions' => [['names' => ['грузоподъемность'], 'min' => 1000, 'unit' => 'т']]], new ToolContext);

    expect($result)->toContain('Под условие не подошло ничего — скажи это прямо.')
        ->toContain('бери их отсюда, а не из поиска по словам')
        ->toContain('[Домкрат 300 т]')
        // Всё проверено — повторять нечего.
        ->not->toContain('ПОВТОРИ');
});
