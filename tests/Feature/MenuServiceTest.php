<?php

use App\Models\Menu;
use App\Models\MenuItem;
use App\Support\Menu\MenuService;
use Illuminate\Support\Str;

test('menu service tree returns only active items', function () {
    $menuKey = 'menu-'.Str::random(8);

    $menu = Menu::factory()->create([
        'key' => $menuKey,
        'name' => 'Primary Menu',
    ]);

    MenuItem::factory()->create([
        'menu_id' => $menu->id,
        'label' => 'Active Item',
        'type' => 'url',
        'url' => 'https://example.com/active',
        'is_active' => true,
        'sort' => 1,
    ]);

    MenuItem::factory()->create([
        'menu_id' => $menu->id,
        'label' => 'Inactive Item',
        'type' => 'url',
        'url' => 'https://example.com/inactive',
        'is_active' => false,
        'sort' => 2,
    ]);

    $tree = app(MenuService::class)->tree($menuKey);

    expect($tree)
        ->toHaveCount(1)
        ->and($tree[0]['label'])->toBe('Active Item');
});
