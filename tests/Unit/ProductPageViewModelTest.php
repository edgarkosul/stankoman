<?php

use App\Models\Product;
use App\Support\ViewModels\ProductPageViewModel;
use Tests\TestCase;

uses(TestCase::class);

it('prefers meta_title over the legacy title field', function (): void {
    $viewModel = app(ProductPageViewModel::class, ['product' => new Product([
        'name' => 'Токарный станок',
        'title' => 'Legacy title',
        'meta_title' => 'SEO title',
        'price_amount' => 125000,
    ])]);

    expect($viewModel->metaTitle())->toBe('SEO title');
});

it('falls back to generated title instead of the legacy title field', function (): void {
    $viewModel = app(ProductPageViewModel::class, ['product' => new Product([
        'name' => 'Токарный станок',
        'title' => 'Legacy title',
        'price_amount' => 125000,
    ])]);

    expect($viewModel->metaTitle())->toBe('Купить Токарный станок по цене 125 000 ₽');
});
