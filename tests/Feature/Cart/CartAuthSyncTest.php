<?php

use App\Models\Cart;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Str;

it('merges guest cart into user cart on login and keeps user quantity on conflicts', function (): void {
    $user = User::factory()->create([
        'email' => 'cart-sync-user@example.test',
    ]);

    $conflictProduct = Product::query()->create([
        'name' => 'Товар конфликт корзины',
        'slug' => 'cart-sync-conflict-product',
        'is_active' => true,
        'in_stock' => true,
        'price_amount' => 150000,
    ]);

    $guestOnlyProduct = Product::query()->create([
        'name' => 'Товар гостевой корзины',
        'slug' => 'cart-sync-guest-only-product',
        'is_active' => true,
        'in_stock' => true,
        'price_amount' => 200000,
    ]);

    $userCart = Cart::query()->create([
        'user_id' => $user->id,
        'token' => (string) Str::uuid(),
    ]);

    $userCart->items()->create([
        'product_id' => $conflictProduct->id,
        'quantity' => 5,
        'price_snapshot' => 150000,
        'options' => [],
    ]);

    $guestCart = Cart::query()->create([
        'user_id' => null,
        'token' => (string) Str::uuid(),
    ]);

    $guestCart->items()->create([
        'product_id' => $conflictProduct->id,
        'quantity' => 2,
        'price_snapshot' => 150000,
        'options' => [],
    ]);

    $guestCart->items()->create([
        'product_id' => $guestOnlyProduct->id,
        'quantity' => 3,
        'price_snapshot' => 200000,
        'options' => [],
    ]);

    $response = $this
        ->withCookie('cart_token', $guestCart->token)
        ->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

    $response->assertSessionHasNoErrors();
    $this->assertAuthenticatedAs($user);

    $syncedUserCart = Cart::query()
        ->where('user_id', $user->id)
        ->firstOrFail();

    expect($syncedUserCart->items()->count())->toBe(2)
        ->and((int) $syncedUserCart->items()->where('product_id', $conflictProduct->id)->value('quantity'))->toBe(5)
        ->and((int) $syncedUserCart->items()->where('product_id', $guestOnlyProduct->id)->value('quantity'))->toBe(3);

    $response->assertCookie('cart_token', $syncedUserCart->token);

    $this->assertDatabaseMissing('carts', [
        'id' => $guestCart->id,
    ]);
});

it('clones user cart to guest cart on logout and updates cart cookie', function (): void {
    $user = User::factory()->create();

    $product = Product::query()->create([
        'name' => 'Товар для logout синхронизации',
        'slug' => 'cart-sync-logout-product',
        'is_active' => true,
        'in_stock' => true,
        'price_amount' => 110000,
    ]);

    $userCart = Cart::query()->create([
        'user_id' => $user->id,
        'token' => (string) Str::uuid(),
    ]);

    $userCart->items()->create([
        'product_id' => $product->id,
        'quantity' => 4,
        'price_snapshot' => 110000,
        'options' => [],
    ]);

    $response = $this->actingAs($user)->post(route('logout'));

    $response->assertRedirect(route('home'));
    $this->assertGuest();

    $guestCart = Cart::query()->whereNull('user_id')->latest('id')->first();

    expect($guestCart)->toBeInstanceOf(Cart::class)
        ->and($guestCart?->items()->count())->toBe(1)
        ->and((int) $guestCart?->items()->value('quantity'))->toBe(4);

    $response->assertCookie('cart_token', $guestCart?->token);

    $this->assertDatabaseHas('carts', [
        'id' => $userCart->id,
        'user_id' => $user->id,
    ]);
});
