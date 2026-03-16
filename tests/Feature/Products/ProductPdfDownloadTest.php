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

    $this->get(route('product.print', ['product' => $product, 'dl' => 1]))
        ->assertOk()
        ->assertDownload()
        ->assertHeader('content-type', 'application/pdf');
});
