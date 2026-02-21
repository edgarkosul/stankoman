<?php

namespace App\Listeners;

use App\Support\CartService;
use Illuminate\Auth\Events\Logout;

class CloneCartOnLogout
{
    public function __construct(protected CartService $cart) {}

    public function handle(Logout $event): void
    {
        $this->cart->cloneToGuestOnLogout($event->user);
    }
}
