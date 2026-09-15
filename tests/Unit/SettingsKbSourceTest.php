<?php

use App\Services\Kb\Data\KbDocument;
use App\Services\Kb\KbChunker;
use App\Shop\SettingsKbSource;
use Tests\TestCase;

uses(TestCase::class);

$requisites = function (): ?KbDocument {
    $documents = iterator_to_array((new SettingsKbSource('InterTooler.ru'))->documents(), false);

    return $documents[0] ?? null;
};

beforeEach(function (): void {
    config([
        'company' => [
            'legal_name' => 'Индивидуальный предприниматель Кодаченко Роман Александрович',
            'inn' => '231102927496',
            'kpp' => '',
            'ogrn' => '',
            'ogrnip' => '',
            'legal_addr' => '350020, г. Краснодар, ул. Рашпилевская, д. 170',
            'correspondence_addr' => '',
            'phone' => '+7 (900) 246-86-60',
            'public_email' => 'sales@intertooler.ru',
            'site_url' => 'https://intertooler.ru',
            'bank' => [
                'name' => 'Краснодарское отделение №8619 ПАО Сбербанк',
                'bik' => '040349602',
                'rs' => '40802810230000073752',
                'ks' => '30101810100000000602',
            ],
        ],
        'settings.product.stavka_nds' => 22,
    ]);
});

it('собирает реквизиты из настроек одним документом без ссылки', function () use ($requisites): void {
    $document = $requisites();

    expect($document)->not->toBeNull()
        ->and($document->key)->toBe(SettingsKbSource::DOCUMENT_KEY)
        ->and($document->url)->toBeNull()
        ->and($document->breadcrumb)->toBe(['InterTooler.ru', 'Реквизиты и данные организации'])
        ->and($document->text)->toContain(
            "## Организация\n\n"
            ."Наименование: Индивидуальный предприниматель Кодаченко Роман Александрович\n"
            ."ИНН: 231102927496\n"
            .'Юридический адрес: 350020, г. Краснодар, ул. Рашпилевская, д. 170'
        )
        ->and($document->text)->toContain(
            "## Банковские реквизиты\n\n"
            ."Банк: Краснодарское отделение №8619 ПАО Сбербанк\n"
            ."БИК: 040349602\n"
            ."Расчётный счёт: 40802810230000073752\n"
            .'Корреспондентский счёт: 30101810100000000602'
        )
        ->and($document->text)->toContain('Ставка НДС: 22%, цены на сайте указаны с НДС (в том числе)');
});

it('не пишет пустых полей', function () use ($requisites): void {
    // Строку «ОГРН:» без значения модель прочтёт как «ОГРН у магазина нет».
    expect($requisites()->text)
        ->not->toContain('ОГРН')
        ->not->toContain('КПП')
        ->not->toContain('корреспонденции');
});

it('режется чанкером по разделам, чтобы вопрос про БИК находил банковский фрагмент', function () use ($requisites): void {
    $document = $requisites();

    $chunks = (new KbChunker)->chunk('intertooler-settings:requisites', $document->title, $document->breadcrumb, $document->text);

    expect(array_column($chunks, 'section_path'))
        ->toBe([['Организация'], ['Банковские реквизиты'], ['Контакты'], ['НДС']])
        ->and($chunks[1]['text'])->toStartWith('InterTooler.ru > Реквизиты и данные организации > Банковские реквизиты');
});

it('не отдаёт документа, когда реквизитов нет', function () use ($requisites): void {
    config(['company' => [], 'settings.product.stavka_nds' => 0]);

    expect($requisites())->toBeNull();
});
