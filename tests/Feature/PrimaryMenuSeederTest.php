<?php

use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Page;
use Database\Seeders\PrimaryMenuSeeder;

test('primary menu seeder creates pages and menu items', function () {
    $this->seed(PrimaryMenuSeeder::class);

    $menu = Menu::query()->where('key', 'primary')->first();

    expect($menu)->not->toBeNull();

    $rootItems = MenuItem::query()
        ->where('menu_id', $menu->id)
        ->whereNull('parent_id')
        ->get();

    expect($rootItems)->not->toBeEmpty();

    $page = Page::query()->where('slug', 'delivery')->first();

    expect($page)->not->toBeNull();

    $menuItem = $rootItems->firstWhere('label', 'Оформление и доставка');

    expect($menuItem)->not->toBeNull()
        ->and($menuItem->type)->toBe('page')
        ->and($menuItem->page_id)->toBe($page->id);
});
