<?php

use App\Livewire\Support\ChatPanel;
use App\Models\Page;
use App\Services\Ai\AssistantConfig;
use Livewire\Livewire;

/*
 * Анимированный робот выключается в «Настройках бота». Проверяем не движок
 * анимации — он живёт в браузере, — а то, что разметка соглашается с настройкой
 * во ВСЕХ трёх местах: на кнопке, в подсказке у неё и в шапке панели. Разойтись
 * они не имеют права: робот на кнопке и статичный значок в шапке читаются как
 * поломка, а не как решение владельца.
 */

beforeEach(function (): void {
    // Предпросмотр выключен — иначе виджета на странице нет вовсе.
    config(['ai_support.chat.preview.enabled' => false]);
});

function mascotPage(): Page
{
    return Page::factory()->create(['slug' => 'dostavka-i-oplata', 'is_published' => true]);
}

it('по умолчанию ставит робота: настройки нет, а вид сайта не меняется', function (): void {
    expect(app(AssistantConfig::class)->mascotEnabled())->toBeTrue();

    $this->get(route('page.show', mascotPage()->slug))
        ->assertOk()
        ->assertSee('x-ref="bubbleMascot"', escape: false)
        // Кавычки в конфиге Alpine экранированы директивой @js — ищем их вид
        // в разметке, а не исходный JSON.
        ->assertSee('\u0022mascot\u0022:true', escape: false);
});

it('выключенный робот уступает место значку и не едет в браузер', function (): void {
    config(['settings.'.AssistantConfig::PREFIX.'mascot' => false]);

    $this->get(route('page.show', mascotPage()->slug))
        ->assertOk()
        // Ни одного места для робота в разметке — монтировать его некуда.
        ->assertDontSee('x-ref="bubbleMascot"', escape: false)
        // Флаг уезжает в Alpine: по нему ленивый import артворка не случается вовсе.
        ->assertSee('\u0022mascot\u0022:false', escape: false)
        // Кнопка не осталась пустой: облачко-плейсхолдер и так было первым кадром.
        ->assertSee('data-mascot-placeholder', escape: false);
});

it('шапка панели соглашается с кнопкой', function (): void {
    Livewire::test(ChatPanel::class)
        ->assertSee('chatAvatar(40)', escape: false);

    config(['settings.'.AssistantConfig::PREFIX.'mascot' => false]);

    Livewire::test(ChatPanel::class)
        ->assertDontSee('chatAvatar(40)', escape: false);
});
