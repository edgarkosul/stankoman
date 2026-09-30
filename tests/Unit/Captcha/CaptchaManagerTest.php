<?php

use App\Services\Captcha\CaptchaManager;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

/*
 * Один класс на весь магазин отвечает, нужна ли проверка, каким ключом
 * рисовать виджет и пропускать ли токен. Проверяется именно это: неполная
 * настройка обязана читаться как выключенная капча, а не как закрытая форма.
 */

function captchaManager(bool $switchedOn = true, string $driver = 'smartcaptcha', array $overrides = []): CaptchaManager
{
    return new CaptchaManager(
        switchedOn: $switchedOn,
        driver: $driver,
        drivers: [
            'smartcaptcha' => array_merge([
                'site_key' => 'ysc1_client',
                'secret_key' => 'ysc2_server',
                'js_url' => 'https://smartcaptcha.cloud.yandex.ru/captcha.js',
                'validate_url' => 'https://smartcaptcha.cloud.yandex.ru/validate',
                'invisible' => true,
                'hide_shield' => true,
                'language' => 'ru',
                'timeout' => 4.0,
                'test' => false,
            ], $overrides),
        ],
    );
}

it('включена, когда есть рубильник и обе половины ключа', function (): void {
    expect(captchaManager()->enabled())->toBeTrue();
});

it('выключена рубильником', function (): void {
    expect(captchaManager(switchedOn: false)->enabled())->toBeFalse();
});

it('выключена драйвером null', function (): void {
    // Единственный способ погасить капчу без выката, если сервис начнёт
    // заворачивать живых покупателей.
    expect(captchaManager(driver: 'null')->enabled())->toBeFalse();
});

it('выключена без клиентского ключа', function (): void {
    // Без него виджет в браузере не поднимется, и «включённая» проверка
    // означала бы форму, которую невозможно отправить.
    expect(captchaManager(overrides: ['site_key' => ''])->enabled())->toBeFalse();
});

it('выключена без серверного ключа', function (): void {
    expect(captchaManager(overrides: ['secret_key' => ''])->enabled())->toBeFalse();
});

it('выключенная капча пропускает всех, не касаясь сети', function (): void {
    Http::fake();

    expect(captchaManager(switchedOn: false)->verify('что угодно'))->toBeTrue();

    Http::assertNothingSent();
});

it('включённая капча идёт к провайдеру', function (): void {
    Http::fake(['*' => Http::response(['status' => 'failed', 'message' => ''])]);

    expect(captchaManager()->verify('token'))->toBeFalse();

    Http::assertSentCount(1);
});

it('отдаёт разметке готовый конфиг', function (): void {
    expect(captchaManager()->frontendConfig())->toMatchArray([
        'enabled' => true,
        'siteKey' => 'ysc1_client',
        'jsUrl' => 'https://smartcaptcha.cloud.yandex.ru/captcha.js',
        'invisible' => true,
        'hideShield' => true,
        'language' => 'ru',
        'test' => false,
    ]);
});

it('в выключенном виде не отдаёт разметке ключ', function (): void {
    // Ключ не секрет, но светить его на странице, где он всё равно
    // не работает, незачем: для JS пустой ключ означает «ничего не грузить».
    $config = captchaManager(switchedOn: false)->frontendConfig();

    expect($config['enabled'])->toBeFalse()
        ->and($config['siteKey'])->toBe('');
});

it('требует плашку только на скрытом шилде', function (): void {
    // Плашка не оформление, а обязательство: угловой блок сервиса можно
    // убрать только в обмен на собственное уведомление.
    expect(captchaManager()->showsNotice())->toBeTrue()
        ->and(captchaManager(overrides: ['hide_shield' => false])->showsNotice())->toBeFalse()
        ->and(captchaManager(switchedOn: false)->showsNotice())->toBeFalse();
});
