<?php

use App\Models\Product;

it('shows product pdf download link on product page', function (): void {
    $product = Product::query()->create([
        'name' => 'Тестовый товар для скачивания PDF',
        'slug' => 'test-product-pdf-download-link',
        'is_active' => true,
        'price_amount' => 125_000,
    ]);

    $downloadUrl = route('product.print', ['product' => $product, 'dl' => 1], false);

    $this->get(route('product.show', ['product' => $product]))
        ->assertSuccessful()
        ->assertSee($downloadUrl, false)
        ->assertSee('Скачать PDF', false);
});

it('downloads product pdf when dl query parameter is set', function (): void {
    $product = Product::query()->create([
        'name' => 'Тестовый товар PDF download',
        'slug' => 'test-product-pdf-download-response',
        'is_active' => true,
        'price_amount' => 90_000,
    ]);

    $response = $this->get(route('product.print', ['product' => $product, 'dl' => 1]));

    $response
        ->assertOk()
        ->assertDownload()
        ->assertHeader('content-type', 'application/pdf');

    $pdf = $response->getContent();

    expect($pdf)
        ->toStartWith('%PDF-')
        ->toContain('/FontName /RobotoCondensed-Regular')
        ->toContain('/FontName /RobotoCondensed-Bold')
        ->not->toContain('/BaseFont /inter_');
});

it('renders configured company contacts and bank details in product pdf offer view', function (): void {
    config()->set('company.site_url', 'https://settings.example.com');
    config()->set('company.site_host', 'docs.settings.example.com');
    config()->set('company.phone', '+7 (999) 123-45-67');
    config()->set('company.legal_addr', 'г. Краснодар, ул. Тестовая, 10');
    config()->set('company.bank.name', 'Тестовый банк');
    config()->set('company.bank.bik', '012345678');
    config()->set('company.bank.rs', '40802810999999999999');
    config()->set('company.bank.ks', '30101810999999999999');

    $product = Product::query()->create([
        'name' => 'Тестовый товар PDF offer view',
        'slug' => 'test-product-pdf-offer-view',
        'is_active' => true,
        'price_amount' => 90_000,
    ]);

    $html = view('pages.product.pdf.offer', [
        'product' => $product,
        'cover' => null,
        'sku' => $product->id,
        'price' => '90 000 ₽',
        'attributes' => [],
        'descriptionHtml' => '<p>Описание</p>',
    ])->render();

    expect($html)
        ->toContain('font-family: "RobotoCondensed"')
        ->toContain('font-family: RobotoCondensed, sans-serif')
        ->not->toContain('font-family: "Inter"')
        ->not->toContain('font-family: Inter, sans-serif')
        ->toContain('КОНТАКТЫ И РЕКВИЗИТЫ')
        ->toContain('https://settings.example.com')
        ->toContain('docs.settings.example.com')
        ->toContain('+7 (999) 123-45-67')
        ->toContain('г. Краснодар, ул. Тестовая, 10')
        ->toContain('Тестовый банк')
        ->toContain('012345678')
        ->toContain('40802810999999999999')
        ->toContain('30101810999999999999');
});
