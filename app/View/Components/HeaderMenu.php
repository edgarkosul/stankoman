<?php

namespace App\View\Components;

use App\Support\Menu\MenuService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\View\Component;

class HeaderMenu extends Component
{
    public Collection $links;

    public Collection $lgInlineLinks;

    public Collection $lgMoreLinks;

    public Collection $xlInlineLinks;

    public Collection $xlMoreLinks;

    public int $lgInlineCount = 2;

    public int $xlInlineCount = 4;

    private string $currentUrl;

    private string $currentPath;

    public function __construct(
        protected MenuService $menuService,
        public string $menuKey = 'primary',
    ) {
        $this->currentUrl = rtrim(url()->current(), '/');
        $this->currentPath = rtrim(parse_url($this->currentUrl, PHP_URL_PATH) ?? '/', '/');

        $this->links = collect($this->menuService->tree($this->menuKey))
            ->map(fn (array $item): array => $this->decorateItem($item))
            ->values();

        $this->lgInlineLinks = $this->links->take($this->lgInlineCount);
        $this->lgMoreLinks = $this->links->slice($this->lgInlineCount)->values();
        $this->xlInlineLinks = $this->links->take($this->xlInlineCount);
        $this->xlMoreLinks = $this->links->slice($this->xlInlineCount)->values();
    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View
    {
        return view('components.header-menu');
    }

    private function decorateItem(array $item): array
    {
        $children = collect($item['children'] ?? [])
            ->map(function (array $child): array {
                $child['is_active'] = $this->isActiveHref($child['href'] ?? null);

                return $child;
            })
            ->values()
            ->all();

        $item['children'] = $children;
        $item['is_active'] = $this->isActiveHref($item['href'] ?? null)
            || collect($children)->contains(fn (array $child): bool => $child['is_active']);

        return $item;
    }

    private function isActiveHref(?string $href): bool
    {
        if (! $href) {
            return false;
        }

        $normalizedHref = rtrim($href, '/');
        if ($normalizedHref === $this->currentUrl) {
            return true;
        }

        $hrefPath = rtrim(parse_url($normalizedHref, PHP_URL_PATH) ?? '', '/');

        return $hrefPath !== '' && $hrefPath === $this->currentPath;
    }
}
