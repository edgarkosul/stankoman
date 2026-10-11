<?php

use Siteko\Chatbot\Kb\Data\KbDocument;
use Siteko\Chatbot\Kb\KbCalibrationQuestions;
use Siteko\Chatbot\Kb\KbVectorIndexer;

/*
 * Замер порога базы знаний — команда пакета (`chatbot:kb-calibrate`) на наших
 * размеченных вопросах. Список правится после каждой новой статьи: вопрос
 * «по теме, ответа нет» переезжает в «ответ есть», иначе замер меряет базу,
 * которой уже нет.
 */

$questions = fn (): string => resource_path('bot/kb-calibration-questions.json');

it('читает наш список размеченных вопросов', function () use ($questions): void {
    $groups = KbCalibrationQuestions::fromFile($questions());

    expect($groups[KbCalibrationQuestions::ANSWERED])->not->toBeEmpty()
        ->and($groups[KbCalibrationQuestions::UNANSWERED])->not->toBeEmpty()
        ->and($groups[KbCalibrationQuestions::OFF_TOPIC])->toContain('Какая погода в Москве?');
});

it('проходит все размеченные вопросы и советует порог', function () use ($questions): void {
    // На заглушке оценки случайны — проверяется тракт команды, а не сам порог:
    // его меряют на деве настоящим эмбеддером.
    app(KbVectorIndexer::class)->indexDocument('intertooler-page', new KbDocument(
        key: 'dostavka-i-oplata',
        title: 'Доставка и оплата',
        breadcrumb: ['InterTooler.ru', 'Доставка и оплата'],
        text: "## Доставка\n\nВезём по всей России.\n\n## Оплата\n\nПо счёту.",
    ));

    $this->artisan('chatbot:kb-calibrate', ['--questions' => $questions()])
        ->expectsOutputToContain('Какая погода в Москве?')
        ->assertSuccessful();
});
