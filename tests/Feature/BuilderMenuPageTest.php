<?php

use App\Filament\Resources\Menus\MenuResource;
use App\Models\Menu;
use App\Models\User;

test('builder menu page loads for an existing menu', function () {
    config(['app.env' => 'local']);

    $user = User::factory()->create();

    $menu = Menu::query()->create([
        'key' => 'test-menu',
        'name' => 'Test Menu',
    ]);

    $this->actingAs($user)
        ->get(MenuResource::getUrl('builder', ['record' => $menu]))
        ->assertSuccessful();
});
