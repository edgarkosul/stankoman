<?php

use App\Services\Kb\KbThresholdCalibrator;
use Tests\TestCase;

uses(TestCase::class);

it('считает оценку, равную порогу, найденной', function (): void {
    [$row] = (new KbThresholdCalibrator)->sweep([0.45, 0.50], [0.30, 0.45], 0.45, 0.45);

    expect($row)->toBe(['threshold' => 0.45, 'missed' => 0, 'leaked' => 1]);
});

it('ставит порог посередине зазора, когда группы разделяются', function (): void {
    // Без ошибок пороги от 0.37 до 0.45 — берём середину, а не край.
    $recommended = (new KbThresholdCalibrator)->recommend([0.45, 0.52, 0.60], [0.28, 0.31, 0.36]);

    expect($recommended)->toBe(['threshold' => 0.41, 'missed' => 0, 'leaked' => 0]);
});

it('при перекрытии берёт самый длинный участок с наименьшим числом ошибок', function (): void {
    // По одной ошибке на 0.31–0.34 (четыре порога) и на 0.37–0.50 (четырнадцать).
    // Короткий участок сломался бы от первого нового вопроса — берётся длинный.
    $recommended = (new KbThresholdCalibrator)->recommend([0.34, 0.50, 0.55], [0.30, 0.36]);

    expect($recommended)->toBe(['threshold' => 0.43, 'missed' => 1, 'leaked' => 0]);
});
