<?php

use App\Http\Controllers\Hooks\MaxHookController;
use App\Services\Messengers\MaxLinks;
use Tests\TestCase;

/*
 * Коды живут в кэше (в тестах он `array`), база не нужна.
 */
uses(TestCase::class);

it('выдаёт код, который MAX пропустит в payload', function (): void {
    expect((new MaxLinks)->issue())->toMatch('/^[A-Za-z0-9]{24}$/');
});

it('гасит код после первого использования', function (): void {
    $links = new MaxLinks;
    $code = $links->issue();

    expect($links->consume($code))->toBeTrue()
        ->and($links->consume($code))->toBeFalse();
});

it('не принимает чужие и кривые коды', function (string $code): void {
    expect((new MaxLinks)->consume($code))->toBeFalse();
})->with([
    'не выдавался' => [str_repeat('a', 24)],
    'со спецсимволами' => ['../../'.str_repeat('a', 18)],
    'пустой' => [''],
]);

it('пускает webhook только с верным секретом', function (string $secret, ?string $header, bool $expected): void {
    expect(MaxHookController::authorized($secret, $header))->toBe($expected);
})->with([
    'верный' => ['s3cret', 's3cret', true],
    'неверный' => ['s3cret', 'guess', false],
    'без заголовка' => ['s3cret', null, false],
    'секрет не задан' => ['', '', false],
]);
