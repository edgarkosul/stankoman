<?php

use App\Events\Orders\OrderSubmitted;
use App\Mail\OrderSubmittedCustomerMail;
use App\Mail\OrderSubmittedManagerMail;
use App\Mail\WelcomeNoPassword;
use App\Mail\WelcomeSetPassword;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Notifications\Auth\VerifyEmailNotification;
use App\Support\CartService;
use App\Support\CheckoutService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

it('dispatches order submitted event after checkout submit', function (): void {
    $user = User::factory()->create();
    $product = createCheckoutNotificationProduct([
        'price_amount' => 100000,
        'discount_price' => 90000,
    ]);

    $this->actingAs($user);

    app(CartService::class)->addItem($product->id, 1);
    Event::fake([OrderSubmitted::class]);

    $order = app(CheckoutService::class)->submit(
        [
            'customer_name' => 'Иван Петров',
            'customer_phone' => '+79990001122',
            'customer_email' => 'ivan@example.test',
            'is_company' => false,
        ],
        [
            'shipping_method' => 'delivery',
            'shipping_city' => 'Краснодар',
        ],
        [
            'payment_method' => 'cash',
            'accept_terms' => true,
        ],
    );

    Event::assertDispatched(OrderSubmitted::class, function (OrderSubmitted $event) use ($order): bool {
        return $event->order->is($order);
    });
});

it('queues customer and manager emails on order submitted event', function (): void {
    config()->set('settings.general.manager_emails', [
        'manager.one@example.test',
        'manager.two@example.test',
    ]);

    Mail::fake();

    $user = User::factory()->create();
    $product = createCheckoutNotificationProduct([
        'name' => 'Email Product',
        'slug' => 'email-product',
        'price_amount' => 120000,
    ]);

    $order = Order::factory()->for($user)->create([
        'customer_email' => 'customer@example.test',
        'customer_name' => 'Тест Клиент',
        'customer_phone' => '+79990002233',
        'payment_method' => 'bank_transfer',
        'shipping_method' => 'delivery',
        'items_subtotal' => 120000,
        'discount_total' => 0,
        'shipping_total' => 0,
        'grand_total' => 120000,
    ]);

    $order->items()->create([
        'product_id' => $product->id,
        'sku' => $product->sku,
        'name' => $product->name,
        'quantity' => 1,
        'price_amount' => 120000,
        'total_amount' => 120000,
    ]);

    event(new OrderSubmitted($order));

    Mail::assertQueued(OrderSubmittedCustomerMail::class, function (OrderSubmittedCustomerMail $mail) use ($order): bool {
        return $mail->order->is($order) && $mail->hasTo('customer@example.test');
    });

    Mail::assertQueued(OrderSubmittedManagerMail::class, function (OrderSubmittedManagerMail $mail) use ($order): bool {
        return $mail->order->is($order)
            && $mail->hasTo('manager.one@example.test')
            && $mail->hasReplyTo('customer@example.test');
    });

    Mail::assertQueued(OrderSubmittedManagerMail::class, function (OrderSubmittedManagerMail $mail) use ($order): bool {
        return $mail->order->is($order)
            && $mail->hasTo('manager.two@example.test')
            && $mail->hasReplyTo('customer@example.test');
    });

    Mail::assertQueuedCount(3);
});

it('queues welcome set password email for newly created checkout account without verification notification', function (): void {
    Mail::fake();
    Notification::fake();

    $product = createCheckoutNotificationProduct([
        'name' => 'Checkout Welcome Product',
        'slug' => 'checkout-welcome-product',
        'price_amount' => 100000,
        'discount_price' => 90000,
    ]);

    app(CartService::class)->addItem($product->id, 1);
    Event::fake([OrderSubmitted::class]);

    app(CheckoutService::class)->submit(
        [
            'customer_name' => 'Новый клиент',
            'customer_phone' => '+79994445566',
            'customer_email' => 'welcome-new@example.test',
            'create_account' => true,
            'is_company' => false,
        ],
        [
            'shipping_method' => 'delivery',
            'shipping_city' => 'Краснодар',
        ],
        [
            'payment_method' => 'cash',
            'accept_terms' => true,
        ],
    );

    Mail::assertQueued(WelcomeSetPassword::class, function (WelcomeSetPassword $mail): bool {
        return $mail->hasTo('welcome-new@example.test')
            && $mail->resetUrl !== '';
    });

    Notification::assertNothingSent();
});

it('queues welcome no password email for existing checkout account without verification notification', function (): void {
    Mail::fake();
    Notification::fake();

    $existingUser = User::factory()->unverified()->create([
        'email' => 'welcome-existing@example.test',
    ]);

    $product = createCheckoutNotificationProduct([
        'name' => 'Checkout Existing Product',
        'slug' => 'checkout-existing-product',
        'price_amount' => 100000,
    ]);

    app(CartService::class)->addItem($product->id, 1);
    Event::fake([OrderSubmitted::class]);

    $order = app(CheckoutService::class)->submit(
        [
            'customer_name' => 'Существующий клиент',
            'customer_phone' => '+79990009988',
            'customer_email' => 'welcome-existing@example.test',
            'create_account' => true,
            'is_company' => false,
        ],
        [
            'shipping_method' => 'delivery',
            'shipping_city' => 'Краснодар',
        ],
        [
            'payment_method' => 'cash',
            'accept_terms' => true,
        ],
    );

    expect($order->user_id)->toBe($existingUser->id);

    Mail::assertQueued(WelcomeNoPassword::class, function (WelcomeNoPassword $mail) use ($existingUser): bool {
        return $mail->user->is($existingUser)
            && $mail->hasTo('welcome-existing@example.test');
    });

    Notification::assertNotSentTo($existingUser, VerifyEmailNotification::class);
});

function createCheckoutNotificationProduct(array $attributes = []): Product
{
    static $sequence = 1;

    $defaults = [
        'name' => 'Checkout Notification Product '.$sequence,
        'slug' => 'checkout-notification-product-'.$sequence,
        'is_active' => true,
        'in_stock' => true,
        'price_amount' => 100000,
    ];

    $sequence++;

    return Product::query()->create(array_merge($defaults, $attributes));
}
