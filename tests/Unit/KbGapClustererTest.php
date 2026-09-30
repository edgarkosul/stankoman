<?php

use App\Services\Kb\Data\KbGapQuestion;
use App\Services\Kb\KbGapClusterer;
use Illuminate\Support\Carbon;

// Кластеризация — чистая функция над векторами: ни базы, ни шлюза.
// Ради этого сбор сигналов и группировка разведены по разным классам.

/**
 * Единичный вектор под заданным углом. Двух измерений хватает: косинус
 * между нормированными векторами — это косинус угла между ними, и на
 * плоскости он ровно тот же, что в тысяче измерений.
 *
 * @return list<float>
 */
function gapUnitVector(float $degrees): array
{
    $radians = deg2rad($degrees);

    return [cos($radians), sin($radians)];
}

/**
 * @param  list<float>  $vector
 * @param  list<string>  $signals
 */
function gapQuestionFixture(
    string $text,
    array $vector,
    array $signals = [KbGapQuestion::SIGNAL_KB_MISS],
    int $id = 1,
    string $askedAt = '2026-09-10 10:00:00',
    ?string $answer = null,
): KbGapQuestion {
    return new KbGapQuestion(
        messageId: $id,
        conversationId: 100 + $id,
        text: $text,
        searchQuery: null,
        askedAt: Carbon::parse($askedAt),
        signals: $signals,
        vector: $vector,
        questionMessageId: 1000 + $id,
        answer: $answer,
    );
}

it('складывает разные формулировки одного вопроса в одну группу', function (): void {
    $clusters = (new KbGapClusterer(0.72))->cluster([
        gapQuestionFixture('можно ли вернуть товар', gapUnitVector(-20), id: 1),
        gapQuestionFixture('как оформить возврат', gapUnitVector(0), id: 2),
        gapQuestionFixture('вернёте деньги за станок', gapUnitVector(20), id: 3),
    ]);

    expect($clusters)->toHaveCount(1)
        ->and($clusters[0]->count())->toBe(3)
        ->and($clusters[0]->worthArticle())->toBeTrue();
});

it('не склеивает вопросы о разном', function (): void {
    // Ошибка порога в эту сторону хуже противоположной: рассыпавшуюся
    // группу увидят и сложат сами, а склеенная притворяется одной задачей.
    $clusters = (new KbGapClusterer(0.72))->cluster([
        gapQuestionFixture('как оформить возврат', gapUnitVector(0), id: 1),
        gapQuestionFixture('сколько стоит доставка в Крым', gapUnitVector(90), id: 2),
    ]);

    expect($clusters)->toHaveCount(2);
});

it('поднимает наверх группу, о которой спрашивали чаще', function (): void {
    $clusters = (new KbGapClusterer(0.72))->cluster([
        gapQuestionFixture('гарантия на станок', gapUnitVector(90), id: 1),
        gapQuestionFixture('как оформить возврат', gapUnitVector(0), id: 2),
        gapQuestionFixture('можно ли вернуть товар', gapUnitVector(10), id: 3),
    ]);

    expect($clusters[0]->count())->toBe(2)
        ->and($clusters[1]->count())->toBe(1);
});

it('при равном размере групп ставит выше ту, о которой спрашивали недавно', function (): void {
    $clusters = (new KbGapClusterer(0.72))->cluster([
        gapQuestionFixture('старый вопрос', gapUnitVector(0), id: 1, askedAt: '2026-08-01 10:00:00'),
        gapQuestionFixture('свежий вопрос', gapUnitVector(90), id: 2, askedAt: '2026-09-10 10:00:00'),
    ]);

    expect($clusters[0]->title())->toBe('свежий вопрос');
});

it('делает заголовком формулировку, ближайшую к центру группы', function (): void {
    // Не первую по времени (случайная фраза) и не самую частую
    // (одинаковых формулировок у живых людей не бывает).
    $clusters = (new KbGapClusterer(0.72))->cluster([
        gapQuestionFixture('с краю слева', gapUnitVector(-20), id: 1),
        gapQuestionFixture('ровно посередине', gapUnitVector(0), id: 2),
        gapQuestionFixture('с краю справа', gapUnitVector(20), id: 3),
    ]);

    expect($clusters[0]->title())->toBe('ровно посередине')
        ->and($clusters[0]->questionMessageIds()[0])->toBe(1002);
});

it('не берёт в группировку вопросы без вектора', function (): void {
    // Вектор не посчитался — шлюз эмбеддингов лежал. Такой вопрос уходит
    // в отдельный список, а не растворяется в чужой группе.
    $clusters = (new KbGapClusterer(0.72))->cluster([
        gapQuestionFixture('как оформить возврат', gapUnitVector(0), id: 1),
        gapQuestionFixture('бот ответил мимо', [], id: 2),
    ]);

    expect($clusters)->toHaveCount(1)
        ->and($clusters[0]->count())->toBe(1);
});

it('не сравнивает векторы разной размерности', function (): void {
    // Смена модели или размерности эмбеддингов — повод переиндексировать,
    // а не повод молча считать скалярное произведение обрезанных векторов.
    $clusters = (new KbGapClusterer(0.72))->cluster([
        gapQuestionFixture('вопрос на старой модели', [1.0, 0.0], id: 1),
        gapQuestionFixture('вопрос на новой модели', [1.0, 0.0, 0.0], id: 2),
    ]);

    expect($clusters)->toHaveCount(2);
});

it('считает сигналы по группе, а не по вопросам', function (): void {
    // Один вопрос даёт два сигнала сразу: бот не нашёл ответа, позвал
    // человека — и получил 👎. Складывать их надо по каждому отдельно.
    $clusters = (new KbGapClusterer(0.72))->cluster([
        gapQuestionFixture('как оформить возврат', gapUnitVector(0), [
            KbGapQuestion::SIGNAL_KB_MISS,
            KbGapQuestion::SIGNAL_ESCALATED,
        ], id: 1),
        gapQuestionFixture('можно ли вернуть товар', gapUnitVector(10), [
            KbGapQuestion::SIGNAL_KB_MISS,
        ], id: 2),
    ]);

    expect($clusters[0]->signalCounts())->toBe([
        KbGapQuestion::SIGNAL_KB_MISS => 2,
        KbGapQuestion::SIGNAL_ESCALATED => 1,
    ]);
});

it('отдаёт ответы менеджеров группы как материал статьи', function (): void {
    $clusters = (new KbGapClusterer(0.72))->cluster([
        gapQuestionFixture('счёт на ООО выставите?', gapUnitVector(0), [KbGapQuestion::SIGNAL_OPERATOR_ANSWER], id: 1,
            answer: 'Да, выставим счёт в течение часа, пришлите реквизиты на почту.'),
        gapQuestionFixture('оплата по безналу есть?', gapUnitVector(8), id: 2),
    ]);

    expect($clusters[0]->answers())->toBe(['Да, выставим счёт в течение часа, пришлите реквизиты на почту.']);
});

it('собирает диалоги группы без повторов', function (): void {
    $clusters = (new KbGapClusterer(0.72))->cluster([
        gapQuestionFixture('первый раз', gapUnitVector(0), id: 1),
        gapQuestionFixture('второй раз в том же диалоге', gapUnitVector(5), id: 1),
    ]);

    expect($clusters[0]->conversationIds())->toBe([101]);
});

it('сокращает длинный вопрос для подписи группы', function (): void {
    $question = gapQuestionFixture(str_repeat('очень длинный вопрос ', 20), gapUnitVector(0));

    expect($question->preview(40))->toHaveLength(40)
        ->and($question->preview(40))->toEndWith('…');
});

it('склеивает переносы строк в вопросе', function (): void {
    $question = gapQuestionFixture("здравствуйте\n\n  а можно счёт  на ООО?", gapUnitVector(0));

    expect($question->preview())->toBe('здравствуйте а можно счёт на ООО?');
});
