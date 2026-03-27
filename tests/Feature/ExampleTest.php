<?php

test('home page renders base sections', function () {
    $response = $this->get(route('home'));

    $response
        ->assertOk()
        ->assertSee('<div class="flex min-h-screen flex-col">', false)
        ->assertSee('<main class="flex flex-1 flex-col">', false)
        ->assertSee('InterTooler.ru');
});

test('site footer renders public company email without legal details', function (): void {
    config()->set('company.brand_line', 'Test Brand');
    config()->set('company.legal_name', 'ООО Тестовая компания');
    config()->set('company.site_host', 'settings.example.com');
    config()->set('company.site_url', 'https://settings.example.com');
    config()->set('company.legal_addr', 'г. Краснодар, ул. Тестовая, 10');
    config()->set('company.public_email', 'public@example.com');

    $footer = view('components.layouts.partials.footer')->render();

    expect($footer)
        ->toContain('Test Brand')
        ->toContain('settings.example.com')
        ->not->toContain('ООО Тестовая компания')
        ->not->toContain('г. Краснодар, ул. Тестовая, 10')
        ->toContain('mailto:public@example.com')
        ->toContain('public@example.com');
});
