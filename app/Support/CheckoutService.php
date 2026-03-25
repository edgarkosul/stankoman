<?php

namespace App\Support;

use App\Events\Orders\OrderSubmitted;
use App\Models\Order;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CheckoutService
{
    public function __construct(
        private OrderPlacementService $orderPlacement,
    ) {}

    public function submit(array $contact, array $delivery, array $review): Order
    {
        $cartService = app(CartService::class);
        $cart = $cartService->getCart();

        if ($cartService->isEmpty() || $cart->items()->count() === 0) {
            throw ValidationException::withMessages([
                'cart' => 'Невозможно оформить заказ с пустой корзиной.',
            ]);
        }

        $lockKey = 'checkout:submit:cart:'.$cart->id;

        $order = Cache::lock($lockKey, 10)->block(5, function () use ($cart, $cartService, $contact, $delivery, $review): Order {
            return DB::transaction(function () use ($cart, $cartService, $contact, $delivery, $review): Order {
                $items = $cart->items()->get();

                if ($items->isEmpty()) {
                    throw ValidationException::withMessages([
                        'cart' => 'Корзина пуста.',
                    ]);
                }

                $order = $this->orderPlacement->place(
                    items: $items
                        ->map(fn ($item): array => [
                            'product_id' => (int) $item->product_id,
                            'quantity' => (int) $item->quantity,
                        ])
                        ->all(),
                    contact: $contact,
                    delivery: $delivery,
                    review: $review,
                );

                $cartService->clear();

                return $order;
            });
        });

        event(new OrderSubmitted($order));

        return $order;
    }
}
