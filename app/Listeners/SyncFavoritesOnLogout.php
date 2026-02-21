<?php

namespace App\Listeners;

use App\Support\FavoritesService;
use Illuminate\Auth\Events\Logout;

class SyncFavoritesOnLogout
{
    public function __construct(private FavoritesService $favorites) {}

    public function handle(Logout $event): void
    {
        $this->favorites->syncOnLogout($event->user);
    }
}
