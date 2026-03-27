<?php

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\Users\RelationManagers\OrdersRelationManager;
use App\Filament\Resources\Users\UserResource;
use App\Models\Order;
use App\Models\User;

test('user and order resources register relationship managers', function (): void {
    expect(UserResource::getRelations())
        ->toContain(OrdersRelationManager::class)
        ->and(OrderResource::getRelations())
        ->toContain(ItemsRelationManager::class);
});

test('admin can open user and order resources with relationship sections', function (): void {
    config([
        'settings.general.filament_admin_emails' => ['admin@example.com'],
    ]);

    $admin = User::factory()->create([
        'email' => 'admin@example.com',
    ]);

    $order = Order::factory()->for($admin)->create();

    $order->items()->create([
        'name' => 'Тестовая позиция заказа',
        'quantity' => 2,
        'price_amount' => 1500,
        'total_amount' => 3000,
    ]);

    $this->actingAs($admin)
        ->get(UserResource::getUrl('index', panel: 'admin'))
        ->assertSuccessful();

    $this->actingAs($admin)
        ->get(UserResource::getUrl('edit', ['record' => $admin], panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Заказы');

    $this->actingAs($admin)
        ->get(OrderResource::getUrl('index', panel: 'admin'))
        ->assertSuccessful();

    $this->actingAs($admin)
        ->get(OrderResource::getUrl('view', ['record' => $order], panel: 'admin'))
        ->assertSuccessful()
        ->assertSee($order->order_number);
});
