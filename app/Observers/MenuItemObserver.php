<?php

namespace App\Observers;

use App\Models\MenuItem;
use App\Support\Menu\MenuService;

class MenuItemObserver
{
    public function saved(MenuItem $item): void
    {
        app(MenuService::class)->forgetByMenuId($item->menu_id);
    }

    public function deleted(MenuItem $item): void
    {
        app(MenuService::class)->forgetByMenuId($item->menu_id);
    }

    public function restored(MenuItem $item): void
    {
        app(MenuService::class)->forgetByMenuId($item->menu_id);
    }
}
