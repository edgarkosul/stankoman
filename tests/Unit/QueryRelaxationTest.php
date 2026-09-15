<?php

use App\Support\Search\QueryRelaxation;

// Какие слова выбросить из пустого запроса — чистая функция, индекс не нужен.
// Случаи взяты из 25 пустых запросов с прода (замер 15.09.2026).

it('пробует по словам только запрос из двух–восьми слов', function (): void {
    expect(QueryRelaxation::worthProbing(['tehnotek']))->toBeFalse()
        ->and(QueryRelaxation::worthProbing(['benzogenerator', 'tehnotek']))->toBeTrue()
        ->and(QueryRelaxation::worthProbing(array_fill(0, QueryRelaxation::MAX_WORDS, 'slovo')))->toBeTrue()
        ->and(QueryRelaxation::worthProbing(array_fill(0, QueryRelaxation::MAX_WORDS + 1, 'slovo')))->toBeFalse();
});

it('находит слова без попаданий', function (): void {
    expect(QueryRelaxation::unmatched([0, 78]))->toBe([0])
        ->and(QueryRelaxation::unmatched([0, 26, 26, 2]))->toBe([0])
        ->and(QueryRelaxation::unmatched([5, 7]))->toBe([]);
});

it('выбрасывает незнакомое первое слово', function (): void {
    // Главная дыра: `last` выбрасывает слова только с конца.
    expect(QueryRelaxation::retryWords(['benzogenerator', 'tehnotek'], [0]))->toBe(['tehnotek'])
        ->and(QueryRelaxation::retryWords(['invertornye', 'generatory'], [0]))->toBe(['generatory'])
        ->and(QueryRelaxation::retryWords(['elektropitbajk', 'white', 'siberia', 'belluga'], [0]))
        ->toBe(['white', 'siberia', 'belluga']);
});

it('не повторяет, если осталось меньше половины слов', function (): void {
    // Без этого условия «баллон гбо 35л» отдавал гидронасос на 0,35 л,
    // а «натяжитель полотна для лобзикового станка» — 320 станков «для».
    expect(QueryRelaxation::retryWords(['ballon', 'gbo', '35l'], [0, 1]))->toBeNull()
        ->and(QueryRelaxation::retryWords(['kugoo', 'wish', '02', 'pro', 'elektropitbajk'], [0, 1, 4]))->toBeNull()
        ->and(QueryRelaxation::retryWords(['natazitel\'', 'polotna', 'dla', 'lobzikovogo', 'stanka'], [0, 1, 3]))->toBeNull();
});

it('не повторяет по обрывку без единого настоящего слова', function (): void {
    // «u2» само по себе находит чужой скутер, «02 pro» — алмазное бурение.
    expect(QueryRelaxation::retryWords(['aimiko', 'u2'], [0]))->toBeNull()
        ->and(QueryRelaxation::retryWords(['sity', 'dla', 'gt'], [0]))->toBeNull();
});

it('нечего выбрасывать или нечего оставить — повтора нет', function (): void {
    expect(QueryRelaxation::retryWords(['tricikl', 'dvuhmestnyj'], [0, 1]))->toBeNull()
        ->and(QueryRelaxation::retryWords(['dizel\'nyj', 'generator'], []))->toBeNull();
});
