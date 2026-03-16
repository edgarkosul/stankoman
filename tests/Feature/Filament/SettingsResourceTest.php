<?php

use App\Enums\SettingType;
use App\Filament\Resources\Settings\Pages\EditSetting;
use App\Filament\Resources\Settings\SettingResource;
use App\Models\Setting;
use App\Models\User;
use Livewire\Livewire;

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

test('settings global search only uses persisted columns', function (): void {
    expect(SettingResource::getGloballySearchableAttributes())
        ->toBe(['key'])
        ->not->toContain('translated_key');
});

test('edit setting page saves manager emails repeater into json value', function (): void {
    config(['filament_admin.emails' => ['admin@example.com']]);

    $admin = User::factory()->create([
        'email' => 'admin@example.com',
    ]);

    $setting = Setting::factory()->create([
        'key' => 'general.manager_emails',
        'type' => SettingType::Json,
        'value' => json_encode(['old@example.com'], JSON_UNESCAPED_UNICODE),
        'autoload' => true,
    ]);

    $this->actingAs($admin);

    Livewire::test(EditSetting::class, [
        'record' => $setting->getRouteKey(),
    ])
        ->set('data.manager_emails', [
            ['email' => 'first@example.com'],
            ['email' => 'second@example.com'],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $setting->refresh();

    expect($setting->value)
        ->toBe(json_encode([
            'first@example.com',
            'second@example.com',
        ], JSON_UNESCAPED_UNICODE))
        ->and($setting->type)->toBe(SettingType::Json);
});
