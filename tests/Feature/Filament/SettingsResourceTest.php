<?php

use App\Enums\SettingType;
use App\Filament\Resources\Settings\SettingResource;
use App\Models\Setting;
use App\Models\User;

test('admin sees translated title on the setting edit page', function (): void {
    config(['filament_admin.emails' => ['admin@example.com']]);

    $admin = User::factory()->create([
        'email' => 'admin@example.com',
    ]);

    $setting = Setting::factory()->create([
        'key' => 'general.filament_admin_emails',
        'type' => SettingType::Json,
        'value' => json_encode(['admin@example.com'], JSON_UNESCAPED_UNICODE),
    ]);

    $this->actingAs($admin)
        ->get(SettingResource::getUrl('edit', ['record' => $setting], panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Адреса админов Filament');
});
