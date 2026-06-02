<?php

use App\Mail\OrderSubmittedCustomerMail;
use App\Support\Mail\MailPreviewFactory;
use Tests\TestCase;

uses(TestCase::class);

test('order submitted customer mail uses shop address and auto generated headers', function (): void {
    config()->set('mail.from.address', 'noreply@intertooler.ru');
    config()->set('company.public_email', 'sales@intertooler.ru');
    config()->set('settings.general.shop_name', 'InterTooler.ru');

    $mail = app(MailPreviewFactory::class)->build('order-submitted-customer');

    expect($mail)->toBeInstanceOf(OrderSubmittedCustomerMail::class);

    $mail->assertFrom('noreply@intertooler.ru', 'InterTooler.ru');
    $mail->assertHasReplyTo('sales@intertooler.ru', 'InterTooler.ru');

    expect($mail->headers()->text)->toBe([
        'Auto-Submitted' => 'auto-generated',
        'X-Auto-Response-Suppress' => 'All',
    ]);
});

test('order submitted customer mail has simple plain text body', function (): void {
    config()->set('company.public_email', 'sales@intertooler.ru');
    config()->set('company.phone', '+7 (900) 246-86-60');
    config()->set('company.site_url', 'https://intertooler.ru');

    $mail = app(MailPreviewFactory::class)->build('order-submitted-customer');

    expect($mail)->toBeInstanceOf(OrderSubmittedCustomerMail::class);

    $mail
        ->assertSeeInText('Спасибо за заказ!')
        ->assertSeeInText('Ваш заказ №27-03-26/07 принят и передан в обработку.')
        ->assertSeeInText('Состав заказа:')
        ->assertSeeInText('Станок ленточнопильный IT-4500')
        ->assertSeeInText('Контакты магазина:')
        ->assertSeeInText('sales@intertooler.ru')
        ->assertSeeInText('Если вы не оформляли этот заказ, напишите нам на sales@intertooler.ru.')
        ->assertDontSeeInText('# Спасибо за заказ')
        ->assertDontSeeInText('**')
        ->assertDontSeeInText('|');
});

test('order submitted customer mail uses markdown html with text logo', function (): void {
    config()->set('company.public_email', 'sales@intertooler.ru');
    config()->set('company.phone', '+7 (900) 246-86-60');
    config()->set('settings.general.shop_name', 'InterTooler.ru');

    $mail = app(MailPreviewFactory::class)->build('order-submitted-customer');

    expect($mail)->toBeInstanceOf(OrderSubmittedCustomerMail::class);

    $html = $mail->render();

    expect($html)
        ->toContain('Спасибо за заказ')
        ->toContain('Ваш заказ')
        ->toContain('№27-03-26/07')
        ->toContain('brand-logo-link')
        ->toContain('InterTooler.ru')
        ->toContain('class="order-item-name"')
        ->toContain('class="order-item-facts"')
        ->toContain('+7 (900) 246-86-60')
        ->toContain('sales@intertooler.ru')
        ->not->toContain('<img')
        ->not->toContain('images/logo.png')
        ->not->toContain('images/logo.svg');
});
