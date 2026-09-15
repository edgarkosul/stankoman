<?php

use App\Services\Chat\ChatPreviewGate;
use Illuminate\Http\Request;

/*
 * Кому виден виджет в предпросмотре — решение, которое нельзя проверить
 * глазами: ошибка в одну сторону прячет виджет от заказчика, в другую —
 * показывает недоделанного бота покупателям.
 */

function chatPreviewGate(bool $previewOnly = true, string $key = 'secret-123', ?Closure $isStaff = null): ChatPreviewGate
{
    return new ChatPreviewGate(previewOnly: $previewOnly, key: $key, isStaff: $isStaff);
}

function chatPreviewVisitor(array $query = [], array $cookies = []): Request
{
    return Request::create('/', 'GET', $query, $cookies);
}

it('показывает виджет всем, когда предпросмотр выключен', function (): void {
    expect(chatPreviewGate(previewOnly: false)->allows(chatPreviewVisitor()))->toBeTrue();
});

it('прячет виджет от постороннего, пока идёт предпросмотр', function (): void {
    expect(chatPreviewGate()->allows(chatPreviewVisitor()))->toBeFalse();
});

it('пускает по ссылке с ключом и по куке, оставшейся от ссылки', function (): void {
    expect(chatPreviewGate()->allows(chatPreviewVisitor(query: ['bot' => 'secret-123'])))->toBeTrue()
        ->and(chatPreviewGate()->allows(chatPreviewVisitor(cookies: [ChatPreviewGate::COOKIE => 'secret-123'])))->toBeTrue();
});

it('не пускает по чужому ключу', function (): void {
    expect(chatPreviewGate()->allows(chatPreviewVisitor(query: ['bot' => 'secret-124'])))->toBeFalse()
        ->and(chatPreviewGate()->allows(chatPreviewVisitor(cookies: [ChatPreviewGate::COOKIE => 'мимо'])))->toBeFalse();
});

it('пускает сотрудника магазина без всякой ссылки', function (): void {
    expect(chatPreviewGate()->allows(chatPreviewVisitor(), isStaff: true))->toBeTrue();
});

it('при пустом ключе не пускает НИКОГО, кроме сотрудников', function (): void {
    // Забытая настройка не должна молча открывать виджет всему свету —
    // это ровно та ошибка, которую замечают по счёту за модель.
    expect(chatPreviewGate(key: '')->allows(chatPreviewVisitor()))->toBeFalse()
        ->and(chatPreviewGate(key: '')->allows(chatPreviewVisitor(query: ['bot' => ''])))->toBeFalse()
        ->and(chatPreviewGate(key: '')->allows(chatPreviewVisitor(), isStaff: true))->toBeTrue();
});

it('без предпросмотра не спрашивает, сотрудник ли посетитель', function (): void {
    // Вопрос о сотруднике — обращение к пользователю сессии на каждой
    // странице витрины; при открытом виджете оно ничего не решает.
    $asked = false;
    $gate = chatPreviewGate(previewOnly: false, isStaff: function () use (&$asked): bool {
        $asked = true;

        return false;
    });

    expect($gate->visibleNow())->toBeTrue()
        ->and($asked)->toBeFalse();
});
