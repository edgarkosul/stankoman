<?php

use App\Models\User;
use App\Providers\SettingsServiceProvider;
use Filament\Panel;
use Tests\TestCase;

uses(TestCase::class);

it('allows configured filament admin emails', function () {
    config()->set('settings.general.filament_admin_emails', ['admin@example.com']);
    config()->set('settings.general.manager_emails', []);

    $user = new User([
        'email' => 'admin@example.com',
    ]);

    expect($user->canAccessPanel(Panel::make()->id('admin')))->toBeTrue();
});

it('falls back to manager emails when admin list is empty', function () {
    config()->set('settings.general.filament_admin_emails', []);
    config()->set('settings.general.manager_emails', ['manager@example.com']);

    $allowedUser = new User([
        'email' => 'manager@example.com',
    ]);

    $blockedUser = new User([
        'email' => 'customer@example.com',
    ]);

    expect($allowedUser->canAccessPanel(Panel::make()->id('admin')))->toBeTrue()
        ->and($blockedUser->canAccessPanel(Panel::make()->id('admin')))->toBeFalse();
});

it('falls back to legacy filament admin config when settings lists are empty', function () {
    config()->set('settings.general.filament_admin_emails', []);
    config()->set('settings.general.manager_emails', []);
    config()->set('filament_admin.emails', ['legacy@example.com']);

    $user = new User([
        'email' => 'legacy@example.com',
    ]);

    expect($user->canAccessPanel(Panel::make()->id('admin')))->toBeTrue();
});

it('skips overriding blank email lists when bootstrapping settings config', function () {
    $provider = new SettingsServiceProvider(app());
    $method = new ReflectionMethod($provider, 'shouldSkipOverride');
    $method->setAccessible(true);

    expect($method->invoke($provider, 'general.filament_admin_emails', null))->toBeTrue()
        ->and($method->invoke($provider, 'general.filament_admin_emails', []))->toBeTrue()
        ->and($method->invoke($provider, 'general.manager_emails', ''))->toBeTrue()
        ->and($method->invoke($provider, 'general.shop_name', 'InterTooler'))->toBeFalse();
});
