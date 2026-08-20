<?php

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\Products\ProductEcommerceDataBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('builds ecommerce payloads and schema from the same product snapshot', function (): void {
    $root = Category::query()->create([
        'name' => 'Каталог',
        'slug' => 'catalog',
        'parent_id' => Category::defaultParentKey(),
        'order' => 1,
        'is_active' => true,
    ]);
    $leaf = Category::query()->create([
        'name' => 'Токарные станки',
        'slug' => 'turning-machines',
        'parent_id' => $root->id,
        'order' => 1,
        'is_active' => true,
    ]);

    $product = Product::query()->create([
        'name' => 'Токарный станок TEST-700',
        'slug' => 'tokarnyj-stanok-test-700',
        'sku' => 'TEST-700',
        'brand' => 'Stankoman',
        'price_amount' => 150000,
        'discount_price' => 135000,
        'is_active' => true,
        'in_stock' => true,
    ]);
    $product->categories()->attach($leaf->id, ['is_primary' => true]);

    $builder = app(ProductEcommerceDataBuilder::class);

    $detailPayload = $builder->detailPayload($product->fresh('categories'));
    $addPayload = $builder->addToCartPayload($product->fresh('categories'), 3);
    $schema = $builder->productSchema(
        $product->fresh('categories'),
        images: ['https://example.test/images/test-700.webp'],
        description: 'Описание станка',
        url: 'https://example.test/product/tokarnyj-stanok-test-700',
    );

    expect($detailPayload)->toMatchArray([
        'currencyCode' => 'RUB',
        'detail' => [
            'products' => [[
                'id' => 'TEST-700',
                'name' => 'Токарный станок TEST-700',
                'price' => 150000,
                'brand' => 'Stankoman',
                'category' => 'Каталог / Токарные станки',
                'quantity' => 1,
            ]],
        ],
    ])->and($addPayload['currencyCode'])->toBe('RUB')
        ->and($addPayload['add']['products'][0]['id'])->toBe('TEST-700')
        ->and($addPayload['add']['products'][0]['quantity'])->toBe(3)
        ->and($schema)->toMatchArray([
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => 'Токарный станок TEST-700',
            'description' => 'Описание станка',
            'image' => ['https://example.test/images/test-700.webp'],
            'sku' => 'TEST-700',
            'brand' => [
                '@type' => 'Brand',
                'name' => 'Stankoman',
            ],
            'category' => 'Каталог / Токарные станки',
            'offers' => [
                '@type' => 'Offer',
                'priceCurrency' => 'RUB',
                'price' => '150000',
                'availability' => 'https://schema.org/InStock',
                'url' => 'https://example.test/product/tokarnyj-stanok-test-700',
            ],
        ]);
});

it('builds purchase payload from order item snapshots', function (): void {
    $order = Order::query()->create([
        'status' => 'submitted',
        'payment_status' => 'awaiting',
        'customer_name' => 'Иван Петров',
        'customer_phone' => '+79990001122',
        'shipping_method' => 'delivery',
        'items_subtotal' => 270000,
        'discount_total' => 15000,
        'shipping_total' => 0,
        'grand_total' => 255000,
        'submitted_at' => now(),
    ]);

    $order->items()->create([
        'product_id' => null,
        'sku' => 'TEST-900',
        'name' => 'Станок TEST-900',
        'quantity' => 2,
        'price_amount' => 127500,
        'meta' => [
            'analytics_id' => 'TEST-900',
            'brand' => 'Stankoman',
            'category_path' => 'Каталог / Фрезерные станки',
        ],
    ]);

    $payload = app(ProductEcommerceDataBuilder::class)->purchasePayload($order->fresh('items'));

    expect($payload)->toMatchArray([
        'currencyCode' => 'RUB',
        'purchase' => [
            'actionField' => [
                'id' => $order->order_number,
                'revenue' => 255000.0,
            ],
            'products' => [[
                'id' => 'TEST-900',
                'name' => 'Станок TEST-900',
                'price' => 127500.0,
                'brand' => 'Stankoman',
                'category' => 'Каталог / Фрезерные станки',
                'quantity' => 2,
            ]],
        ],
    ]);
});

it('uses the discounted price in analytics for authenticated customers', function (): void {
    $product = Product::query()->create([
        'name' => 'Станок со скидкой',
        'slug' => 'stanok-so-skidkoj',
        'sku' => 'DISC-700',
        'price_amount' => 150000,
        'discount_price' => 135000,
        'in_stock' => true,
    ]);

    $builder = app(ProductEcommerceDataBuilder::class);

    // Гость видит обычную цену.
    expect($builder->productLineItem($product)['price'])->toBe(150000);

    $this->actingAs(User::factory()->create());

    // Зарегистрированный — цену со скидкой.
    expect($builder->productLineItem($product)['price'])->toBe(135000);
});
