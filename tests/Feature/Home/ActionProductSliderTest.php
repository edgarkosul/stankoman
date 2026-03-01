<?php

use App\Models\Product;
use App\View\Components\Layouts\Partials\ActionProductSlider;

function createHomeSliderProduct(array $attributes = []): Product
{
    static $counter = 1;
    $index = $counter++;

    return Product::query()->create(array_merge([
        'name' => "Home Slider Product {$index}",
        'slug' => "home-slider-product-{$index}",
        'price_amount' => 100_000,
        'discount_price' => 80_000,
        'is_active' => true,
        'in_stock' => true,
    ], $attributes));
}

test('action slider component contains only active products with discount', function (): void {
    $included = createHomeSliderProduct([
        'name' => 'Included Product',
        'slug' => 'included-product',
    ]);
    $excludedWithoutDiscount = createHomeSliderProduct([
        'name' => 'No Discount Product',
        'slug' => 'no-discount-product',
        'discount_price' => null,
    ]);
    $excludedInactive = createHomeSliderProduct([
        'name' => 'Inactive Product',
        'slug' => 'inactive-product',
        'is_active' => false,
    ]);

    $component = new ActionProductSlider;
    $ids = $component->products->pluck('id');

    expect($ids)
        ->toContain($included->id)
        ->not->toContain($excludedWithoutDiscount->id)
        ->not->toContain($excludedInactive->id);
});

test('home page renders action product slider when discounted products exist', function (): void {
    createHomeSliderProduct([
        'name' => 'Home Discount Product',
        'slug' => 'home-discount-product',
    ]);

    $this->get(route('home'))
        ->assertSuccessful()
        ->assertSee('Акции:')
        ->assertSee('action-product-slider');
});

test('app js includes product slider initialization hooks', function (): void {
    $appJs = file_get_contents(resource_path('js/app.js'));

    expect($appJs)
        ->toContain('const equalizeProductSliderHeights = (slider) =>')
        ->toContain('card.style.minHeight = normalizedHeight;')
        ->not->toContain('card.style.height = normalizedHeight;')
        ->toContain('scheduleProductSliderEqualize(slider);')
        ->toContain('const initProductSliders = (root = document) =>')
        ->toContain("document.addEventListener('DOMContentLoaded', () => initProductSliders(document));")
        ->toContain("document.addEventListener('livewire:navigated', () => initProductSliders(document));")
        ->toContain('initProductSliders(scope);');
});
