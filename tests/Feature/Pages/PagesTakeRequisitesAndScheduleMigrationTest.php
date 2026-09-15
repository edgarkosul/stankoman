<?php

use App\Models\Page;
use Illuminate\Support\Facades\Queue;

/*
 * Миграция меняет набранные руками реквизиты ООО на «Контактах» и график на
 * «О компании» блоками из настроек. Главное здесь — что чужую правку она
 * не трогает: страницы редактируют в админке, и на бою текст мог уйти вперёд.
 */

$migration = function (): object {
    static $instance;

    return $instance ??= require database_path('migrations/2026_09_15_110100_pages_take_requisites_and_schedule_from_settings.php');
};

$replacements = fn (): array => (new ReflectionClassConstant($migration(), 'REPLACEMENTS'))->getValue();

beforeEach(function (): void {
    // «Контакты» и «О компании» в белом списке базы знаний — наблюдатель ставит задачу в Redis.
    Queue::fake();
});

it('заменяет реквизиты ООО и график блоками из настроек, не трогая остальной текст', function () use ($migration, $replacements): void {
    [$contactsBefore] = $replacements()['kontakty'];
    [$aboutBefore] = $replacements()['o-kompanii'];

    $contacts = Page::factory()->create(['slug' => 'kontakty', 'content' => '<p>Наш адрес: Краснодар</p>'.$contactsBefore]);
    $about = Page::factory()->create(['slug' => 'o-kompanii', 'content' => '<p>С 2014 года.</p>'.$aboutBefore]);

    $migration()->up();

    expect($contacts->fresh()->content)
        ->toBe('<p>Наш адрес: Краснодар</p><div data-type="customBlock" data-config="{&quot;show_bank&quot;:true}" data-id="seller-requisites"></div>')
        ->not->toContain('2311386255');

    expect($about->fresh()->content)
        ->toBe('<p>С 2014 года.</p><div data-type="customBlock" data-config="null" data-id="work-schedule"></div>');
});

it('находит фрагмент, когда в номере счёта неразрывные пробелы', function () use ($migration, $replacements): void {
    [$before] = $replacements()['kontakty'];

    // Так лежит на бою: редактор поставил неразрывные пробелы в группы цифр,
    // и байт в байт фрагмент со страницей не совпадает.
    $onProduction = str_replace('40702 810 7 3074 0005761', "40702\u{00A0}810\u{00A0}7\u{00A0}3074\u{00A0}0005761", $before);
    $page = Page::factory()->create(['slug' => 'kontakty', 'content' => $onProduction]);

    $migration()->up();

    expect($page->fresh()->content)->toContain('data-id="seller-requisites"')
        ->not->toContain('0005761');
});

it('откатывается к исходному тексту', function () use ($migration, $replacements): void {
    [$before] = $replacements()['kontakty'];
    $original = '<p>Наш адрес: Краснодар</p>'.$before;
    $page = Page::factory()->create(['slug' => 'kontakty', 'content' => $original]);

    $migration()->up();
    $migration()->down();

    expect($page->fresh()->content)->toBe($original);
});

it('не трогает страницу, текст которой уже правили', function () use ($migration, $replacements): void {
    [$before] = $replacements()['kontakty'];
    $edited = str_replace('КПП 231101001', 'КПП 231101002', $before);
    $page = Page::factory()->create(['slug' => 'kontakty', 'content' => $edited]);

    $migration()->up();

    expect($page->fresh()->content)->toBe($edited);
});

it('повторный прогон ничего не ломает', function () use ($migration, $replacements): void {
    [$before] = $replacements()['o-kompanii'];
    $page = Page::factory()->create(['slug' => 'o-kompanii', 'content' => $before]);

    $migration()->up();
    $once = $page->fresh()->content;
    $migration()->up();

    expect($page->fresh()->content)->toBe($once);
});
