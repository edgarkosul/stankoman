<?php

use App\Enums\SettingType;
use App\Filament\Resources\Settings\Pages\EditSetting;
use App\Filament\Resources\Settings\SettingResource;
use App\Models\Setting;
use App\Models\User;
use Livewire\Livewire;

test('admin sees translated title on the setting edit page', function (): void {
    config([
        'settings.general.filament_admin_emails' => ['admin@example.com'],
    ]);

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
    config([
        'settings.general.filament_admin_emails' => ['admin@example.com'],
    ]);

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

test('edit setting page saves string settings into value column', function (string $key, string $field, string $value): void {
    config([
        'settings.general.filament_admin_emails' => ['admin@example.com'],
    ]);

    $admin = User::factory()->create([
        'email' => 'admin@example.com',
    ]);

    $setting = Setting::factory()->create([
        'key' => $key,
        'type' => SettingType::String,
        'value' => 'old@example.com',
        'autoload' => true,
    ]);

    $this->actingAs($admin);

    Livewire::test(EditSetting::class, [
        'record' => $setting->getRouteKey(),
    ])
        ->set("data.{$field}", $value)
        ->call('save')
        ->assertHasNoFormErrors();

    expect($setting->refresh()->value)
        ->toBe($value)
        ->and($setting->type)->toBe(SettingType::String);
})->with([
    'company legal name' => ['company.legal_name', 'legal_name_value', 'ООО Тестовая компания'],
    'company brand line' => ['company.brand_line', 'brand_line_value', 'Test Brand'],
    'company site host' => ['company.site_host', 'site_host_value', 'settings.example.com'],
    'company public email' => ['company.public_email', 'email_value', 'public@example.com'],
    'mail from address' => ['mail.from.address', 'email_value', 'mailer@example.com'],
    'company phone' => ['company.phone', 'phone_value', '+7 (999) 123-45-67'],
    'company site url' => ['company.site_url', 'site_url_value', 'https://settings.example.com'],
    'company legal addr' => ['company.legal_addr', 'legal_addr_value', 'г. Краснодар, ул. Тестовая, 10'],
    'company bank name' => ['company.bank.name', 'bank_name_value', 'Тестовый банк'],
    'company bank bik' => ['company.bank.bik', 'bank_bik_value', '012345678'],
    'company bank rs' => ['company.bank.rs', 'bank_rs_value', '40802810999999999999'],
    'company bank ks' => ['company.bank.ks', 'bank_ks_value', '30101810999999999999'],
]);
