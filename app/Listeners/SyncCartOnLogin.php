<?php

namespace App\Listeners;

use App\Support\CartService;
use Illuminate\Auth\Events\Login;

class SyncCartOnLogin
{
    public function __construct(protected CartService $cart) {}

    public function handle(Login $event): void
    {
        $this->cart->syncWithUser();
    }
}
