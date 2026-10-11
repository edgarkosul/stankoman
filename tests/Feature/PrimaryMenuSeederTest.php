<?php

use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Page;
use Database\Seeders\PrimaryMenuSeeder;
use Illuminate\Support\Facades\Queue;

test('primary menu seeder creates pages and menu items', function () {
    // Сидер заводит «Производство» и «Сервис» — они в базе знаний бота
    // и ставят переиндексацию на redis-assistant мимо QUEUE_CONNECTION=sync.
    Queue::fake();

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
