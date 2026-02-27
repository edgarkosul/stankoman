<?php

use App\Enums\SettingType;
use App\Models\Setting;
use App\Providers\SettingsServiceProvider;

it('syncs settings from config into database', function (): void {
    expect(Setting::query()->count())->toBe(0);

    $this->artisan('settings:sync')
        ->assertSuccessful();

    $setting = Setting::query()->where('key', 'product.stavka_nds')->first();

    expect($setting)->toBeInstanceOf(Setting::class)
        ->and($setting->type)->toBe(SettingType::Int)
        ->and($setting->value)->toBe((string) config('settings.product.stavka_nds'));
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

    Setting::flushCache();

    config()->set('settings.product.stavka_nds', 0);

    (new SettingsServiceProvider(app()))->boot();

    expect(config('settings.product.stavka_nds'))->toBe(18);
});
