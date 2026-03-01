<?php

namespace App\Listeners;

use App\Support\CartService;
use Illuminate\Auth\Events\Login;

class SyncCartOnLogin
{
    public function __construct(protected CartService $cart) {}

    public function handle(Login $event): void
    {
        $token = request()->cookie('cart_token');

        $this->cart->syncWithUser(
            user: $event->user,
            guestToken: is_string($token) ? $token : null,
        );
    }
}
