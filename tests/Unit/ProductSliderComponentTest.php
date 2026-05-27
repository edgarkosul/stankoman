<?php

use Tests\TestCase;

uses(TestCase::class);

test('product slider component contains navigation and product card markup', function () {
    $component = file_get_contents(resource_path('views/components/product/slider.blade.php'));

    expect($component)
        ->toContain('product-slider action-product-slider swiper')
        ->toContain('data-nav="prev"')
        ->toContain('data-nav="next"')
        ->toContain('<x-product.card :product="$product" :index="$loop->index" />')
        ->not->toContain('x-product.lite-card');
});

test('product slider component renders with empty products collection', function () {
    $html = view('components.product.slider', [
        'products' => collect(),
    ])->render();

    expect($html)
        ->toContain('product-slider action-product-slider swiper')
        ->toContain('data-nav="prev"')
        ->toContain('data-nav="next"');
});

test('popular products component is ignored by livewire morph updates', function (): void {
    $component = file_get_contents(resource_path('views/components/product/popular.blade.php'));

    expect($component)
        ->toContain('<section wire:ignore')
        ->toContain('Популярные товары:');
});
