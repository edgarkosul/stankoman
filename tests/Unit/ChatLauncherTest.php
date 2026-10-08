<?php

use App\Services\Chat\ChatLauncher;

/*
 * Кнопка чата: значения выбирают в админке, а становятся они классами
 * в разметке витрины. Поэтому всё незнакомое превращается в умолчание
 * здесь, а умолчание — ровно прежняя кнопка: робот без диска справа.
 */

it('без настроек — прежний робот справа, 16 px от краёв', function (): void {
    $launcher = ChatLauncher::from(null);

    expect($launcher->image)->toBe('robot')
        ->and($launcher->style)->toBe('circle')
        ->and([$launcher->side, $launcher->x, $launcher->y])->toBe(['right', 16, 16])
        ->and($launcher->floatingRobot())->toBeTrue()
        ->and($launcher->heightPx())->toBe(72)
        ->and($launcher->classes(picture: true))->toBe('chat-launcher cl-robot')
        ->and($launcher->isDefault())->toBeTrue();
});

it('принимает выбор админа', function (): void {
    $launcher = ChatLauncher::from([
        'style' => 'pill', 'image' => 'avatar', 'icon' => 'sparkles', 'status' => '  На  связи ',
        'effect' => 'shine', 'dot' => 'pulse', 'size' => 'l', 'side' => 'left', 'x' => '32', 'y' => 90,
    ]);

    expect($launcher->toArray())->toBe([
        'style' => 'pill', 'image' => 'avatar', 'icon' => 'sparkles', 'status' => 'На связи',
        'effect' => 'shine', 'dot' => 'pulse', 'size' => 'l', 'side' => 'left', 'x' => 32, 'y' => 90,
    ])
        ->and($launcher->classes(picture: true))
        ->toBe('chat-launcher cl-pill cl-picture cl-size-l cl-left cl-fx-shine cl-dot-pulse')
        ->and($launcher->heightPx())->toBe(64);
});

it('у парящего робота нет ни размера, ни эффекта: он движется сам', function (): void {
    $launcher = ChatLauncher::from(['image' => 'robot', 'size' => 's', 'effect' => 'rings', 'dot' => 'off']);

    expect($launcher->classes(picture: true))->toBe('chat-launcher cl-robot cl-no-dot')
        ->and($launcher->heightPx())->toBe(72);
});

it('робот в пилюле — картинка в кружке, с размером и эффектом', function (): void {
    $launcher = ChatLauncher::from(['image' => 'robot', 'style' => 'pill', 'size' => 's', 'effect' => 'nudge']);

    expect($launcher->floatingRobot())->toBeFalse()
        ->and($launcher->classes(picture: true))->toBe('chat-launcher cl-pill cl-picture cl-size-s cl-fx-nudge')
        ->and($launcher->heightPx())->toBe(48);
});

it('незнакомое слово становится умолчанием, а не классом', function (): void {
    $launcher = ChatLauncher::from([
        'style' => 'pill" onclick="x', 'image' => '../etc', 'icon' => '<svg>', 'effect' => 'fx-rings evil',
        'dot' => ['on'], 'size' => 'xl', 'side' => 'top', 'x' => 'abc', 'y' => null,
    ]);

    expect($launcher->isDefault())->toBeTrue()
        ->and($launcher->classes(picture: false))->toBe('chat-launcher cl-robot')
        ->and($launcher->iconPath())->toBe(ChatLauncher::ICON_PATHS['chat']);
});

it('зажимает отступы и обрезает строку под именем', function (): void {
    $launcher = ChatLauncher::from(['x' => -15, 'y' => 5000, 'status' => str_repeat('я', 40)]);

    expect($launcher->x)->toBe(0)
        ->and($launcher->y)->toBe(200)
        ->and(mb_strlen($launcher->status))->toBe(ChatLauncher::STATUS_LENGTH)
        ->and(ChatLauncher::from(['status' => '   '])->status)->toBe('Онлайн');
});

it('хранит только отличия от умолчания', function (): void {
    expect(ChatLauncher::from(['style' => 'pill', 'effect' => 'none', 'x' => 16, 'y' => 16, 'status' => 'Онлайн'])->toArray())
        ->toBe(['style' => 'pill'])
        ->and(ChatLauncher::from(['image' => 'robot', 'style' => 'circle'])->toArray())->toBe([]);
});

it('перетаскивание включает только настоящее «да»', function (): void {
    expect(ChatLauncher::from(['drag' => true])->drag)->toBeTrue()
        ->and(ChatLauncher::from(['drag' => true])->toArray())->toBe(['drag' => true])
        ->and(ChatLauncher::from(['drag' => '1'])->drag)->toBeFalse()
        ->and(ChatLauncher::from(['drag' => [true]])->drag)->toBeFalse()
        ->and(ChatLauncher::from(['drag' => false])->toArray())->toBe([]);
});

it('у каждого значка из списка есть контур', function (): void {
    expect(array_keys(ChatLauncher::ICON_PATHS))->toBe(array_keys(ChatLauncher::ICONS));
});
