<?php

use App\Events\Orders\OrderSubmitted;
use App\Livewire\Pages\Product\OneClickOrder;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\CartService;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

it('creates guest one click order without touching cart contents', function (): void {
    Event::fake([OrderSubmitted::class]);

    $root = Category::query()->create([
        'name' => 'Каталог',
        'slug' => 'catalog-one-click',
        'parent_id' => Category::defaultParentKey(),
        'order' => 1,
        'is_active' => true,
    ]);
    $leaf = Category::query()->create([
        'name' => 'Гибочные станки',
        'slug' => 'bending-machines',
        'parent_id' => $root->id,
        'order' => 1,
        'is_active' => true,
    ]);

    $oneClickProduct = createOneClickProduct([
        'name' => 'Станок для гибки арматуры Vektor GW32',
        'slug' => 'vektor-gw32',
        'sku' => 'GW32',
        'brand' => 'VEKTOR',
        'price_amount' => 150000,
    ]);
    $oneClickProduct->categories()->attach($leaf->id, ['is_primary' => true]);
    $cartProduct = createOneClickProduct([
        'name' => 'Товар в корзине',
        'slug' => 'cart-product',
        'price_amount' => 89000,
    ]);

    $cart = app(CartService::class);
    $cart->addItem($cartProduct->id, 2);

    Livewire::test(OneClickOrder::class, ['productId' => $oneClickProduct->id])
        ->call('openModal', $oneClickProduct->id, 3)
        ->assertSet('isOpen', true)
        ->assertSet('quantity', 3)
        ->set('customerName', 'Иван Петров')
        ->set('customerPhone', '+79990001122')
        ->set('customerEmail', 'guest@example.test')
        ->set('shippingCountry', 'Россия')
        ->set('shippingRegion', '')
        ->set('shippingComment', 'Позвоните утром')
        ->call('submit')
        ->assertSet('submitted', true)
        ->assertDispatched('ecommerce:purchase', function (string $event, array $params) use ($oneClickProduct): bool {
            $productPayload = $params['payload']['purchase']['products'][0] ?? [];

            return $event === 'ecommerce:purchase'
                && ($params['payload']['purchase']['actionField']['id'] ?? null) !== null
                && ($productPayload['id'] ?? null) === 'GW32'
                && (float) ($productPayload['price'] ?? 0) === 150000.0
                && ($productPayload['brand'] ?? null) === 'VEKTOR'
                && ($productPayload['category'] ?? null) === 'Каталог / Гибочные станки'
                && (int) ($productPayload['quantity'] ?? 0) === 3
                && ($productPayload['name'] ?? null) === $oneClickProduct->name;
        });

    $order = Order::query()->with('items')->latest('id')->firstOrFail();
    $item = $order->items->firstOrFail();

    expect($order->user_id)->toBeNull()
        ->and($order->customer_name)->toBe('Иван Петров')
        ->and($order->customer_phone)->toBe('+79990001122')
        ->and($order->customer_email)->toBe('guest@example.test')
        ->and($order->shipping_country)->toBe('Россия')
        ->and($order->shipping_region)->toBeNull()
        ->and($order->shipping_comment)->toBe('Позвоните утром')
        ->and($order->payment_method)->toBeNull()
        ->and($item->product_id)->toBe($oneClickProduct->id)
        ->and((int) $item->quantity)->toBe(3)
        ->and($item->meta)->toMatchArray([
            'analytics_id' => 'GW32',
            'brand' => 'VEKTOR',
            'category_path' => 'Каталог / Гибочные станки',
        ])
        ->and(app(CartService::class)->getCart()->items()->count())->toBe(1)
        ->and(app(CartService::class)->quantityFor($cartProduct->id))->toBe(2);
});

it('keeps one click orders guest-only even for authenticated matching users', function (): void {
    Event::fake([OrderSubmitted::class]);

    $user = User::factory()->create([
        'name' => 'Павел Сидоров',
        'email' => 'pavel.sidorov@example.test',
        'phone' => '+79991112233',
        'shipping_country' => 'Россия',
        'shipping_region' => 'Московская область',
    ]);
    $product = createOneClickProduct([
        'name' => 'Металлорежущий станок',
        'slug' => 'metal-cut-machine',
        'price_amount' => 230000,
    ]);

    $this->actingAs($user);

    Livewire::actingAs($user)
        ->test(OneClickOrder::class, ['productId' => $product->id])
        ->call('openModal', $product->id, 1)
        ->assertSet('customerName', 'Павел Сидоров')
        ->assertSet('customerEmail', 'pavel.sidorov@example.test')
        ->assertSet('customerPhone', '+79991112233')
        ->assertSet('shippingCountry', 'Россия')
        ->assertSet('shippingRegion', 'Московская область')
        ->call('submit')
        ->assertSet('submitted', true);

    $order = Order::query()->latest('id')->firstOrFail();

    expect($order->user_id)->toBeNull()
        ->and($order->customer_email)->toBe('pavel.sidorov@example.test')
        ->and($order->customer_name)->toBe('Павел Сидоров');
});

it('validates required one click fields', function (): void {
    $product = createOneClickProduct([
        'price_amount' => 100000,
    ]);

    Livewire::test(OneClickOrder::class, ['productId' => $product->id])
        ->call('openModal', $product->id, 1)
        ->set('shippingCountry', '')
        ->call('submit')
        ->assertHasErrors([
            'customerName',
            'customerPhone',
            'shippingCountry',
        ]);
});

it('renders scroll lock hook and inner scroll container for one click modal', function (): void {
    $product = createOneClickProduct([
        'price_amount' => 100000,
    ]);

    Livewire::test(OneClickOrder::class, ['productId' => $product->id])
        ->call('openModal', $product->id, 1)
        ->assertSee("x-init=\"syncScrollLock(\$wire.isOpen); \$watch('\$wire.isOpen', value => syncScrollLock(value))\"", escape: false)
        ->assertSee('max-h-[calc(100dvh-2rem)]', escape: false)
        ->assertSee('overflow-y-auto overscroll-contain', escape: false)
        ->assertSee('Нажимая кнопку «Отправить», вы соглашаетесь с')
        ->assertSee('/page/terms', escape: false)
        ->assertSee('/page/privacy', escape: false);
});

function createOneClickProduct(array $attributes = []): Product
{
    static $sequence = 1;

    $defaults = [
        'name' => 'One Click Product '.$sequence,
        'slug' => 'one-click-product-'.$sequence,
        'is_active' => true,
        'in_stock' => true,
        'price_amount' => 100000,
    ];

    $sequence++;

    return Product::query()->create(array_merge($defaults, $attributes));
}
