<?php

namespace App\Listeners;

use App\Support\CartService;
use Illuminate\Auth\Events\Login;

class SyncCartOnLogin
{
    public const CHECKOUT_SYNC_MODE_SESSION_KEY = 'checkout.cart_sync_mode';

    public function __construct(protected CartService $cart) {}

    public function handle(Login $event): void
    {
        $token = request()->cookie('cart_token');
        $mode = session()->pull(self::CHECKOUT_SYNC_MODE_SESSION_KEY);

        $this->cart->syncWithUser(
            user: $event->user,
            guestToken: is_string($token) ? $token : null,
            mode: $mode === CartService::SYNC_MODE_PRESERVE_GUEST
                ? CartService::SYNC_MODE_PRESERVE_GUEST
                : CartService::SYNC_MODE_MERGE,
        );
    }
}
