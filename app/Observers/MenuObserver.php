<?php

namespace App\Observers;

use App\Models\Menu;
use App\Support\Menu\MenuService;

class MenuObserver
{
    public function updating(Menu $menu): void
    {
        if ($menu->isDirty('key')) {
            $oldKey = $menu->getOriginal('key');
            if ($oldKey) {
                app(MenuService::class)->forget($oldKey);
            }
        }
    }

    public function saved(Menu $menu): void
    {
        app(MenuService::class)->forget($menu->key);
    }

    public function deleted(Menu $menu): void
    {
        app(MenuService::class)->forget($menu->key);
    }
}
