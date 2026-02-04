<?php

namespace App\Observers;

use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Page;
use App\Support\Menu\MenuService;

class PageObserver
{
    public function saving(Page $page): void
    {
        // Если slug меняется — надо забыть кеш уже сейчас (на случай, если где-то читают до saved)
        if ($page->exists && $page->isDirty('slug')) {
            $this->forgetMenusThatReference($page);
        }
    }

    public function saved(Page $page): void
    {
        $this->forgetMenusThatReference($page);
    }

    public function deleted(Page $page): void
    {
        $this->forgetMenusThatReference($page);
    }

    private function forgetMenusThatReference(Page $page): void
    {
        $menuIds = MenuItem::query()
            ->where('type', 'page')
            ->where('page_id', $page->id)
            ->distinct()
            ->pluck('menu_id')
            ->all();

        if (! count($menuIds)) {
            return;
        }

        $keys = Menu::query()->whereIn('id', $menuIds)->pluck('key')->all();

        $service = app(MenuService::class);
        foreach ($keys as $key) {
            $service->forget($key);
        }
    }
}
