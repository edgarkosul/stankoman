<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Carbon;

it('requires authentication for orders cabinet routes', function (): void {
    $order = Order::factory()->create();

    $this->get(route('user.orders.index'))
        ->assertRedirect(route('login'));

    $this->get(route('user.orders.show', orderRouteParams($order)))
        ->assertRedirect(route('login'));
});

it('shows only authenticated user orders in cabinet list', function (): void {
    $user = User::factory()->create();
    $anotherUser = User::factory()->create();

    $userOrder = Order::factory()->for($user)->create([
        'order_date' => Carbon::parse('2026-02-27'),
    ]);
    $otherOrder = Order::factory()->for($anotherUser)->create([
        'order_date' => Carbon::parse('2026-02-26'),
    ]);

    $this->actingAs($user)
        ->get(route('user.orders.index'))
        ->assertSuccessful()
        ->assertSee('Мои заказы')
        ->assertSee($userOrder->order_number)
        ->assertDontSee($otherOrder->order_number);
});

it('renders order details page for the owner', function (): void {
    $user = User::factory()->create();

    $product = createOrderProduct([
        'name' => 'Order Test Product',
        'slug' => 'order-test-product',
        'price_amount' => 120000,
    ]);

    $order = Order::factory()->for($user)->create([
        'status' => OrderStatus::Processing->value,
        'payment_status' => PaymentStatus::Awaiting->value,
        'items_subtotal' => 240000,
        'discount_total' => 0,
        'shipping_total' => 0,
        'grand_total' => 240000,
    ]);

    $order->items()->create([
        'product_id' => $product->id,
        'name' => $product->name,
        'quantity' => 2,
        'price_amount' => 120000,
        'total_amount' => 240000,
    ]);

    $this->actingAs($user)
        ->get(route('user.orders.show', orderRouteParams($order)))
        ->assertSuccessful()
        ->assertSee('Состав заказа')
        ->assertSee($order->order_number)
        ->assertSee('Order Test Product')
        ->assertSee(price(240000));
});

it('returns not found when user opens another user order page', function (): void {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $order = Order::factory()->for($owner)->create([
        'order_date' => Carbon::parse('2026-02-20'),
    ]);

    $this->actingAs($stranger)
        ->get(route('user.orders.show', orderRouteParams($order)))
        ->assertNotFound();
});

/**
 * @return array{date: string, seq: string}
 */
function orderRouteParams(Order $order): array
{
    return [
        'date' => $order->order_date->format('d-m-y'),
        'seq' => str_pad((string) $order->seq, 2, '0', STR_PAD_LEFT),
    ];
}

function createOrderProduct(array $attributes = []): Product
{
    static $sequence = 1;

    $defaults = [
        'name' => 'Order Product '.$sequence,
        'slug' => 'order-product-'.$sequence,
        'is_active' => true,
        'in_stock' => true,
        'price_amount' => 100000,
    ];

    $sequence++;

    return Product::query()->create(array_merge($defaults, $attributes));
}
