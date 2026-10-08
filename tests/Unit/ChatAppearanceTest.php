<?php

use App\Services\Chat\ChatAppearance;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/*
 * Вид чата читается из конфига, куда его раскладывает SettingsServiceProvider,
 * — значит проверяется без базы: лаунчер спрашивает его на каждой странице.
 */
uses(TestCase::class);

beforeEach(function (): void {
    config()->set('settings.assistant', []);
    Storage::fake('public');
    Storage::disk('public')->put('chat-avatars/face.webp', 'x');
    Storage::disk('public')->put('chat-avatars/button.png', 'x');
});

function appearance(array $settings): ChatAppearance
{
    foreach ($settings as $key => $value) {
        config()->set('settings.assistant.'.$key, $value);
    }

    return new ChatAppearance;
}

it('без настроек — робот на кнопке, в подсказке и в шапке', function (): void {
    $appearance = appearance([]);

    expect($appearance->launcher()->image)->toBe('robot')
        ->and($appearance->pictureUrl())->toBeNull()
        ->and($appearance->face())->toBe(ChatAppearance::FACE_ROBOT);
});

it('старый выключатель робота даёт значок, пока кнопку не сохраняли', function (): void {
    // «Лицо бота» выключили до «Вида чата» — выкладка не должна вернуть робота.
    expect(appearance(['mascot' => false])->launcher()->image)->toBe('icon')
        ->and(appearance(['mascot' => false])->face())->toBe(ChatAppearance::FACE_ICON);

    // Включённый — то же умолчание, что и без строки.
    config()->set('settings.assistant', []);
    expect(appearance(['mascot' => true])->launcher()->image)->toBe('robot');

    // Кнопку сохранили в «Виде чата» — дальше решает она.
    config()->set('settings.assistant', []);
    expect(appearance(['mascot' => false, 'launcher' => ['image' => 'avatar']])->launcher()->image)->toBe('avatar');
});

it('робот на кнопке — робот и в лице, даже если аватар загружен', function (): void {
    expect(appearance(['avatar' => 'chat-avatars/face.webp'])->face())->toBe(ChatAppearance::FACE_ROBOT);
});

it('без робота лицо — аватар, а без аватара — значок', function (): void {
    expect(appearance(['launcher' => ['image' => 'icon'], 'avatar' => 'chat-avatars/face.webp'])->face())
        ->toBe(ChatAppearance::FACE_AVATAR);

    config()->set('settings.assistant.avatar', null);

    expect((new ChatAppearance)->face())->toBe(ChatAppearance::FACE_ICON);
});

it('картинка на кнопке — аватар, своя или никакой', function (): void {
    $appearance = appearance(['avatar' => 'chat-avatars/face.webp', 'launcher_picture' => 'chat-avatars/button.png']);

    // Значок — картинки нет, даже если она загружена.
    config()->set('settings.assistant.launcher', ['image' => 'icon']);
    expect($appearance->pictureUrl())->toBeNull();

    config()->set('settings.assistant.launcher', ['image' => 'avatar']);
    expect($appearance->pictureUrl())->toEndWith('/chat-avatars/face.webp');

    config()->set('settings.assistant.launcher', ['image' => 'custom']);
    expect($appearance->pictureUrl())->toEndWith('/chat-avatars/button.png');

    // Файл пропал с диска — значок, а не пустой кружок.
    Storage::disk('public')->delete('chat-avatars/button.png');
    expect($appearance->pictureUrl())->toBeNull();
});

it('путь картинки — только из своей папки', function (): void {
    // Путь из настроек уходит в delete() при уборке: чужой файл так не снести.
    foreach (['../.env', 'chat-avatars/../../.env', 'pics/logo.png', 'chat-avatars/a.svg', ['chat-avatars/face.webp']] as $path) {
        expect(appearance(['avatar' => $path])->avatarPath())->toBeNull();
    }

    expect(appearance(['avatar' => 'chat-avatars/face.webp'])->avatarPath())->toBe('chat-avatars/face.webp');
});

it('уборка сносит сменённую картинку и не трогает текущую', function (): void {
    $appearance = appearance(['avatar' => 'chat-avatars/face.webp']);
    Storage::disk('public')->put('chat-avatars/old.webp', 'x');

    $appearance->sweep(['chat-avatars/old.webp', null]);

    expect(Storage::disk('public')->exists('chat-avatars/old.webp'))->toBeFalse()
        ->and(Storage::disk('public')->exists('chat-avatars/face.webp'))->toBeTrue()
        // Ничья, но свежая: в соседней вкладке её, может быть, как раз сохраняют.
        ->and(Storage::disk('public')->exists('chat-avatars/button.png'))->toBeTrue();
});
