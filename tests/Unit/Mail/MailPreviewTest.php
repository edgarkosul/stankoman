<?php

use App\Support\Mail\MailPreviewFactory;
use Tests\TestCase;

uses(TestCase::class);

test('mail preview index can be rendered without database bootstrap', function () {
    $response = $this->get(route('mail.preview.index'));

    $response->assertOk();
    $response->assertSee('Mail Previews', escape: false);
});

test('mail preview catalog renders expected content', function () {
    $factory = app(MailPreviewFactory::class);

    foreach ($factory->catalog() as $preview) {
        $response = $this->get(route('mail.preview.show', $preview['key']));

        $response->assertOk();
        $response->assertSeeText($preview['expectedText']);
    }
});

test('welcome set password preview renders updated onboarding steps', function () {
    $response = $this->get(route('mail.preview.show', 'welcome-set-password'));
    $publicEmail = (string) config('company.public_email');

    $response->assertOk();
    $response->assertSee('www.intertooler.ru', escape: false);
    $response->assertSee('+7 (900) 246-86-60', escape: false);
    $response->assertSee('mailto:'.$publicEmail, escape: false);
    $response->assertSeeText('Установите пароль.');
    $response->assertSeeText('Войдите в личный кабинет.');
    $response->assertSeeText('Подтвердите email по отдельному письму');
    $response->assertDontSeeText('Подтвердить email');
    $response->assertSee('padding: 14px 26px', escape: false);
    $response->assertSee('white-space: nowrap', escape: false);
});

test('order submitted previews render stacked item facts under product name', function () {
    $customerResponse = $this->get(route('mail.preview.show', 'order-submitted-customer'));

    $customerResponse->assertOk();
    $customerResponse->assertSee('display: none; font-size: 0; line-height: 0; padding: 0;', escape: false);
    $customerResponse->assertSee('class="order-item-name"', escape: false);
    $customerResponse->assertSee('class="order-item-facts"', escape: false);
    $customerResponse->assertSeeText('Кол-во');
    $customerResponse->assertSeeText('Цена');
    $customerResponse->assertSeeText('Сумма');

    $managerResponse = $this->get(route('mail.preview.show', 'order-submitted-manager'));

    $managerResponse->assertOk();
    $managerResponse->assertSee('display: none; font-size: 0; line-height: 0; padding: 0;', escape: false);
    $managerResponse->assertSee('class="order-item-sku"', escape: false);
    $managerResponse->assertSeeText('SKU:');
    $managerResponse->assertSeeText('Кол-во');
    $managerResponse->assertSeeText('Цена');
    $managerResponse->assertSeeText('Сумма');
});

test('order submitted manager preview renders metadata rows on separate lines inside panel', function () {
    $response = $this->get(route('mail.preview.show', 'order-submitted-manager'));

    $response->assertOk();
    $response->assertSee('class="mail-key-value-list"', escape: false);
    $response->assertSee('class="mail-key-value-row"', escape: false);
    $response->assertSee('class="mail-key-value-label"', escape: false);
    $response->assertSeeText('Дата:');
    $response->assertSeeText('Статус заказа:');
    $response->assertSeeText('Статус оплаты:');
    $response->assertSeeText('Способ оплаты:');
    $response->assertSeeText('ID заказа:');
    $response->assertDontSeeText('Публичный хеш:');
});
