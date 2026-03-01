<?php

use App\Events\Orders\OrderSubmitted;
use App\Listeners\SyncCartOnLogin;
use App\Livewire\Checkout\Wizard;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\CartService;
use App\Support\CheckoutService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

it('redirects to cart page when checkout is opened with empty cart', function (): void {
    $response = $this->get(route('checkout.index'));

    $response->assertRedirect(route('cart.index'));
});

it('creates order through checkout wizard and clears user cart', function (): void {
    $user = User::factory()->create();
    $product = createCheckoutProduct([
        'price_amount' => 150000,
        'discount_price' => 130000,
    ]);

    $this->actingAs($user);

    app(CartService::class)->addItem($product->id, 2);

    $response = Livewire::actingAs($user)
        ->test(Wizard::class)
        ->set('contact.customer_name', 'Иван Петров')
        ->set('contact.customer_phone', '+79990001122')
        ->set('contact.customer_email', 'ivan@example.test')
        ->set('delivery.shipping_method', 'delivery')
        ->set('delivery.shipping_city', 'Краснодар')
        ->set('review.payment_method', 'cash')
        ->set('review.accept_terms', true)
        ->call('confirm');

    $order = Order::query()->with('items')->firstOrFail();

    $response->assertRedirect(route('checkout.success', [
        'date' => $order->order_date->format('d-m-y'),
        'seq' => str_pad((string) $order->seq, 2, '0', STR_PAD_LEFT),
    ]));

    expect($order->items)->toHaveCount(1)
        ->and((int) $order->items->first()->quantity)->toBe(2)
        ->and((float) $order->items_subtotal)->toBe(300000.0)
        ->and((float) $order->discount_total)->toBe(40000.0)
        ->and((float) $order->grand_total)->toBe(260000.0);

    $cart = Cart::query()->where('user_id', $user->id)->firstOrFail();

    expect($cart->items()->count())->toBe(0);
});

it('creates order through checkout wizard without delivery address', function (): void {
    $user = User::factory()->create();
    $product = createCheckoutProduct([
        'price_amount' => 150000,
        'discount_price' => 130000,
    ]);

    $this->actingAs($user);

    app(CartService::class)->addItem($product->id, 1);

    $response = Livewire::actingAs($user)
        ->test(Wizard::class)
        ->set('contact.customer_name', 'Иван Петров')
        ->set('contact.customer_phone', '+79990001122')
        ->set('contact.customer_email', 'ivan@example.test')
        ->set('delivery.shipping_method', 'delivery')
        ->set('delivery.shipping_city', '')
        ->set('review.payment_method', 'cash')
        ->set('review.accept_terms', true)
        ->call('confirm');

    $order = Order::query()->firstOrFail();

    $response->assertRedirect(route('checkout.success', [
        'date' => $order->order_date->format('d-m-y'),
        'seq' => str_pad((string) $order->seq, 2, '0', STR_PAD_LEFT),
    ]));

    expect($order->shipping_city)->toBeNull()
        ->and($order->items()->count())->toBe(1);
});

it('calculates discount totals in checkout service for authenticated user', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $discountedProduct = createCheckoutProduct([
        'price_amount' => 100000,
        'discount_price' => 80000,
    ]);

    $regularProduct = createCheckoutProduct([
        'price_amount' => 50000,
        'discount_price' => null,
    ]);

    $cartService = app(CartService::class);
    $cartService->addItem($discountedProduct->id, 2);
    $cartService->addItem($regularProduct->id, 1);

    $order = app(CheckoutService::class)->submit(
        [
            'customer_name' => 'Покупатель',
            'customer_phone' => '+79991112233',
            'customer_email' => 'buyer@example.test',
            'is_company' => false,
        ],
        [
            'shipping_method' => 'delivery',
            'shipping_city' => 'Краснодар',
        ],
        [
            'payment_method' => 'bank_transfer',
            'accept_terms' => true,
        ],
    );

    expect((float) $order->items_subtotal)->toBe(250000.0)
        ->and((float) $order->discount_total)->toBe(40000.0)
        ->and((float) $order->grand_total)->toBe(210000.0)
        ->and($order->items()->count())->toBe(2);
});

it('creates account from guest checkout and applies account discounts', function (): void {
    $product = createCheckoutProduct([
        'price_amount' => 150000,
        'discount_price' => 120000,
    ]);

    app(CartService::class)->addItem($product->id, 2);
    Event::fake([OrderSubmitted::class]);

    $order = app(CheckoutService::class)->submit(
        [
            'customer_name' => 'Новый клиент',
            'customer_phone' => '+79993334455',
            'customer_email' => 'new-checkout-user@example.test',
            'create_account' => true,
            'is_company' => false,
        ],
        [
            'shipping_method' => 'delivery',
            'shipping_region' => 'Краснодарский край',
            'shipping_city' => 'Краснодар',
            'shipping_street' => 'Красная',
        ],
        [
            'payment_method' => 'cash',
            'accept_terms' => true,
        ],
    );

    $user = User::query()->where('email', 'new-checkout-user@example.test')->firstOrFail();

    expect($order->user_id)->toBe($user->id)
        ->and((float) $order->items_subtotal)->toBe(300000.0)
        ->and((float) $order->discount_total)->toBe(60000.0)
        ->and((float) $order->grand_total)->toBe(240000.0)
        ->and($user->shipping_region)->toBe('Краснодарский край')
        ->and($user->shipping_city)->toBe('Краснодар')
        ->and($user->shipping_street)->toBe('Красная');
});

it('requires email when create account is enabled in checkout wizard', function (): void {
    $product = createCheckoutProduct([
        'price_amount' => 100000,
    ]);

    app(CartService::class)->addItem($product->id, 1);

    Livewire::test(Wizard::class)
        ->set('contact.customer_name', 'Покупатель')
        ->set('contact.customer_phone', '+79990001122')
        ->set('contact.customer_email', '')
        ->set('contact.create_account', true)
        ->set('delivery.shipping_method', 'delivery')
        ->set('delivery.shipping_city', 'Краснодар')
        ->set('review.payment_method', 'cash')
        ->set('review.accept_terms', true)
        ->call('confirm')
        ->assertHasErrors(['contact.customer_email' => 'required_if']);
});

it('renders phone mask attribute on checkout page', function (): void {
    $product = createCheckoutProduct([
        'price_amount' => 100000,
    ]);

    app(CartService::class)->addItem($product->id, 1);

    $this->get(route('checkout.index'))
        ->assertSuccessful()
        ->assertSee('data-phone-mask="ru"', escape: false)
        ->assertSee('placeholder="+7 (___) ___-__-__"', escape: false)
        ->assertSee('Формат: +7 (999) 123-45-67.');
});

it('shows inline login prompt for guests on checkout page', function (): void {
    $product = createCheckoutProduct([
        'price_amount' => 100000,
    ]);

    app(CartService::class)->addItem($product->id, 1);

    $this->get(route('checkout.index'))
        ->assertSuccessful()
        ->assertSee('Если вы уже зарегистрированы,')
        ->assertSee('Войдите')
        ->assertSee('и поля заказа заполнятся автоматически.');
});

it('opens login modal from checkout and stores preserve cart sync context in session', function (): void {
    $product = createCheckoutProduct([
        'price_amount' => 100000,
    ]);

    app(CartService::class)->addItem($product->id, 1);

    Livewire::test(Wizard::class)
        ->call('openLoginModal')
        ->assertDispatched('showLoginModal');

    expect(session('url.intended'))->toBe(route('checkout.index', absolute: false))
        ->and(session(SyncCartOnLogin::CHECKOUT_SYNC_MODE_SESSION_KEY))->toBe(CartService::SYNC_MODE_PRESERVE_GUEST);
});

it('does not show pickup option on checkout delivery step', function (): void {
    $product = createCheckoutProduct([
        'price_amount' => 100000,
    ]);

    app(CartService::class)->addItem($product->id, 1);

    Livewire::test(Wizard::class)
        ->set('contact.customer_name', 'Покупатель')
        ->set('contact.customer_phone', '+79990001122')
        ->call('next')
        ->assertSet('currentStep', 2)
        ->assertSee('Способ доставки')
        ->assertSee('Доставка')
        ->assertDontSee('Самовывоз');
});

it('prefills checkout contact data for authenticated user', function (): void {
    $user = User::factory()->create([
        'name' => 'Павел Сидоров',
        'email' => 'pavel.sidorov@example.test',
        'phone' => '+79991112233',
        'is_company' => true,
        'company_name' => 'ООО Профиль',
        'inn' => '7707083893',
        'kpp' => '770701001',
        'shipping_region' => 'Московская область',
        'shipping_city' => 'Химки',
        'shipping_street' => 'Ленина',
        'shipping_house' => '15',
        'shipping_postcode' => '141400',
    ]);
    $product = createCheckoutProduct([
        'price_amount' => 90000,
    ]);

    $this->actingAs($user);
    app(CartService::class)->addItem($product->id, 1);

    Livewire::actingAs($user)
        ->test(Wizard::class)
        ->assertSet('contact.customer_name', 'Павел Сидоров')
        ->assertSet('contact.customer_email', 'pavel.sidorov@example.test')
        ->assertSet('contact.customer_phone', '+79991112233')
        ->assertSet('contact.is_company', true)
        ->assertSet('contact.company_name', 'ООО Профиль')
        ->assertSet('contact.inn', '7707083893')
        ->assertSet('contact.kpp', '770701001')
        ->assertSet('delivery.shipping_region', 'Московская область')
        ->assertSet('delivery.shipping_city', 'Химки')
        ->assertSet('delivery.shipping_street', 'Ленина')
        ->assertSet('delivery.shipping_house', '15')
        ->assertSet('delivery.shipping_postcode', '141400')
        ->assertSet('contact.create_account', false);
});

it('rejects pickup as shipping method in checkout wizard', function (): void {
    $product = createCheckoutProduct([
        'price_amount' => 100000,
    ]);

    app(CartService::class)->addItem($product->id, 1);

    Livewire::test(Wizard::class)
        ->set('contact.customer_name', 'Покупатель')
        ->set('contact.customer_phone', '+79990001122')
        ->set('contact.customer_email', '')
        ->set('delivery.shipping_method', 'pickup')
        ->set('review.payment_method', 'cash')
        ->set('review.accept_terms', true)
        ->call('confirm')
        ->assertHasErrors(['delivery.shipping_method' => 'in']);
});

it('updates authenticated user shipping and company profile through checkout', function (): void {
    $user = User::factory()->create([
        'shipping_city' => 'Старый город',
        'shipping_street' => 'Старая улица',
        'is_company' => false,
    ]);
    $product = createCheckoutProduct([
        'price_amount' => 98000,
    ]);

    $this->actingAs($user);
    app(CartService::class)->addItem($product->id, 1);

    app(CheckoutService::class)->submit(
        [
            'customer_name' => 'Иван Иванов',
            'customer_phone' => '+79990001122',
            'customer_email' => 'ivan@example.test',
            'is_company' => true,
            'company_name' => 'ООО Новый профиль',
            'inn' => '7707083893',
            'kpp' => '770701001',
        ],
        [
            'shipping_method' => 'delivery',
            'shipping_country' => 'Россия',
            'shipping_region' => 'Краснодарский край',
            'shipping_city' => 'Краснодар',
            'shipping_street' => 'Красная',
            'shipping_house' => '10',
            'shipping_postcode' => '350000',
        ],
        [
            'payment_method' => 'cash',
            'accept_terms' => true,
        ],
    );

    $user->refresh();

    expect($user->shipping_country)->toBe('Россия')
        ->and($user->shipping_region)->toBe('Краснодарский край')
        ->and($user->shipping_city)->toBe('Краснодар')
        ->and($user->shipping_street)->toBe('Красная')
        ->and($user->shipping_house)->toBe('10')
        ->and($user->shipping_postcode)->toBe('350000')
        ->and((bool) $user->is_company)->toBeTrue()
        ->and($user->company_name)->toBe('ООО Новый профиль')
        ->and($user->inn)->toBe('7707083893')
        ->and($user->kpp)->toBe('770701001');
});

it('autofills company data by inn via dadata lookup', function (): void {
    Cache::flush();
    config()->set('services.dadata.token', 'dadata-test-token');
    config()->set('services.dadata.secret', 'dadata-test-secret');
    Http::preventStrayRequests();
    Http::fake([
        'https://suggestions.dadata.ru/*' => Http::response([
            'suggestions' => [
                [
                    'value' => 'ООО Тестовая компания',
                    'data' => [
                        'type' => 'LEGAL',
                        'inn' => '7707083893',
                        'kpp' => '770701001',
                        'name' => [
                            'full_with_opf' => 'ООО Тестовая компания',
                        ],
                    ],
                ],
            ],
        ], 200),
    ]);

    $product = createCheckoutProduct([
        'price_amount' => 120000,
    ]);
    app(CartService::class)->addItem($product->id, 1);

    Livewire::test(Wizard::class)
        ->set('contact.is_company', true)
        ->set('contact.inn', '7707083893')
        ->assertSet('contact.company_name', 'ООО Тестовая компания')
        ->assertSet('contact.kpp', '770701001');

    Http::assertSentCount(1);
});

it('validates phone, inn and kpp in checkout wizard', function (): void {
    $product = createCheckoutProduct([
        'price_amount' => 100000,
    ]);

    app(CartService::class)->addItem($product->id, 1);

    Livewire::test(Wizard::class)
        ->set('contact.is_company', true)
        ->set('contact.customer_name', 'Покупатель')
        ->set('contact.customer_phone', '12345')
        ->set('contact.customer_email', '')
        ->set('contact.company_name', 'ООО Тест')
        ->set('contact.inn', '1234567890')
        ->set('contact.kpp', '123')
        ->set('delivery.shipping_method', 'delivery')
        ->set('delivery.shipping_city', 'Краснодар')
        ->set('review.payment_method', 'cash')
        ->set('review.accept_terms', true)
        ->call('confirm')
        ->assertHasErrors([
            'contact.customer_phone',
            'contact.inn',
            'contact.kpp',
        ]);
});

it('requires kpp for company checkout with ten-digit inn', function (): void {
    Cache::flush();

    $product = createCheckoutProduct([
        'price_amount' => 100000,
    ]);

    app(CartService::class)->addItem($product->id, 1);

    Livewire::test(Wizard::class)
        ->set('contact.is_company', true)
        ->set('contact.customer_name', 'Покупатель')
        ->set('contact.customer_phone', '+79990001122')
        ->set('contact.customer_email', '')
        ->set('contact.company_name', 'ООО Тест')
        ->set('contact.inn', '7707083893')
        ->set('contact.kpp', '')
        ->set('delivery.shipping_method', 'delivery')
        ->set('delivery.shipping_city', 'Краснодар')
        ->set('review.payment_method', 'cash')
        ->set('review.accept_terms', true)
        ->call('confirm')
        ->assertHasErrors(['contact.kpp' => 'required']);
});

function createCheckoutProduct(array $attributes = []): Product
{
    static $sequence = 1;

    $defaults = [
        'name' => 'Checkout Product '.$sequence,
        'slug' => 'checkout-product-'.$sequence,
        'is_active' => true,
        'in_stock' => true,
        'price_amount' => 100000,
    ];

    $sequence++;

    return Product::query()->create(array_merge($defaults, $attributes));
}
