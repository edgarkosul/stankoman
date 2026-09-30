<?php

use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\SellerRequisitesBlock;
use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\WorkScheduleBlock;
use App\Models\Page;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();

    config(['company.work_schedule' => [
        'days' => [
            1 => ['10:00', '19:00'],
            2 => ['10:00', '19:00'],
            3 => ['10:00', '19:00'],
            4 => ['10:00', '19:00'],
            5 => ['10:00', '19:00'],
            6 => ['10:00', '15:00'],
            7 => null,
        ],
        'note' => 'В воскресенье — отгрузка по договорённости.',
    ]]);
});

it('шапка и подвал показывают режим работы из настроек, а не зашитую строку', function (): void {
    $page = Page::factory()->create(['is_published' => true, 'content' => '<p>Текст</p>']);

    $this->get(route('page.show', ['page' => $page->slug]))
        ->assertSuccessful()
        ->assertSee('Пн – Пт: 10:00 – 19:00')
        ->assertSee('Сб: 10:00 – 15:00')
        ->assertSee('Вс: выходной')
        ->assertSee('В воскресенье — отгрузка по договорённости.')
        ->assertDontSee('9:00 - 18:00');
});

it('блок «Режим работы» на странице рисует текущую настройку', function (): void {
    $page = Page::factory()->create([
        'is_published' => true,
        'content' => '<p>О нас</p><div data-type="customBlock" data-config="null" data-id="work-schedule"></div>',
    ]);

    $this->get(route('page.show', ['page' => $page->slug]))
        ->assertSuccessful()
        ->assertSee('Режим работы:')
        ->assertSeeInOrder(['О нас', 'Пн – Пт: 10:00 – 19:00', 'Сб: 10:00 – 15:00']);

    expect(WorkScheduleBlock::toHtml([], []))->toContain('<li>Вс: выходной</li>');
});

it('реквизиты продавца показывают банк только по выбору', function (): void {
    config([
        'company.legal_name' => 'Индивидуальный предприниматель Кодаченко Роман Александрович',
        'company.bank' => [
            'name' => 'Краснодарское отделение №8619 ПАО Сбербанк',
            'bik' => '040349602',
            'rs' => '40802810230000073752',
            'ks' => '30101810100000000602',
        ],
    ]);

    // Уже вставленные блоки (оферта, политика) конфига не имеют и остаются как были.
    expect(SellerRequisitesBlock::toHtml([], []))
        ->toContain('Индивидуальный предприниматель Кодаченко Роман Александрович')
        ->not->toContain('Банковские реквизиты')
        ->not->toContain('40802810230000073752');

    expect(SellerRequisitesBlock::toHtml(['show_bank' => true], []))
        ->toContain('Банковские реквизиты')
        ->toContain('БИК: 040349602')
        ->toContain('Расчётный счёт: 40802810230000073752')
        ->toContain('Корреспондентский счёт: 30101810100000000602');
});

it('страница с блоком реквизитов и банком рендерится целиком', function (): void {
    config(['company.bank.rs' => '40802810230000073752']);

    $page = Page::factory()->create([
        'is_published' => true,
        'content' => '<div data-type="customBlock" data-config="{&quot;show_bank&quot;:true}" data-id="seller-requisites"></div>',
    ]);

    $this->get(route('page.show', ['page' => $page->slug]))
        ->assertSuccessful()
        ->assertSee('Расчётный счёт: 40802810230000073752');
});
