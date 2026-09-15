<?php

use App\Services\Chat\OperatorPresence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/*
 * Присутствие проверяется без базы: всё его состояние живёт в кэше
 * (в тестах он `array`) и в конструкторе. Приложение поднимается только
 * ради фасада Cache.
 */
uses(TestCase::class);

function chatPresence(): OperatorPresence
{
    return new OperatorPresence(
        schedule: [
            1 => ['09:00', '18:00'],
            2 => ['09:00', '18:00'],
            3 => ['09:00', '18:00'],
            4 => ['09:00', '18:00'],
            5 => ['09:00', '18:00'],
            6 => null,
            7 => null,
        ],
        timezone: 'Europe/Moscow',
        activityWindowMinutes: 10,
        overrideTtlMinutes: 480,
    );
}

/** Понедельник, полдень — рабочее время по расписанию. */
function chatAtWorkTime(): void
{
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-31 12:00', 'Europe/Moscow'));
}

/** Суббота, ночь — по расписанию не работает никто. */
function chatAtNight(): void
{
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-29 03:00', 'Europe/Moscow'));
}

afterEach(function (): void {
    Carbon::setTestNow();
    Cache::flush();
});

it('в рабочее время считает менеджера на связи, ночью — нет', function (): void {
    chatAtWorkTime();
    expect(chatPresence()->isOnline())->toBeTrue()
        ->and(chatPresence()->source())->toBe(OperatorPresence::SOURCE_SCHEDULE);

    chatAtNight();
    expect(chatPresence()->isOnline())->toBeFalse();
});

it('ручной переключатель перебивает расписание в обе стороны и истекает сам', function (): void {
    chatAtNight();
    chatPresence()->setOverride(true);

    expect(chatPresence()->isOnline())->toBeTrue()
        ->and(chatPresence()->source())->toBe(OperatorPresence::SOURCE_OVERRIDE);

    // Через девять часов (TTL — восемь) решение больше не действует.
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-29 12:00', 'Europe/Moscow'));

    expect(chatPresence()->override())->toBeNull()
        ->and(chatPresence()->isOnline())->toBeFalse();

    chatAtWorkTime();
    chatPresence()->setOverride(false);

    expect(chatPresence()->isOnline())->toBeFalse();
});

it('работа в админке в выходной означает, что кто-то на связи', function (): void {
    chatAtNight();
    chatPresence()->touchActivity();

    expect(chatPresence()->isOnline())->toBeTrue()
        ->and(chatPresence()->source())->toBe(OperatorPresence::SOURCE_ACTIVITY);

    Carbon::setTestNow(CarbonImmutable::parse('2026-08-29 03:11', 'Europe/Moscow'));

    expect(chatPresence()->isOnline())->toBeFalse();
});

it('находит начало ближайшей смены', function (): void {
    chatAtNight();
    expect(chatPresence()->nextShiftStart()->format('Y-m-d H:i'))->toBe('2026-08-31 09:00');

    chatAtWorkTime();
    expect(chatPresence()->nextShiftStart()->format('Y-m-d H:i'))->toBe('2026-08-31 12:00');
});

it('сворачивает расписание в человеческую строку', function (): void {
    expect(chatPresence()->scheduleSummary())->toBe('Пн–Пт 09:00–18:00')
        ->and((new OperatorPresence([], 'Europe/Moscow', 10, 480))->scheduleSummary())->toBe('по договорённости');
});
