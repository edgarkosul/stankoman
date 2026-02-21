<?php

namespace App\Listeners;

use App\Support\FavoritesService;
use Illuminate\Auth\Events\Login;

class SyncFavoritesOnLogin
{
    public function __construct(private FavoritesService $favorites) {}

    public function handle(Login $event): void
    {
        $this->favorites->syncOnLogin($event->user);
    }
}
