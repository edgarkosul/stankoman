<?php

use App\Support\Search\ProductTextSearch;
use Tests\TestCase;

uses(TestCase::class);

/*
 * Порядок работы общей точки входа. Индекс не нужен: драйвер в тестах —
 * collection, он matchingStrategy не знает, поэтому проба по словам
 * и сам поиск подменяются замыканиями, а замыкание поиска смотрит только
 * на текст запроса, который ему дали.
 */

/**
 * @param  array<string, list<int>>  $found  текст запроса → найденные ключи
 * @return array{0: Closure, 1: ArrayObject<int, string>}
 */
function fakeProductTextSearch(array $found): array
{
    $asked = new ArrayObject;

    $run = function ($search) use ($found, $asked) {
        $asked[] = $search->query;

        return collect($found[$search->query] ?? []);
    };

    return [$run, $asked];
}

it('запрос, который что-то нашёл, не пробует слова и не повторяется', function (): void {
    [$run, $asked] = fakeProductTextSearch(['tehnotek benzogenerator' => [1, 2]]);
    $search = new ProductTextSearch(
        wordHits: fn () => throw new RuntimeException('проба не нужна'),
        brands: fn () => [],
    );

    $outcome = $search->run('Tehnotek бензогенератор', $run);

    expect($outcome->result->all())->toBe([1, 2])
        ->and($outcome->relaxed)->toBeFalse()
        ->and($outcome->unmatched)->toBe([])
        ->and($asked->getArrayCopy())->toBe(['tehnotek benzogenerator']);
});

it('смысловому поиску называет незнакомые слова даже при полной выдаче', function (): void {
    /*
     * Гибрид с вектором возвращает ближайших соседей почти всегда, то есть
     * пустой выдачи — единственного нашего признака «таких слов в каталоге
     * нет» — у него не бывает. Без пробы шум вектора уехал бы к покупателю
     * как точный ответ.
     */
    [$run, $asked] = fakeProductTextSearch(['elektropitbajk belluga' => [4, 5]]);
    $search = new ProductTextSearch(
        wordHits: fn (): array => [0, 12],
        brands: fn () => [],
    );

    $outcome = $search->run('электропитбайк belluga', $run, probeAlways: true);

    expect($outcome->result->all())->toBe([4, 5])
        ->and($outcome->unmatched)->toBe(['электропитбайк'])
        // Выдача уже есть — повторять нечего, второго поиска быть не должно.
        ->and($outcome->relaxed)->toBeFalse()
        ->and($asked->getArrayCopy())->toBe(['elektropitbajk belluga']);
});

it('пустой запрос повторяет без незнакомого первого слова', function (): void {
    [$run, $asked] = fakeProductTextSearch(['tehnotek' => [7]]);
    $probed = [];
    $search = new ProductTextSearch(
        wordHits: function (array $words) use (&$probed): array {
            $probed[] = $words;

            return [0, 78];
        },
        brands: fn () => [],
    );

    $outcome = $search->run('бензогенератор  tehnotek', $run);

    expect($outcome->result->all())->toBe([7])
        ->and($outcome->relaxed)->toBeTrue()
        ->and($outcome->text)->toBe('tehnotek')
        // Покупателю — его собственное слово, а не транслитерация.
        ->and($outcome->unmatched)->toBe(['бензогенератор'])
        ->and($probed)->toBe([['benzogenerator', 'tehnotek']])
        ->and($asked->getArrayCopy())->toBe(['benzogenerator tehnotek', 'tehnotek']);
});

it('повторяет не больше одного раза', function (): void {
    [$run, $asked] = fakeProductTextSearch([]);
    $search = new ProductTextSearch(wordHits: fn () => [0, 3, 3], brands: fn () => []);

    $outcome = $search->run('электропитбайк white belluga', $run);

    expect($asked->getArrayCopy())->toBe(['elektropitbajk white belluga', 'white belluga'])
        ->and($outcome->relaxed)->toBeFalse()
        ->and($outcome->text)->toBe('elektropitbajk white belluga')
        ->and($outcome->unmatched)->toBe(['электропитбайк']);
});

it('обрывок запроса не повторяет, но слова без попаданий отдаёт', function (): void {
    // Витрине они нужны для поиска внутри слов названия.
    [$run, $asked] = fakeProductTextSearch(['35l' => [9]]);
    $search = new ProductTextSearch(wordHits: fn () => [0, 0, 1], brands: fn () => []);

    $outcome = $search->run('баллон гбо 35л', $run);

    expect($outcome->result->all())->toBe([])
        ->and($outcome->relaxed)->toBeFalse()
        ->and($outcome->unmatched)->toBe(['баллон', 'гбо'])
        ->and($asked->getArrayCopy())->toBe(['ballon gbo 35l']);
});

it('одно слово и слишком длинную фразу по словам не пробует', function (): void {
    [$run] = fakeProductTextSearch([]);
    $search = new ProductTextSearch(
        wordHits: fn () => throw new RuntimeException('проба не нужна'),
        brands: fn () => [],
    );

    expect($search->run('картофелекопалка', $run)->unmatched)->toBe([])
        ->and($search->run('маленький мощный электросамокат дальность пробега 200 км с сиденьем', $run)->unmatched)
        ->toBe([]);
});

it('без пробы (не Meilisearch или ошибка) остаётся честная пустота', function (): void {
    [$run, $asked] = fakeProductTextSearch(['tehnotek' => [7]]);
    $search = new ProductTextSearch(wordHits: fn () => null, brands: fn () => []);

    $outcome = $search->run('бензогенератор tehnotek', $run);

    expect($outcome->result->all())->toBe([])
        ->and($outcome->unmatched)->toBe([])
        ->and($asked->getArrayCopy())->toBe(['benzogenerator tehnotek']);
});

it('на драйвере collection проба выключается сама', function (): void {
    [$run, $asked] = fakeProductTextSearch([]);

    $outcome = (new ProductTextSearch(brands: fn () => []))->run('бензогенератор tehnotek', $run);

    expect($outcome->unmatched)->toBe([])
        ->and($asked->getArrayCopy())->toBe(['benzogenerator tehnotek']);
});

it('подставляет бренд, а список брендов берёт только для кириллицы', function (): void {
    [$run, $asked] = fakeProductTextSearch(['kompressor crossair' => [1]]);

    (new ProductTextSearch(wordHits: fn () => [], brands: fn () => ['CrossAir']))
        ->run('компрессор кроссэйр', $run);

    (new ProductTextSearch(
        wordHits: fn () => [],
        brands: fn () => throw new RuntimeException('латинице бренды не нужны'),
    ))->run('CrossAir KS-50', $run);

    expect($asked->getArrayCopy())->toBe(['kompressor crossair', 'CrossAir KS-50']);
});
