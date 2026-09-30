<?php

use App\Services\Captcha\Verifiers\SmartCaptchaVerifier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class);

const SMARTCAPTCHA_VALIDATE_URL = 'https://smartcaptcha.cloud.yandex.ru/validate';

function smartCaptchaVerifier(): SmartCaptchaVerifier
{
    return new SmartCaptchaVerifier(
        secretKey: 'ysc2_secret',
        validateUrl: SMARTCAPTCHA_VALIDATE_URL,
        timeout: 4.0,
    );
}

it('пропускает человека', function (): void {
    Http::fake([SMARTCAPTCHA_VALIDATE_URL => Http::response(['status' => 'ok', 'message' => ''])]);

    expect(smartCaptchaVerifier()->verify('token', '10.0.0.1'))->toBeTrue();
});

it('не пропускает робота', function (): void {
    // Пустой message при status=failed — это и есть «робот».
    Http::fake([SMARTCAPTCHA_VALIDATE_URL => Http::response(['status' => 'failed', 'message' => ''])]);

    expect(smartCaptchaVerifier()->verify('token', '10.0.0.1'))->toBeFalse();
});

it('не пропускает и жалуется в лог, когда ошибка на нашей стороне', function (): void {
    // Непустой message значит, что мы прислали негодное: битый ключ,
    // протухший или уже потраченный токен. Без записи в лог неверный
    // серверный ключ выглядел бы как нашествие ботов.
    Http::fake([SMARTCAPTCHA_VALIDATE_URL => Http::response([
        'status' => 'failed',
        'message' => 'Authentication failed. Invalid secret.',
    ])]);

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $message): bool => str_contains($message, 'отклонила'));

    expect(smartCaptchaVerifier()->verify('token'))->toBeFalse();
});

it('пропускает, когда сервис отвечает ошибкой', function (): void {
    // Рекомендация Яндекса: не 200 считать успехом. Иначе чужая авария
    // выключает чат целиком, а над капчей стоят ещё два слоя защиты.
    Http::fake([SMARTCAPTCHA_VALIDATE_URL => Http::response('', 503)]);

    Log::shouldReceive('warning')->once();

    expect(smartCaptchaVerifier()->verify('token'))->toBeTrue();
});

it('пропускает, когда запрос вообще не ушёл', function (): void {
    Http::fake(fn () => throw new ConnectionException('timed out'));

    Log::shouldReceive('warning')->once();

    expect(smartCaptchaVerifier()->verify('token'))->toBeTrue();
});

it('не тратит запрос на пустой токен', function (): void {
    // Тарифицируются только успешные проверки, но и запроса с известным
    // заранее ответом не делаем.
    Http::fake();

    expect(smartCaptchaVerifier()->verify(''))->toBeFalse();

    Http::assertNothingSent();
});

it('шлёт форму с ключом, токеном и адресом', function (): void {
    Http::fake([SMARTCAPTCHA_VALIDATE_URL => Http::response(['status' => 'ok'])]);

    smartCaptchaVerifier()->verify('the-token', '10.0.0.1');

    Http::assertSent(function ($request): bool {
        expect($request->method())->toBe('POST')
            ->and($request->hasHeader('Content-Type', 'application/x-www-form-urlencoded'))->toBeTrue();

        return $request['secret'] === 'ysc2_secret'
            && $request['token'] === 'the-token'
            && $request['ip'] === '10.0.0.1';
    });
});

it('не шлёт пустой адрес', function (): void {
    // Пустое поле ip сервису лучше не слать вовсе, чем слать пустым.
    Http::fake([SMARTCAPTCHA_VALIDATE_URL => Http::response(['status' => 'ok'])]);

    smartCaptchaVerifier()->verify('the-token', null);

    Http::assertSent(fn ($request): bool => ! array_key_exists('ip', $request->data()));
});
