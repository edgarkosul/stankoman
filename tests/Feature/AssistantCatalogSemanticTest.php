<?php

use App\Models\Category;
use App\Models\Product;
use App\Services\Ai\Contracts\LlmClient;
use App\Services\Ai\Data\ProductQuery;
use App\Services\Ai\Providers\FakeLlmClient;
use App\Services\Ai\Support\PiiRedactor;
use App\Services\Ai\Support\ProductTextExtractor;
use App\Services\Catalog\CatalogSemanticIndex;
use App\Services\Catalog\CatalogSemanticSearch;
use App\Shop\CatalogSections;
use App\Shop\EloquentProductLookup;
use App\Shop\ProductEmbeddingText;
use App\Support\Products\ProductSpecs;
use App\Support\Search\ProductTextSearch;
use Meilisearch\Client;
use Meilisearch\Endpoints\Indexes;
use Meilisearch\Search\SearchResult;

/*
 * Смысловой поиск по каталогу на живых моделях (фаза 8).
 *
 * Meilisearch здесь подменён: драйвер Scout в тестах — collection, своего
 * зеркала у него нет. Проверяется шов, а не качество подбора: что бот идёт
 * в зеркало, когда оно собрано, и продолжает работать словами, когда нет.
 * Качество меряется живыми запросами на dev, а не тестом.
 */

function semanticMirror(array $ids, int $documents = 3762): CatalogSemanticIndex
{
    $hits = Mockery::mock(SearchResult::class);
    $hits->shouldReceive('getHits')
        ->andReturn(array_map(static fn (int $id): array => ['id' => $id], $ids));

    $index = Mockery::mock(Indexes::class);
    $index->shouldReceive('stats')->andReturn(['numberOfDocuments' => $documents]);
    $index->shouldReceive('search')->andReturn($hits);

    $client = Mockery::mock(Client::class);
    $client->shouldReceive('index')->andReturn($index);

    return new CatalogSemanticIndex(
        client: $client,
        indexName: 'products_semantic',
        embedder: 'shop',
        dimensions: 1024,
        semanticRatio: 0.5,
        modelSemanticRatio: 0.2,
    );
}

function semanticLookup(?CatalogSemanticSearch $semantic, ?ProductTextSearch $search = null): EloquentProductLookup
{
    $search ??= new ProductTextSearch;

    return new EloquentProductLookup(
        search: $search,
        sections: new CatalogSections($search),
        specs: new ProductSpecs,
        extractor: new ProductTextExtractor,
        descriptionLimit: 3000,
        specsInList: 8,
        vatRate: 22,
        semantic: $semantic,
    );
}

function semanticProduct(array $overrides = []): Product
{
    return Product::query()->create(array_merge([
        'name' => 'Ленточнопильный станок Stalex BS-712N',
        'slug' => 'stalex-bs-712n',
        'sku' => 'BS-712N',
        'brand' => 'Stalex',
        'price_amount' => 189000,
        'currency' => 'RUB',
        'in_stock' => true,
        'is_active' => true,
        'specs' => [
            ['name' => 'Мощность двигателя, кВт', 'value' => '1.1'],
            ['name' => 'Диаметр реза, мм', 'value' => '180'],
        ],
        'description' => '<p>Станок для резки трубы и профиля в гараже и в цехе.</p>',
    ], $overrides));
}

it('текст для вектора несёт название, бренд, раздел, характеристики и описание', function (): void {
    $root = Category::query()->create([
        'name' => 'Металлообработка',
        'slug' => 'metalloobrabotka',
        'parent_id' => Category::defaultParentKey(),
        'order' => 1,
        'is_active' => true,
    ]);

    $leaf = Category::query()->create([
        'name' => 'Ленточнопильные станки',
        'slug' => 'lentochnopilnye-stanki',
        'parent_id' => $root->id,
        'order' => 1,
        'is_active' => true,
    ]);

    $product = semanticProduct();
    $leaf->products()->attach($product->id, ['is_primary' => true]);

    $text = (new ProductEmbeddingText(new ProductTextExtractor, new ProductSpecs, 1200))
        ->for($product->fresh());

    /*
     * Раздел в тексте — не украшение: «ленточнопильный станок» покупатель
     * называет словами каталога, которых в самой карточке может не быть.
     */
    expect($text)->toContain('Ленточнопильный станок Stalex BS-712N')
        ->and($text)->toContain('Бренд: Stalex')
        ->and($text)->toContain('Раздел: Металлообработка › Ленточнопильные станки')
        ->and($text)->toContain('Мощность двигателя, кВт: 1.1')
        ->and($text)->toContain('резки трубы')
        // Цена и наличие в текст не входят: иначе каждую ночь после пересчёта
        // курсов каталог эмбеддился бы заново, за деньги.
        ->and($text)->not->toContain('189000')
        ->and($text)->not->toContain('наличии');
});

it('текст обрезается по потолку и рвётся по слову', function (): void {
    $product = semanticProduct(['description' => '<p>'.str_repeat('станок для резки металла ', 200).'</p>']);

    $text = (new ProductEmbeddingText(new ProductTextExtractor, new ProductSpecs, 300))->for($product);

    expect(mb_strlen($text))->toBeLessThanOrEqual(300)
        ->and($text)->toStartWith('Ленточнопильный станок Stalex BS-712N')
        ->and($text)->not->toEndWith('стан');
});

it('собранное зеркало задаёт и выдачу, и её порядок', function (): void {
    $first = semanticProduct();
    $second = semanticProduct(['name' => 'Компрессор Hansmann', 'slug' => 'hansmann', 'sku' => 'RSE']);

    // Зеркало отвечает своим порядком — по релевантности гибрида, а не базы.
    $semantic = new CatalogSemanticSearch(
        index: semanticMirror([$second->id, $first->id]),
        llm: fn (): LlmClient => new FakeLlmClient(1024),
        redactor: new PiiRedactor,
    );

    $matches = semanticLookup($semantic)->search(new ProductQuery(text: 'чем резать трубу в гараже'));

    expect(array_map(static fn ($card): int => $card->id, $matches->cards))->toBe([$second->id, $first->id])
        ->and($matches->semantic)->toBeTrue();
});

it('пустое зеркало не зовёт шлюз и оставляет поиск по словам', function (): void {
    semanticProduct();

    // Ни одного вызова эмбеддинга: считать вектор, когда искать им негде, —
    // это чистая трата денег на каждый вопрос покупателя.
    $llm = Mockery::mock(LlmClient::class);
    $llm->shouldNotReceive('embed');

    $semantic = new CatalogSemanticSearch(
        index: semanticMirror([], documents: 0),
        llm: fn (): LlmClient => $llm,
        redactor: new PiiRedactor,
    );

    $matches = semanticLookup($semantic)->search(new ProductQuery(text: 'Stalex'));

    expect($matches->semantic)->toBeFalse()
        ->and($matches->cards)->toHaveCount(1);
});

it('смысловая выдача называет слова, которых в каталоге нет', function (): void {
    $product = semanticProduct();

    /*
     * Главная ловушка гибрида: вектор всегда назовёт ближайшего соседа,
     * поэтому «не нашлось» у него не бывает вовсе. Выдуманное слово даёт
     * случайному товару 0.78 «сходства» — выше, чем стоит точное попадание
     * с опечаткой, — и без этой приписки бот подал бы находку как ответ.
     */
    $search = new ProductTextSearch(
        wordHits: fn (): array => [0, 12],
        brands: fn (): array => [],
    );

    $semantic = new CatalogSemanticSearch(
        index: semanticMirror([$product->id]),
        llm: fn (): LlmClient => new FakeLlmClient(1024),
        redactor: new PiiRedactor,
    );

    $matches = semanticLookup($semantic, $search)->search(new ProductQuery(text: 'квазимодо станок'));

    expect($matches->cards)->toHaveCount(1)
        ->and($matches->unmatched)->toBe(['квазимодо'])
        // Повтора без слова не было: выдача и так не пуста.
        ->and($matches->relaxed)->toBeFalse()
        ->and($matches->semantic)->toBeTrue();
});
