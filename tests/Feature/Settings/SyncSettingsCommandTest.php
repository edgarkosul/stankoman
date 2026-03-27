<?php

use App\Enums\SettingType;
use App\Models\Setting;
use App\Providers\SettingsServiceProvider;

it('syncs settings from config into database', function (): void {
    expect(Setting::query()->count())->toBe(0);

    $this->artisan('settings:sync')
        ->assertSuccessful();

    $setting = Setting::query()->where('key', 'product.stavka_nds')->first();
    $legalNameSetting = Setting::query()->where('key', 'company.legal_name')->first();
    $brandLineSetting = Setting::query()->where('key', 'company.brand_line')->first();
    $siteHostSetting = Setting::query()->where('key', 'company.site_host')->first();
    $phoneSetting = Setting::query()->where('key', 'company.phone')->first();
    $siteUrlSetting = Setting::query()->where('key', 'company.site_url')->first();
    $publicEmailSetting = Setting::query()->where('key', 'company.public_email')->first();
    $legalAddressSetting = Setting::query()->where('key', 'company.legal_addr')->first();
    $bankNameSetting = Setting::query()->where('key', 'company.bank.name')->first();
    $bankBikSetting = Setting::query()->where('key', 'company.bank.bik')->first();
    $bankRsSetting = Setting::query()->where('key', 'company.bank.rs')->first();
    $bankKsSetting = Setting::query()->where('key', 'company.bank.ks')->first();
    $mailFromAddressSetting = Setting::query()->where('key', 'mail.from.address')->first();

    expect($setting)->toBeInstanceOf(Setting::class)
        ->and($setting->type)->toBe(SettingType::Int)
        ->and($setting->value)->toBe((string) config('settings.product.stavka_nds'))
        ->and($legalNameSetting)->toBeInstanceOf(Setting::class)
        ->and($legalNameSetting->type)->toBe(SettingType::String)
        ->and($legalNameSetting->value)->toBe((string) config('settings.company.legal_name'))
        ->and($brandLineSetting)->toBeInstanceOf(Setting::class)
        ->and($brandLineSetting->type)->toBe(SettingType::String)
        ->and($brandLineSetting->value)->toBe((string) config('settings.company.brand_line'))
        ->and($siteHostSetting)->toBeInstanceOf(Setting::class)
        ->and($siteHostSetting->type)->toBe(SettingType::String)
        ->and($siteHostSetting->value)->toBe((string) config('settings.company.site_host'))
        ->and($phoneSetting)->toBeInstanceOf(Setting::class)
        ->and($phoneSetting->type)->toBe(SettingType::String)
        ->and($phoneSetting->value)->toBe((string) config('settings.company.phone'))
        ->and($siteUrlSetting)->toBeInstanceOf(Setting::class)
        ->and($siteUrlSetting->type)->toBe(SettingType::String)
        ->and($siteUrlSetting->value)->toBe((string) config('settings.company.site_url'))
        ->and($publicEmailSetting)->toBeInstanceOf(Setting::class)
        ->and($publicEmailSetting->type)->toBe(SettingType::String)
        ->and($publicEmailSetting->value)->toBe((string) config('settings.company.public_email'))
        ->and($legalAddressSetting)->toBeInstanceOf(Setting::class)
        ->and($legalAddressSetting->type)->toBe(SettingType::String)
        ->and($legalAddressSetting->value)->toBe((string) config('settings.company.legal_addr'))
        ->and($bankNameSetting)->toBeInstanceOf(Setting::class)
        ->and($bankNameSetting->type)->toBe(SettingType::String)
        ->and($bankNameSetting->value)->toBe((string) config('settings.company.bank.name'))
        ->and($bankBikSetting)->toBeInstanceOf(Setting::class)
        ->and($bankBikSetting->type)->toBe(SettingType::String)
        ->and($bankBikSetting->value)->toBe((string) config('settings.company.bank.bik'))
        ->and($bankRsSetting)->toBeInstanceOf(Setting::class)
        ->and($bankRsSetting->type)->toBe(SettingType::String)
        ->and($bankRsSetting->value)->toBe((string) config('settings.company.bank.rs'))
        ->and($bankKsSetting)->toBeInstanceOf(Setting::class)
        ->and($bankKsSetting->type)->toBe(SettingType::String)
        ->and($bankKsSetting->value)->toBe((string) config('settings.company.bank.ks'))
        ->and($mailFromAddressSetting)->toBeInstanceOf(Setting::class)
        ->and($mailFromAddressSetting->type)->toBe(SettingType::String)
        ->and($mailFromAddressSetting->value)->toBe((string) config('settings.mail.from.address'));
});

it('overrides existing values only with --force option', function (): void {
    Setting::query()->create([
        'key' => 'product.stavka_nds',
        'value' => '99',
        'type' => SettingType::Int,
        'autoload' => true,
    ]);

    $this->artisan('settings:sync')
        ->assertSuccessful();

    $withoutForce = Setting::query()->where('key', 'product.stavka_nds')->first();

    expect($withoutForce)->toBeInstanceOf(Setting::class)
        ->and($withoutForce->value)->toBe('99');

    $this->artisan('settings:sync --force')
        ->assertSuccessful();

    $withForce = Setting::query()->where('key', 'product.stavka_nds')->first();

    expect($withForce)->toBeInstanceOf(Setting::class)
        ->and($withForce->value)->toBe((string) config('settings.product.stavka_nds'));
});

it('loads autoload settings into config via provider', function (): void {
    Setting::query()->create([
        'key' => 'product.stavka_nds',
        'value' => '18',
        'type' => SettingType::Int,
        'autoload' => true,
    ]);
    Setting::query()->create([
        'key' => 'company.legal_name',
        'value' => 'ООО Тестовая компания',
        'type' => SettingType::String,
        'autoload' => true,
    ]);
    Setting::query()->create([
        'key' => 'company.brand_line',
        'value' => 'Test Brand',
        'type' => SettingType::String,
        'autoload' => true,
    ]);
    Setting::query()->create([
        'key' => 'company.site_host',
        'value' => 'settings.example.com',
        'type' => SettingType::String,
        'autoload' => true,
    ]);
    Setting::query()->create([
        'key' => 'company.public_email',
        'value' => 'public@example.com',
        'type' => SettingType::String,
        'autoload' => true,
    ]);
    Setting::query()->create([
        'key' => 'company.phone',
        'value' => '+7 (999) 123-45-67',
        'type' => SettingType::String,
        'autoload' => true,
    ]);
    Setting::query()->create([
        'key' => 'company.site_url',
        'value' => 'https://settings.example.com',
        'type' => SettingType::String,
        'autoload' => true,
    ]);
    Setting::query()->create([
        'key' => 'company.legal_addr',
        'value' => 'г. Краснодар, ул. Тестовая, 10',
        'type' => SettingType::String,
        'autoload' => true,
    ]);
    Setting::query()->create([
        'key' => 'company.bank.name',
        'value' => 'Тестовый банк',
        'type' => SettingType::String,
        'autoload' => true,
    ]);
    Setting::query()->create([
        'key' => 'company.bank.bik',
        'value' => '012345678',
        'type' => SettingType::String,
        'autoload' => true,
    ]);
    Setting::query()->create([
        'key' => 'company.bank.rs',
        'value' => '40802810999999999999',
        'type' => SettingType::String,
        'autoload' => true,
    ]);
    Setting::query()->create([
        'key' => 'company.bank.ks',
        'value' => '30101810999999999999',
        'type' => SettingType::String,
        'autoload' => true,
    ]);
    Setting::query()->create([
        'key' => 'mail.from.address',
        'value' => 'mailer@example.com',
        'type' => SettingType::String,
        'autoload' => true,
    ]);

    Setting::flushCache();

    config()->set('settings.product.stavka_nds', 0);
    config()->set('company.legal_name', '');
    config()->set('company.brand_line', '');
    config()->set('company.site_host', '');
    config()->set('company.public_email', 'fallback@example.com');
    config()->set('company.phone', '');
    config()->set('company.site_url', '');
    config()->set('company.legal_addr', '');
    config()->set('company.bank.name', '');
    config()->set('company.bank.bik', '');
    config()->set('company.bank.rs', '');
    config()->set('company.bank.ks', '');
    config()->set('mail.from.address', 'fallback-mailer@example.com');

    (new SettingsServiceProvider(app()))->boot();

    expect(config('settings.product.stavka_nds'))->toBe(18)
        ->and(config('company.legal_name'))->toBe('ООО Тестовая компания')
        ->and(config('company.brand_line'))->toBe('Test Brand')
        ->and(config('company.site_host'))->toBe('settings.example.com')
        ->and(config('company.public_email'))->toBe('public@example.com')
        ->and(config('company.phone'))->toBe('+7 (999) 123-45-67')
        ->and(config('company.site_url'))->toBe('https://settings.example.com')
        ->and(config('company.legal_addr'))->toBe('г. Краснодар, ул. Тестовая, 10')
        ->and(config('company.bank.name'))->toBe('Тестовый банк')
        ->and(config('company.bank.bik'))->toBe('012345678')
        ->and(config('company.bank.rs'))->toBe('40802810999999999999')
        ->and(config('company.bank.ks'))->toBe('30101810999999999999')
        ->and(config('mail.from.address'))->toBe('mailer@example.com');
});

it('does not sync runtime-only settings keys that are absent from config file', function (): void {
    config()->set('settings.general.notification_email', 'legacy@example.com');

    $this->artisan('settings:sync')
        ->assertSuccessful();

    expect(Setting::query()->where('key', 'general.notification_email')->exists())->toBeFalse();
});
