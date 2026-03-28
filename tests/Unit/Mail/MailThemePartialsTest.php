<?php

use Illuminate\Support\HtmlString;
use Tests\TestCase;

uses(TestCase::class);

test('mail theme partials render configured company brand and contacts', function (): void {
    config()->set('app.url', 'https://app.example.com');
    config()->set('settings.general.shop_name', 'InterTooler.ru');
    config()->set('company.brand_line', 'Test Brand');
    config()->set('company.legal_name', 'ООО Тестовая компания');
    config()->set('company.site_url', 'https://settings.example.com');
    config()->set('company.site_host', 'settings.example.com');
    config()->set('company.phone', '+7 (999) 123-45-67');
    config()->set('company.public_email', 'public@example.com');
    config()->set('company.legal_addr', 'г. Краснодар, ул. Тестовая, 10');

    $header = view('vendor.mail.html.header', [
        'url' => 'https://fallback.example.com',
    ])->render();

    $footer = view('vendor.mail.html.footer', [
        'slot' => new HtmlString('Footer note'),
    ])->render();

    expect($header)
        ->toContain('brand-logo-link')
        ->toContain('images/logo.svg')
        ->toContain('alt="InterTooler.ru"')
        ->toContain('https://settings.example.com')
        ->toContain('settings.example.com')
        ->toContain('+7 (999) 123-45-67')
        ->toContain('public@example.com')
        ->not->toContain('Test Brand')
        ->not->toContain('ООО Тестовая компания')
        ->not->toContain('https://fallback.example.com');

    expect($footer)
        ->toContain('class="mail-shell footer-shell"')
        ->toContain('style="width: 640px; max-width: 640px;"')
        ->toContain('Test Brand')
        ->toContain('ООО Тестовая компания')
        ->toContain('г. Краснодар, ул. Тестовая, 10')
        ->toContain('https://settings.example.com')
        ->toContain('settings.example.com')
        ->toContain('+7 (999) 123-45-67')
        ->toContain('public@example.com')
        ->toContain('Footer note');
});
