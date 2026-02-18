<?php

use App\Support\Menu\MenuService;
use App\View\Components\FooterMenu;

test('footer menu flattens menu tree into one-level links', function (): void {
    $menuService = new class extends MenuService
    {
        public ?string $receivedKey = null;

        public function tree(string $menuKey): array
        {
            $this->receivedKey = $menuKey;

            return [
                [
                    'id' => 1,
                    'label' => 'Section',
                    'href' => null,
                    'target' => null,
                    'rel' => null,
                    'children' => [
                        [
                            'id' => 2,
                            'label' => 'Delivery',
                            'href' => '/delivery',
                            'target' => null,
                            'rel' => null,
                            'children' => [],
                        ],
                    ],
                ],
                [
                    'id' => 3,
                    'label' => 'Contacts',
                    'href' => '/contacts',
                    'target' => '_blank',
                    'rel' => 'noopener noreferrer',
                    'children' => [],
                ],
            ];
        }
    };

    $component = new FooterMenu($menuService, 'footer');

    expect($menuService->receivedKey)->toBe('footer')
        ->and($component->links)->toBe([
            [
                'label' => 'Delivery',
                'href' => '/delivery',
                'target' => null,
                'rel' => null,
            ],
            [
                'label' => 'Contacts',
                'href' => '/contacts',
                'target' => '_blank',
                'rel' => 'noopener noreferrer',
            ],
        ]);
});
