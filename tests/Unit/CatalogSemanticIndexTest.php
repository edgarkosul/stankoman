<?php

use App\Services\Catalog\CatalogSemanticIndex;
use Meilisearch\Client;
use Meilisearch\Endpoints\Indexes;
use Meilisearch\Search\SearchResult;
use Mockery\MockInterface;

/*
 * Зеркало каталога без Meilisearch: клиент подменён, проверяется то, что
 * зеркало решает само, — доля смысла в гибриде и форма записи документов.
 *
 * Обе проверки стоят здесь потому, что обе ошибки БЕЗМОЛВНЫ. Перепутанная
 * доля смысла даёт «такого бренда у нас нет» при 237 товарах бренда,
 * а addDocuments вместо updateDocuments стирает у документов название
 * и артикул, оставляя векторы на месте: поиск словами умирает, гибрид
 * продолжает работать на одной семантике, и в логе ни строки.
 */

/**
 * @return array{0: CatalogSemanticIndex, 1: MockInterface}
 */
function semanticIndex(): array
{
    $index = Mockery::mock(Indexes::class);
    $client = Mockery::mock(Client::class);
    $client->shouldReceive('index')->with('products_semantic')->andReturn($index);

    return [
        new CatalogSemanticIndex(
            client: $client,
            indexName: 'products_semantic',
            embedder: 'shop',
            dimensions: 4,
            semanticRatio: 0.5,
            modelSemanticRatio: 0.2,
        ),
        $index,
    ];
}

function semanticHits(array $ids): SearchResult
{
    $result = Mockery::mock(SearchResult::class);
    $result->shouldReceive('getHits')
        ->andReturn(array_map(static fn (int $id): array => ['id' => $id], $ids));

    return $result;
}

it('обычному запросу отдаёт половину ранга смыслу', function (): void {
    [$semantic, $index] = semanticIndex();
    $params = null;

    $index->shouldReceive('search')->once()
        ->andReturnUsing(function (string $query, array $options) use (&$params): SearchResult {
            $params = $options;

            return semanticHits([7, 9]);
        });

    expect($semantic->search('kompressor dlya garazha', [0.1, 0.2, 0.3, 0.4]))->toBe([7, 9])
        ->and($params['hybrid'])->toBe(['embedder' => 'shop', 'semanticRatio' => 0.5]);
});

it('запросу-обозначению отдаёт смыслу пятую часть', function (): void {
    [$semantic, $index] = semanticIndex();
    $params = null;

    $index->shouldReceive('search')->once()
        ->andReturnUsing(function (string $query, array $options) use (&$params): SearchResult {
            $params = $options;

            return semanticHits([3]);
        });

    // Артикул — последовательность знаков, а не смысл: вектор на нём шумит.
    $semantic->search('BSM-115', [0.1, 0.2, 0.3, 0.4]);

    expect($params['hybrid']['semanticRatio'])->toBe(0.2);
});

it('названный бренд считает обозначением, хотя на модель он не похож', function (): void {
    [$semantic, $index] = semanticIndex();
    $params = null;

    $index->shouldReceive('search')->once()
        ->andReturnUsing(function (string $query, array $options) use (&$params): SearchResult {
            $params = $options;

            return semanticHits([1]);
        });

    /*
     * «Харсман есть?» у донора 09.09.2026: слова находили Hansmann, но
     * попадание с опечаткой стоит 0.494, а вектор выдуманного слова давал
     * случайному товару 0.783 — при доле 0.5 побеждал случайный товар.
     */
    $semantic->search('hansmann', [0.1, 0.2, 0.3, 0.4], designation: true);

    expect($params['hybrid']['semanticRatio'])->toBe(0.2);
});

it('без вектора ищет одними словами, без гибрида', function (): void {
    [$semantic, $index] = semanticIndex();
    $params = null;

    $index->shouldReceive('search')->once()
        ->andReturnUsing(function (string $query, array $options) use (&$params): SearchResult {
            $params = $options;

            return semanticHits([5]);
        });

    $semantic->search('stalex', []);

    expect($params)->not->toHaveKey('hybrid')
        ->and($params)->not->toHaveKey('vector');
});

it('документ без вектора в зеркало не уезжает', function (): void {
    [$semantic, $index] = semanticIndex();
    $sent = null;

    $index->shouldReceive('addDocuments')->once()
        ->andReturnUsing(function (array $documents) use (&$sent): array {
            $sent = $documents;

            return ['taskUid' => 1];
        });

    // Meilisearch отвергает документ без вектора вместе со всей пачкой,
    // поэтому такие отсеиваются здесь.
    $semantic->put(
        [['id' => 1, 'name' => 'Станок'], ['id' => 2, 'name' => 'Компрессор']],
        [1 => [0.1, 0.2, 0.3, 0.4]],
    );

    expect($sent)->toHaveCount(1)
        ->and($sent[0]['id'])->toBe(1)
        ->and($sent[0]['_vectors'])->toBe(['shop' => [0.1, 0.2, 0.3, 0.4]]);
});

it('поля обновляет мержем, а не заменой документа', function (): void {
    [$semantic, $index] = semanticIndex();

    // addDocuments здесь стёр бы название и артикул у всего каталога,
    // оставив векторы: поиск словами умер бы молча.
    $index->shouldReceive('updateDocuments')->once()->andReturn(['taskUid' => 2]);
    $index->shouldNotReceive('addDocuments');

    $semantic->refreshFields([['id' => 1, 'price' => 1000.0, 'in_stock' => true]]);
});

it('пустой список полей в Meilisearch не ходит вовсе', function (): void {
    [$semantic, $index] = semanticIndex();

    $index->shouldNotReceive('updateDocuments');
    $index->shouldNotReceive('deleteDocuments');

    $semantic->refreshFields([]);
    $semantic->forget([]);
});
