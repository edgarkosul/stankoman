<?php

namespace App\Support\Menu;

use App\Models\Menu;
use App\Models\MenuItem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

class MenuService
{
    public function tree(string $menuKey): array
    {
        return Cache::rememberForever($this->cacheKey($menuKey), function () use ($menuKey) {
            $menu = Menu::query()->where('key', $menuKey)->first();

            if (! $menu) {
                return [];
            }

            // Берём только активные пункты. Сразу подгружаем страницу (slug) для type=page.
            $items = MenuItem::query()
                ->where('menu_id', $menu->id)
                ->active()
                ->orderByRaw('parent_id is not null') // сначала root (parent_id=null), затем дети
                ->orderBy('parent_id')
                ->orderBy('sort')
                ->with(['page:id,slug,is_published'])
                ->get([
                    'id', 'menu_id', 'parent_id',
                    'label', 'type',
                    'url', 'route_name', 'route_params', 'page_id',
                    'target', 'rel',
                ]);

            $nodes = [];
            $roots = [];

            foreach ($items as $item) {
                // Ограничение 2 уровней на чтении:
                // если родитель сам НЕ root (т.е. parent_id у родителя не null) — пропускаем "3-й уровень".
                if ($item->parent_id && isset($nodes[$item->parent_id]) && $nodes[$item->parent_id]['parent_id'] !== null) {
                    continue;
                }

                // Если пункт ссылается на page, но страница не опубликована/не найдена — лучше скрыть (иначе 404).
                if ($item->type === 'page') {
                    if (! $item->page || ! $item->page->is_published) {
                        continue;
                    }
                }

                $node = [
                    'id' => $item->id,
                    'parent_id' => $item->parent_id,
                    'label' => $item->label,
                    'href' => $this->makeHref($item),
                    'target' => $item->target,
                    'rel' => $this->normalizeRel($item->target, $item->rel),
                    'children' => [],
                ];

                $nodes[$item->id] = $node;

                if ($item->parent_id && isset($nodes[$item->parent_id])) {
                    $nodes[$item->parent_id]['children'][] = &$nodes[$item->id];
                } else {
                    $roots[] = &$nodes[$item->id];
                }
            }

            // Убираем parent_id из публичной структуры (если не нужно — можно оставить)
            return array_map(fn ($n) => $this->stripInternal($n), $roots);
        });
    }

    public function forget(string $menuKey): void
    {
        Cache::forget($this->cacheKey($menuKey));
    }

    public function forgetByMenuId(int $menuId): void
    {
        $key = Menu::query()->whereKey($menuId)->value('key');
        if ($key) {
            $this->forget($key);
        }
    }

    private function cacheKey(string $menuKey): string
    {
        return "menu:{$menuKey}:tree:v1";
    }

    private function makeHref(MenuItem $item): string
    {
        return match ($item->type) {
            'url' => $item->url ?: '#',

            'route' => $this->makeRouteHref($item),

            'page' => $this->makePageHref($item),

            default => '#',
        };
    }

    private function makeRouteHref(MenuItem $item): string
    {
        if (! $item->route_name) {
            return '#';
        }

        if (! Route::has($item->route_name)) {
            return '#';
        }

        $params = is_array($item->route_params) ? $item->route_params : [];

        try {
            return route($item->route_name, $params);
        } catch (\Throwable $e) {
            return '#';
        }
    }

    private function makePageHref(MenuItem $item): string
    {
        $slug = $item->page?->slug;

        if (! $slug) {
            return '#';
        }

        // На случай если кто-то забудет роут page.show
        if (Route::has('page.show')) {
            return route('page.show', $slug);
        }

        return "/page/{$slug}";
    }

    private function normalizeRel(?string $target, ?string $rel): ?string
    {
        $rel = trim((string) $rel);
        $target = $target ? trim($target) : null;

        if ($target !== '_blank') {
            return $rel !== '' ? $rel : null;
        }

        // Для _blank добавим noopener/noreferrer, если пользователь не указал
        $tokens = preg_split('/\s+/', $rel !== '' ? $rel : '', -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $set = array_fill_keys($tokens, true);

        $set['noopener'] = true;
        $set['noreferrer'] = true;

        $out = implode(' ', array_keys($set));

        return $out !== '' ? $out : null;
    }

    private function stripInternal(array $node): array
    {
        return [
            'id' => $node['id'],
            'label' => $node['label'],
            'href' => $node['href'],
            'target' => $node['target'],
            'rel' => $node['rel'],
            'children' => array_map(fn ($c) => $this->stripInternal($c), $node['children']),
        ];
    }
}
