<?php

namespace App\Http\Middleware;

use App\Support\CartService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CartNotEmpty
{
    public function handle(Request $request, Closure $next): Response
    {
        $cart = app(CartService::class);

        if ($cart->isEmpty()) {
            return redirect()
                ->route('cart.index')
                ->with('warning', 'Корзина пуста. Добавьте товары перед оформлением заказа.');
        }

        return $next($request);
    }
}
