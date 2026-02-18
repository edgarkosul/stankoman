<?php

namespace App\View\Components;

use App\Support\Menu\MenuService;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class FooterMenu extends Component
{
    /** @var array<int, array{label:string,href:string,target:?string,rel:?string}> */
    public array $links;

    public function __construct(
        protected MenuService $menuService,
        public string $menuKey = 'footer',
    ) {
        $this->links = collect($this->menuService->tree($this->menuKey))
            ->flatMap(fn (array $item): array => $this->collectLinks($item))
            ->values()
            ->all();
    }

    public function render(): View
    {
        return view('components.footer-menu');
    }

    /** @return array<int, array{label:string,href:string,target:?string,rel:?string}> */
    private function collectLinks(array $item): array
    {
        $links = [];
        $href = $item['href'] ?? null;

        if (is_string($href) && $href !== '') {
            $links[] = [
                'label' => (string) ($item['label'] ?? ''),
                'href' => $href,
                'target' => isset($item['target']) && is_string($item['target']) ? $item['target'] : null,
                'rel' => isset($item['rel']) && is_string($item['rel']) ? $item['rel'] : null,
            ];
        }

        foreach ($item['children'] ?? [] as $child) {
            if (is_array($child)) {
                $links = [...$links, ...$this->collectLinks($child)];
            }
        }

        return $links;
    }
}
