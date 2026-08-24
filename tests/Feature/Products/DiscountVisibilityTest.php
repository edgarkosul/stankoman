<?php

use App\Models\Product;
use App\Models\User;

function discountedProduct(): Product
{
    return Product::query()->create([
        'name' => 'Станок со скидкой для зарегистрированных',
        'slug' => 'stanok-so-skidkoj-dlya-zaregistrirovannyh',
        'sku' => 'DISC-1',
        'is_active' => true,
        'in_stock' => true,
        'price_amount' => 300_000,
        'discount_price' => 270_000,
    ]);
}

it('hides the discounted price from guests and offers to register', function (): void {
    $product = discountedProduct();

    $this->get(route('product.show', ['product' => $product]))
        ->assertSuccessful()
        ->assertSee(price(300_000), false)
        ->assertDontSee(price(270_000), false)
        ->assertSee('−10%', false)
        ->assertSee('Зарегистрируйтесь и получите скидку', false)
        ->assertSee(route('register'), false)
        ->assertSee(route('login'), false);
});

it('shows the discounted price to authenticated customers', function (): void {
    $product = discountedProduct();

    $this->actingAs(User::factory()->create())
        ->get(route('product.show', ['product' => $product]))
        ->assertSuccessful()
        ->assertSee(price(270_000), false)
        ->assertSee(price(300_000), false)
        ->assertDontSee('−10%', false)
        ->assertDontSee('Зарегистрируйтесь и получите скидку', false);
});

it('keeps the regular price for guests when there is no discount', function (): void {
    $product = Product::query()->create([
        'name' => 'Станок без скидки',
        'slug' => 'stanok-bez-skidki',
        'is_active' => true,
        'in_stock' => true,
        'price_amount' => 300_000,
    ]);

    $this->get(route('product.show', ['product' => $product]))
        ->assertSuccessful()
        ->assertSee(price(300_000), false)
        ->assertDontSee('−10%', false)
        ->assertDontSee('Зарегистрируйтесь и получите скидку', false);
});

it('keeps the request price state without a member discount prompt', function (): void {
    $product = Product::query()->create([
        'name' => 'Станок с ценой по запросу',
        'slug' => 'stanok-s-cenoj-po-zaprosu',
        'is_active' => true,
        'in_stock' => true,
        'price_amount' => 0,
        'discount_price' => 1,
    ]);

    $this->get(route('product.show', ['product' => $product]))
        ->assertSuccessful()
        ->assertSee('Цена по запросу', false)
        ->assertDontSee('Зарегистрируйтесь и получите скидку', false);
});

it('does not render a zero percent badge for a small member discount', function (): void {
    $product = Product::query()->create([
        'name' => 'Станок с малой скидкой',
        'slug' => 'stanok-s-maloj-skidkoj',
        'is_active' => true,
        'in_stock' => true,
        'price_amount' => 1_000,
        'discount_price' => 999,
    ]);

    $this->get(route('product.show', ['product' => $product]))
        ->assertSuccessful()
        ->assertDontSee('−0%', false)
        ->assertSee('Зарегистрируйтесь и получите скидку', false)
        ->assertDontSee(price(999), false);
});

it('shows the regular and after registration prices on a guest product card', function (): void {
    $product = discountedProduct();

    $html = view('components.product.card', [
        'product' => $product,
    ])->render();

    expect($html)
        ->toContain('−10%')
        ->toContain('Обычная цена:')
        ->toContain('После регистрации:')
        ->toContain(price(300_000))
        ->toContain(price(270_000));
});

it('shows the discounted price and percent on an authenticated product card', function (): void {
    $product = discountedProduct();
    $this->actingAs(User::factory()->create());

    $html = view('components.product.card', [
        'product' => $product,
    ])->render();

    expect($html)
        ->toContain('−10%')
        ->toContain(price(300_000))
        ->toContain(price(270_000))
        ->not->toContain('После регистрации:');
});

it('does not show an after registration price on cards without a valid discount', function (array $attributes): void {
    $product = Product::query()->create($attributes);

    $html = view('components.product.card', [
        'product' => $product,
    ])->render();

    expect($html)
        ->not->toContain('Обычная цена:')
        ->not->toContain('После регистрации:');
})->with([
    'without discount' => [[
        'name' => 'Карточка без скидки',
        'slug' => 'card-without-discount',
        'price_amount' => 10_000,
    ]],
    'request price' => [[
        'name' => 'Карточка с ценой по запросу',
        'slug' => 'card-with-request-price',
        'price_amount' => 0,
        'discount_price' => 1,
    ]],
]);
