<?php

use App\Livewire\Support\ChatPanel;
use App\Models\Page;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * Вид кнопки чата из «Вида чата». Проверяем не анимацию — она живёт
 * в браузере, — а то, что разметка соглашается с настройкой во ВСЕХ трёх
 * местах: на кнопке, в подсказке у неё и в шапке панели. Разойтись они
 * не имеют права: робот на кнопке и фото в шапке читаются как поломка.
 */

beforeEach(function (): void {
    // Предпросмотр выключен — иначе виджета на странице нет вовсе.
    config(['ai_support.chat.preview.enabled' => false]);
    Storage::fake('public');
});

function launcherPage(): Page
{
    return Page::factory()->create(['slug' => 'dostavka-i-oplata', 'is_published' => true]);
}

it('по умолчанию ставит робота: настройки нет, а вид сайта не меняется', function (): void {
    $this->get(route('page.show', launcherPage()->slug))
        ->assertOk()
        ->assertSee('class="chat-launcher cl-robot"', escape: false)
        ->assertSee('right: 16px; bottom: 16px', escape: false)
        ->assertSee('x-ref="bubbleMascot"', escape: false)
        // Кавычки в конфиге Alpine экранированы директивой @js.
        ->assertSee('\u0022robot\u0022:true', escape: false);
});

it('старый выключатель робота даёт значок и робот не едет в браузер', function (): void {
    config(['settings.assistant.mascot' => false]);

    $this->get(route('page.show', launcherPage()->slug))
        ->assertOk()
        ->assertDontSee('x-ref="bubbleMascot"', escape: false)
        ->assertDontSee('x-ref="mascot"', escape: false)
        ->assertSee('\u0022robot\u0022:false', escape: false)
        ->assertSee('class="cl-icon"', escape: false);
});

it('пилюля с аватаром слева: картинка на кнопке, имя и угол', function (): void {
    Storage::disk('public')->put('chat-avatars/face.webp', 'x');
    config([
        'settings.assistant.launcher' => ['style' => 'pill', 'image' => 'avatar', 'side' => 'left', 'effect' => 'rings'],
        'settings.assistant.avatar' => 'chat-avatars/face.webp',
    ]);

    $this->get(route('page.show', launcherPage()->slug))
        ->assertOk()
        ->assertSee('class="chat-launcher cl-pill cl-picture cl-left cl-fx-rings"', escape: false)
        ->assertSee('left: 16px; bottom: 16px', escape: false)
        ->assertSee('/chat-avatars/face.webp', escape: false)
        ->assertDontSee('x-ref="bubbleMascot"', escape: false);
});

it('шапка панели соглашается с кнопкой', function (): void {
    Livewire::test(ChatPanel::class)
        ->assertSee('chatAvatar(40)', escape: false);

    Storage::disk('public')->put('chat-avatars/face.webp', 'x');
    config([
        'settings.assistant.launcher' => ['image' => 'icon'],
        'settings.assistant.avatar' => 'chat-avatars/face.webp',
    ]);

    Livewire::test(ChatPanel::class)
        ->assertDontSee('chatAvatar(40)', escape: false)
        ->assertSee('/chat-avatars/face.webp', escape: false);
});
