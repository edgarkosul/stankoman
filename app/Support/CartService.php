<?php

namespace App\Support;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CartService
{
    protected ?Cart $cart = null;

    protected string $cookieKey = 'cart_token';

    protected int $cookieDays = 30;

    public function __construct()
    {
        $this->cart = $this->resolveCart();
    }

    public function getCart(): Cart
    {
        return $this->cart->loadMissing('items.product');
    }

    /**
     * @param  array<string, mixed>|null  $options
     */
    public function addItem(int $productId, int $quantity = 1, ?array $options = null): CartItem
    {
        $product = Product::query()->findOrFail($productId);

        $optionsKey = CartItem::makeOptionsKey($options ?? []);
        $existing = $this->cart->items()
            ->where('product_id', $productId)
            ->where('options_key', $optionsKey)
            ->first();

        if ($existing instanceof CartItem) {
            return $existing;
        }

        return DB::transaction(function () use ($product, $productId, $quantity, $options): CartItem {
            $item = $this->cart->items()->create([
                'product_id' => $productId,
                'quantity' => max(1, $quantity),
                'price_snapshot' => (float) ($product->price_final ?? $product->price_int ?? 0),
                'options' => $options,
            ]);

            $this->cart->touch();

            return $item;
        });
    }

    /**
     * @param  array<string, mixed>|null  $options
     */
    public function updateQuantity(int $productId, int $quantity, ?array $options = null): void
    {
        $optionsKey = CartItem::makeOptionsKey($options ?? []);

        $item = $this->cart->items()
            ->where('product_id', $productId)
            ->where('options_key', $optionsKey)
            ->first();

        if (! $item instanceof CartItem) {
            return;
        }

        if ($quantity <= 0) {
            $item->delete();
        } else {
            $item->update(['quantity' => $quantity]);
        }

        $this->cart->touch();
    }

    /**
     * @param  array<string, mixed>|null  $options
     */
    public function removeItem(int $productId, ?array $options = null): void
    {
        $optionsKey = CartItem::makeOptionsKey($options ?? []);

        $this->cart->items()
            ->where('product_id', $productId)
            ->where('options_key', $optionsKey)
            ->delete();

        $this->cart->touch();
    }

    public function clear(): void
    {
        $this->cart->items()->delete();
        $this->cart->touch();
    }

    public function syncWithUser(): void
    {
        if (! Auth::check()) {
            return;
        }

        $user = Auth::user();
        if (! $user instanceof User) {
            return;
        }

        DB::transaction(function () use ($user): void {
            $userCart = Cart::query()->firstOrCreate(
                ['user_id' => $user->id],
                ['token' => (string) Str::uuid()]
            );

            if ($this->cart?->is($userCart)) {
                $this->queueCookie($userCart->token);

                return;
            }

            $this->cart?->loadMissing('items');

            foreach ($this->cart?->items ?? [] as $item) {
                $existing = $userCart->items()
                    ->where('product_id', $item->product_id)
                    ->where('options_key', $item->options_key)
                    ->first();

                if ($existing instanceof CartItem) {
                    continue;
                }

                $userCart->items()->create($item->only([
                    'product_id',
                    'quantity',
                    'price_snapshot',
                    'options',
                    'options_key',
                ]));
            }

            if ($this->cart?->user_id === null) {
                $this->cart?->delete();
            }

            $this->cart = $userCart->fresh('items');
            $this->queueCookie($userCart->token);
        });
    }

    public function cloneToGuestOnLogout(?Authenticatable $user = null): void
    {
        $resolvedUser = $user instanceof User ? $user : null;

        $sourceCart = $resolvedUser
            ? Cart::query()->where('user_id', $resolvedUser->id)->with('items')->first()
            : $this->cart?->loadMissing('items');

        if (! $sourceCart instanceof Cart) {
            return;
        }

        DB::transaction(function () use ($sourceCart): void {
            $guest = Cart::query()->create([
                'token' => (string) Str::uuid(),
                'user_id' => null,
            ]);

            foreach ($sourceCart->items as $item) {
                $guest->items()->create($item->only([
                    'product_id',
                    'quantity',
                    'price_snapshot',
                    'options',
                    'options_key',
                ]));
            }

            $this->cart = $guest->fresh('items');
            $this->queueCookie($guest->token);
        });
    }

    public static function cleanup(int $days = 30): void
    {
        Cart::query()
            ->whereNull('user_id')
            ->where('updated_at', '<', now()->subDays($days))
            ->delete();
    }

    /**
     * @param  array<string, mixed>|null  $options
     */
    public function isInCart(int $productId, ?array $options = null, bool $strictOptions = true): bool
    {
        $query = $this->cart->items()->where('product_id', $productId);

        if ($strictOptions) {
            $optionsKey = CartItem::makeOptionsKey($options ?? []);
            $query->where('options_key', $optionsKey);
        }

        return $query->exists();
    }

    public function isEmpty(): bool
    {
        return ! $this->cart->items()
            ->where('quantity', '>', 0)
            ->limit(1)
            ->exists();
    }

    public function count(): int
    {
        return (int) $this->getCart()
            ->items()
            ->where('quantity', '>', 0)
            ->sum('quantity');
    }

    public function uniqueProductsCount(): int
    {
        return (int) $this->getCart()
            ->items()
            ->where('quantity', '>', 0)
            ->distinct()
            ->count('product_id');
    }

    protected function resolveCart(): Cart
    {
        if (Auth::check()) {
            $user = Auth::user();
            if ($user instanceof User) {
                $cart = Cart::query()->firstOrCreate(
                    ['user_id' => $user->id],
                    ['token' => (string) Str::uuid()]
                );

                $this->queueCookie($cart->token);

                return $cart;
            }
        }

        $token = Cookie::get($this->cookieKey);

        if (is_string($token) && $token !== '') {
            $cart = Cart::query()
                ->where('token', $token)
                ->whereNull('user_id')
                ->first();

            if ($cart instanceof Cart) {
                return $cart;
            }
        }

        $cart = Cart::query()->create(['token' => (string) Str::uuid()]);
        $this->queueCookie($cart->token);

        return $cart;
    }

    protected function queueCookie(string $token): void
    {
        Cookie::queue($this->cookieKey, $token, $this->cookieDays * 24 * 60, path: '/');
    }
}
