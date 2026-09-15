<?php

use App\Services\Chat\ChatPollingCadence;

/*
 * Бюджет поллинга — часть виджета, которую можно проверить без базы и без
 * сети. Цена ошибки здесь не косметическая: лишний тик на каждой открытой
 * вкладке занимает воркер FPM на проде.
 */

it('опрашивает часто первые полминуты — туда попадает больше половины ответов', function (): void {
    expect(ChatPollingCadence::interval(awaitingReply: true, waitedSeconds: 0, panelOpen: true))->toBe('2s')
        ->and(ChatPollingCadence::interval(awaitingReply: true, waitedSeconds: 29, panelOpen: true))->toBe('2s');
});

it('замедляется на второй ступени и на третьей', function (): void {
    expect(ChatPollingCadence::interval(awaitingReply: true, waitedSeconds: 30, panelOpen: true))->toBe('5s')
        ->and(ChatPollingCadence::interval(awaitingReply: true, waitedSeconds: 89, panelOpen: true))->toBe('5s')
        ->and(ChatPollingCadence::interval(awaitingReply: true, waitedSeconds: 90, panelOpen: true))->toBe('10s')
        ->and(ChatPollingCadence::interval(awaitingReply: true, waitedSeconds: 209, panelOpen: true))->toBe('10s');
});

it('сдаётся позже таймаута джобы, чтобы её заглушка успела дойти', function (): void {
    expect(ChatPollingCadence::GIVE_UP_AFTER_SECONDS)->toBeGreaterThan(200)
        ->and(ChatPollingCadence::interval(awaitingReply: true, waitedSeconds: 210, panelOpen: true))->toBeNull()
        ->and(ChatPollingCadence::givenUp(210))->toBeTrue();
});

it('опрашивает даже простаивающий диалог, пока панель открыта', function (): void {
    // Пока панель не спрашивает сервер, до неё не доходит НИЧЕГО, что
    // происходит на другой стороне. Тик при этом почти ничего не стоит:
    // ChatPanel::poll() сверяет отпечаток и делает skipRender().
    expect(ChatPollingCadence::interval(awaitingReply: false, waitedSeconds: 0, panelOpen: true))
        ->toBe('10s');
});

it('опрашивает чаще всего разговор с живым человеком', function (): void {
    expect(ChatPollingCadence::interval(
        awaitingReply: false, waitedSeconds: 0, panelOpen: true, operatorLed: true, escalated: true,
    ))->toBe('4s')
        ->and(ChatPollingCadence::interval(
            awaitingReply: false, waitedSeconds: 0, panelOpen: true, escalated: true,
        ))->toBe('8s');
});

it('меняет подпись на двадцатой секунде, а не после первого же тика', function (): void {
    expect(ChatPollingCadence::showsThinkingHint(19))->toBeFalse()
        ->and(ChatPollingCadence::showsThinkingHint(20))->toBeTrue();
});

it('сторожит свёрнутый чат, пока бот вот-вот ответит, и бросает через пять минут', function (): void {
    expect(ChatPollingCadence::launcherWatches(escalated: false, operatorLed: false, secondsSinceLastMessage: 30))
        ->toBeTrue()
        ->and(ChatPollingCadence::launcherWatches(escalated: false, operatorLed: false, secondsSinceLastMessage: 600))
        ->toBeFalse();
});

it('ждёт человека дольше, чем бота, но не бесконечно', function (): void {
    expect(ChatPollingCadence::launcherWatches(escalated: true, operatorLed: false, secondsSinceLastMessage: 1800))
        ->toBeTrue()
        ->and(ChatPollingCadence::launcherWatches(escalated: false, operatorLed: true, secondsSinceLastMessage: 1800))
        ->toBeTrue()
        ->and(ChatPollingCadence::launcherWatches(escalated: true, operatorLed: false, secondsSinceLastMessage: 10800))
        ->toBeFalse();
});

it('не сторожит разговор, в котором вообще не было сообщений', function (): void {
    // last_message_at пуст — в сервисе это PHP_INT_MAX, и оно не должно
    // случайно попасть внутрь окна.
    expect(ChatPollingCadence::launcherWatches(escalated: true, operatorLed: true, secondsSinceLastMessage: PHP_INT_MAX))
        ->toBeFalse();
});

it('не опрашивает сервер из свёрнутой панели — это работа лаунчера', function (): void {
    expect(ChatPollingCadence::interval(awaitingReply: false, waitedSeconds: 0, panelOpen: false, escalated: true))
        ->toBeNull();
});
