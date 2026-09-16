<?php

use App\Support\Surveys\BotKnowledgeQuestionnaire as Questionnaire;

function bkqQuestion(string $id): array
{
    foreach (Questionnaire::questions() as $question) {
        if ($question['id'] === $id) {
            return $question;
        }
    }

    throw new RuntimeException("Нет вопроса {$id}");
}

test('схема согласована: id уникальны, условия показа ссылаются на выбор выше по экрану', function (): void {
    $sectionIds = array_column(Questionnaire::sections(), 'id');
    $questionIds = [];
    $kinds = ['invented', 'handoff', 'partial', 'ok', 'site'];

    foreach (Questionnaire::questions() as $question) {
        expect($questionIds)->not->toContain($question['id'])
            ->and($sectionIds)->toContain($question['section'])
            ->and($kinds)->toContain($question['now']['kind']);

        $questionIds[] = $question['id'];
        $seen = [];

        foreach ($question['fields'] as $field) {
            expect(array_keys($seen))->not->toContain($field['id'])
                ->and($field['id'])->not->toBe(Questionnaire::NOTE);

            if (isset($field['showIf'])) {
                $parent = $seen[$field['showIf']['field']] ?? null;

                expect($parent)->not->toBeNull("{$question['id']}.{$field['id']} зависит от поля ниже себя")
                    ->and($parent['type'])->toBe('choice');

                foreach ($field['showIf']['in'] as $key) {
                    expect(array_column($parent['options'], 'key'))->toContain($key);
                }
            }

            if (in_array($field['type'], ['choice', 'multi'], true)) {
                $keys = array_column($field['options'], 'key');

                expect(count($keys))->toBeGreaterThanOrEqual(2)
                    ->and(array_unique($keys))->toHaveCount(count($keys));
            }

            $seen[$field['id']] = $field;
        }
    }

    // Каждый раздел в анкете представлен: пустая плитка раздела на странице ломала бы переход.
    expect(array_unique(array_column(Questionnaire::questions(), 'section')))->toHaveCount(count($sectionIds));
});

test('чистка ответов оставляет только то, что анкета умеет спрашивать', function (): void {
    $clean = Questionnaire::sanitize([
        'returns' => [
            'accept' => 'no',
            'terms' => '7 дней',                // скрыто при «не принимаем»
            'b2b' => 'чужой вариант',
            'defect' => "  Написать на почту\x07 с фото  ",
            'lishnee' => 'поле, которого нет',
            'note' => '',
        ],
        'carriers' => [
            'list' => ['pek', 'dellin', 'vzlom', 42],
            'list_other' => 'Луч',
        ],
        'nesuschestvuyuschiy' => ['x' => 'y'],
        'deferral' => 'не массив',
    ]);

    expect($clean)->toBe([
        'returns' => [
            'accept' => 'no',
            'defect' => 'Написать на почту с фото',
        ],
        'carriers' => [
            'list' => ['dellin', 'pek'],
            'list_other' => 'Луч',
        ],
    ]);
});

test('текст режется по потолку, переносы строк сохраняются', function (): void {
    $clean = Questionnaire::sanitize([
        'onsite' => ['details' => "первая строка\r\nвторая ".str_repeat('я', Questionnaire::TEXT_LIMIT)],
    ]);

    expect(mb_strlen($clean['onsite']['details']))->toBe(Questionnaire::TEXT_LIMIT)
        ->and($clean['onsite']['details'])->toStartWith("первая строка\nвторая ");
});

test('отвечен ли вопрос, решают поля, а не один комментарий', function (): void {
    $question = bkqQuestion('deferral');

    expect(Questionnaire::isAnswered($question, ['note' => 'подумаю']))->toBeFalse()
        ->and(Questionnaire::isAnswered($question, ['rule' => 'no']))->toBeTrue()
        ->and(Questionnaire::answeredCount(['deferral' => ['rule' => 'no'], 'prices' => ['note' => 'позже']]))->toBe(1);
});

test('ответ бота собирается из выбранных вариантов и вписанного текста', function (): void {
    $reply = Questionnaire::botReply(bkqQuestion('service_center'), [
        'where' => 'own',
        'address' => 'Краснодар, ул. Северная, 1.',
        'contact' => 'sales@intertooler.ru',
        'docs' => ['receipt', 'talon'],
    ]);

    expect($reply)->toBe(
        'Гарантийный ремонт делает наш сервис в Краснодаре. '
        .'Адрес: Краснодар, ул. Северная, 1. '
        .'Обращаться: sales@intertooler.ru. '
        .'Понадобится: чек или счёт, гарантийный талон.'
    );

    // Скрытое поле в ответ не попадает, даже если значение осталось.
    expect(Questionnaire::botReply(bkqQuestion('service_center'), ['where' => 'maker', 'address' => 'старый адрес']))
        ->not->toContain('старый адрес');

    expect(Questionnaire::botReply(bkqQuestion('deferral'), []))->toBeNull();
});

test('выгрузка показывает выбор словами, текст, комментарий и черновик реплики бота', function (): void {
    $markdown = Questionnaire::toMarkdown([
        'deferral' => ['rule' => 'sometimes', 'terms' => 'постоянным клиентам до 14 дней', 'note' => 'редко'],
        'carriers' => ['list' => ['cdek'], 'list_other' => 'Луч'],
    ]);

    expect($markdown)
        ->toContain('## Цены и оплата')
        ->toContain('Даёте отсрочку платежа?')
        ->toContain('- Отсрочка платежа: Бывает — решаем индивидуально')
        ->toContain('- Кому и на каких условиях: постоянным клиентам до 14 дней')
        ->toContain('- Комментарий: редко')
        ->toContain('> Бот: Отсрочку платежа можно обсудить с менеджером')
        ->toContain('- Основные компании: СДЭК, Луч')
        ->toContain('_нет ответа_');
});
