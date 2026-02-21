<?php

use App\Support\CompareService;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    session()->forget(CompareService::SESSION_KEY);
});

it('keeps only latest ten compared products', function (): void {
    $service = app(CompareService::class);

    for ($id = 1; $id <= 12; $id++) {
        $service->add($id);
    }

    expect($service->ids())
        ->toHaveCount(10)
        ->toBe([3, 4, 5, 6, 7, 8, 9, 10, 11, 12]);
});

it('toggles compared product presence', function (): void {
    $service = app(CompareService::class);

    $added = $service->toggle(42);
    $removed = $service->toggle(42);

    expect($added)->toBeTrue()
        ->and($removed)->toBeFalse()
        ->and($service->contains(42))->toBeFalse()
        ->and($service->count())->toBe(0);
});
