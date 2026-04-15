<?php

use App\Filament\Resources\Menus\MenuResource;
use App\Models\Menu;
use App\Models\User;

test('builder menu page loads for an existing menu', function () {
    config(['app.env' => 'local']);
    config()->set('settings.general.filament_admin_emails', ['admin@example.com']);
    config()->set('settings.general.manager_emails', []);

    $user = User::factory()->create([
        'email' => 'admin@example.com',
    ]);

    $menu = Menu::query()->create([
        'key' => 'test-menu',
        'name' => 'Test Menu',
    ]);

    $this->actingAs($user)
        ->get(MenuResource::getUrl('builder', ['record' => $menu]))
        ->assertSuccessful();
});
