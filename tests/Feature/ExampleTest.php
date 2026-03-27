<?php

test('home page renders base sections', function () {
    $response = $this->get(route('home'));

    $response
        ->assertOk()
        ->assertSee('<div class="flex min-h-screen flex-col">', false)
        ->assertSee('<main class="flex flex-1 flex-col">', false)
        ->assertSee('InterTooler.ru');
});

test('home page renders configured public company email in layout', function (): void {
    config()->set('company.brand_line', 'Test Brand');
    config()->set('company.legal_name', 'ООО Тестовая компания');
    config()->set('company.site_host', 'settings.example.com');
    config()->set('company.site_url', 'https://settings.example.com');
    config()->set('company.legal_addr', 'г. Краснодар, ул. Тестовая, 10');
    config()->set('company.public_email', 'public@example.com');

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('Test Brand')
        ->assertSee('ООО Тестовая компания')
        ->assertSee('settings.example.com')
        ->assertSee('г. Краснодар, ул. Тестовая, 10')
        ->assertSee('mailto:public@example.com', false)
        ->assertSee('public@example.com');
});
