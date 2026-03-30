<?php

use App\Models\Product;
use App\View\Components\Layouts\Partials\ActionProductSlider;
use App\View\Components\Product\Popular;

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

test('popular products component on home page uses popularity desc and requires at least five items', function (): void {
    $mostPopular = createHomeSliderProduct([
        'name' => 'Most Popular Product',
        'slug' => 'most-popular-product',
        'discount_price' => null,
        'popularity' => 900,
    ]);

    createHomeSliderProduct([
        'name' => 'Popular Product 2',
        'slug' => 'popular-product-2',
        'discount_price' => null,
        'popularity' => 700,
    ]);

    createHomeSliderProduct([
        'name' => 'Popular Product 3',
        'slug' => 'popular-product-3',
        'discount_price' => null,
        'popularity' => 500,
    ]);

    createHomeSliderProduct([
        'name' => 'Popular Product 4',
        'slug' => 'popular-product-4',
        'discount_price' => null,
        'popularity' => 300,
    ]);

    createHomeSliderProduct([
        'name' => 'Popular Product 5',
        'slug' => 'popular-product-5',
        'discount_price' => null,
        'popularity' => 100,
    ]);

    createHomeSliderProduct([
        'name' => 'Inactive Popular Product',
        'slug' => 'inactive-popular-product',
        'discount_price' => null,
        'popularity' => 1_000,
        'is_active' => false,
    ]);

    $component = new Popular;

    expect($component->shouldRender())->toBeTrue()
        ->and($component->products->pluck('id')->all())
        ->toBe([
            $mostPopular->id,
            Product::query()->where('slug', 'popular-product-2')->value('id'),
            Product::query()->where('slug', 'popular-product-3')->value('id'),
            Product::query()->where('slug', 'popular-product-4')->value('id'),
            Product::query()->where('slug', 'popular-product-5')->value('id'),
        ]);
});

test('home page renders popular products slider only when at least five items exist', function (): void {
    foreach (range(1, 4) as $index) {
        createHomeSliderProduct([
            'name' => "Not Enough Popular Product {$index}",
            'slug' => "not-enough-popular-product-{$index}",
            'discount_price' => null,
            'popularity' => $index,
        ]);
    }

    $this->get(route('home'))
        ->assertSuccessful()
        ->assertDontSee('Популярные товары:');

    createHomeSliderProduct([
        'name' => 'Enough Popular Product 5',
        'slug' => 'enough-popular-product-5',
        'discount_price' => null,
        'popularity' => 5,
    ]);

    $this->get(route('home'))
        ->assertSuccessful()
        ->assertSee('Популярные товары:')
        ->assertSee('product-slider');
});

test('app js includes product slider initialization hooks', function (): void {
    $appJs = file_get_contents(resource_path('js/app.js'));

    expect($appJs)
        ->toContain('const equalizeProductSliderHeights = (slider) =>')
        ->toContain("slider.querySelectorAll(':scope > .swiper-wrapper > .swiper-slide > *')")
        ->not->toContain("slider.querySelectorAll('.swiper-slide > *')")
        ->toContain('card.style.minHeight = normalizedHeight;')
        ->not->toContain('card.style.height = normalizedHeight;')
        ->toContain('scheduleProductSliderEqualize(slider);')
        ->toContain('const initProductSliders = (root = document) =>')
        ->toContain("document.addEventListener('DOMContentLoaded', () => initProductSliders(document));")
        ->toContain("document.addEventListener('livewire:navigated', () => initProductSliders(document));")
        ->toContain('initProductSliders(scope);');
});
