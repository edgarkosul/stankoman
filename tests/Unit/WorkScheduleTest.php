<?php

use App\Support\WorkSchedule;
use Tests\TestCase;

uses(TestCase::class);

$weekdays = fn (array $hours, ?array $saturday = null, ?array $sunday = null): array => [
    'days' => [1 => $hours, 2 => $hours, 3 => $hours, 4 => $hours, 5 => $hours, 6 => $saturday, 7 => $sunday],
];

it('склеивает соседние дни с одинаковыми часами', function () use ($weekdays): void {
    $schedule = WorkSchedule::fromArray($weekdays(['09:00', '18:00']));

    // Так режим работы всегда писался на сайте — без ведущего нуля у часа.
    expect($schedule->lines())->toBe(['Пн – Пт: 9:00 – 18:00', 'Сб – Вс: выходной'])
        ->and($schedule->summary())->toBe('Пн – Пт: 9:00 – 18:00, Сб – Вс: выходной');
});

it('не склеивает одинаковые, но не соседние дни', function (): void {
    $schedule = WorkSchedule::fromArray(['days' => [
        1 => ['10:00', '19:00'],
        2 => null,
        3 => ['10:00', '19:00'],
        4 => ['10:00', '19:00'],
        5 => ['10:00', '19:00'],
        6 => ['10:00', '15:00'],
        7 => null,
    ]]);

    expect($schedule->lines())->toBe([
        'Пн: 10:00 – 19:00',
        'Вт: выходной',
        'Ср – Пт: 10:00 – 19:00',
        'Сб: 10:00 – 15:00',
        'Вс: выходной',
    ]);
});

it('приводит время из поля админки к «ЧЧ:ММ»', function () use ($weekdays): void {
    $schedule = WorkSchedule::fromArray($weekdays(['9:00:00', '18:30:00']));

    expect($schedule->days[1])->toBe(['09:00', '18:30'])
        ->and($schedule->toArray()['days'][5])->toBe(['09:00', '18:30']);
});

it('считает выходным всё, что не разобралось, а не падает', function (): void {
    $schedule = WorkSchedule::fromArray(['days' => [
        '1' => ['09:00', '18:00'],
        2 => ['25:00', '18:00'],
        3 => 'круглосуточно',
        4 => ['09:00'],
    ], 'note' => ['не строка']]);

    expect($schedule->days[1])->toBe(['09:00', '18:00'])
        ->and($schedule->days[2])->toBeNull()
        ->and($schedule->days[3])->toBeNull()
        ->and($schedule->days[4])->toBeNull()
        ->and($schedule->note)->toBe('')
        ->and(WorkSchedule::fromArray(null)->hasOpenDays())->toBeFalse();
});

it('без настройки в базе показывает то, что сайт обещал всегда', function (): void {
    expect(WorkSchedule::fromConfig()->lines())->toBe(['Пн – Пт: 9:00 – 18:00', 'Сб – Вс: выходной']);
});

it('берёт настройку из базы, а не из файла конфига', function () use ($weekdays): void {
    config(['company.work_schedule' => [...$weekdays(['08:00', '17:00'], ['10:00', '14:00']), 'note' => '  Отгрузка по звонку  ']]);

    $schedule = WorkSchedule::fromConfig();

    expect($schedule->lines())->toBe(['Пн – Пт: 8:00 – 17:00', 'Сб: 10:00 – 14:00', 'Вс: выходной'])
        ->and($schedule->note)->toBe('Отгрузка по звонку');
});
