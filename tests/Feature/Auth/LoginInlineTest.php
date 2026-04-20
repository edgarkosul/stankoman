<?php

use App\Livewire\Auth\LoginInline;
use App\Livewire\Header\UserMenu;
use App\Models\User;
use Livewire\Livewire;

test('inline login modal can be opened', function () {
    Livewire::test(LoginInline::class)
        ->assertSet('open', false)
        ->call('open')
        ->assertSet('open', true);
});

test('inline login modal markup is guarded by alpine template and contains auth fields', function () {
    Livewire::test(LoginInline::class)
        ->assertSeeHtml('x-cloak')
        ->assertSeeHtml('x-if="open"')
        ->assertSeeHtml('x-on:click.self="$wire.close()"')
        ->assertSeeHtml('wire:submit="login"')
        ->assertSeeHtml('autocomplete="email"')
        ->assertSeeHtml('x-ref="inlineLoginEmail"')
        ->assertSeeHtml('id="inline-login-email"')
        ->assertSeeHtml('id="inline-login-password"')
        ->assertSeeHtml('data-test="login-inline-button"')
        ->assertDontSeeHtml('@click.stop');
});

test('users can authenticate using inline login modal', function () {
    $user = User::factory()->create();
    $referer = rtrim((string) config('app.url', 'http://localhost'), '/').'/cart';

    $response = Livewire::withHeaders([
        'Referer' => $referer,
    ])->test(LoginInline::class)
        ->call('open')
        ->set('email', $user->email)
        ->set('password', 'password')
        ->set('remember', true)
        ->call('login');

    $response
        ->assertHasNoErrors()
        ->assertSet('open', false)
        ->assertNotDispatched('auth:logged-in')
        ->assertDispatched('auth:redirect');

    $this->assertAuthenticatedAs($user);
});

test('unverified users are redirected to verify email modal after inline login', function () {
    $user = User::factory()->unverified()->create();

    $response = Livewire::test(LoginInline::class)
        ->call('open')
        ->set('email', $user->email)
        ->set('password', 'password')
        ->call('login');

    $response
        ->assertHasNoErrors()
        ->assertSet('open', false)
        ->assertDispatched('auth:redirect', url: route('verification.notice', absolute: false))
        ->assertNotDispatched('auth:logged-in')
        ->assertNotDispatched('showVerifyEmailModal');

    $this->assertAuthenticatedAs($user);
});

test('users can not authenticate via inline login modal with invalid password', function () {
    $user = User::factory()->create();

    $response = Livewire::test(LoginInline::class)
        ->set('email', $user->email)
        ->set('password', 'wrong-password')
        ->call('login');

    $response
        ->assertHasErrors('email')
        ->assertNotDispatched('auth:redirect');

    $this->assertGuest();
});

test('filament admins can not authenticate using inline login modal', function () {
    config()->set('settings.general.filament_admin_emails', ['admin@example.com']);

    $user = User::factory()->create([
        'email' => 'admin@example.com',
    ]);

    Livewire::test(LoginInline::class)
        ->set('email', $user->email)
        ->set('password', 'password')
        ->call('login')
        ->assertHasErrors('email')
        ->assertNotDispatched('auth:redirect');

    $this->assertGuest();
});

test('guest user menu dispatches login modal event', function () {
    Livewire::test(UserMenu::class)
        ->call('openLoginModal')
        ->assertDispatched('showLoginModal');
});

test('guest user menu renders login trigger', function () {
    Livewire::test(UserMenu::class)
        ->assertSeeHtml('data-test="open-login-modal-button"')
        ->assertDontSeeHtml('data-test="user-menu-dropdown"');
});

test('authenticated user menu renders dropdown actions', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(UserMenu::class)
        ->assertSeeHtml('data-test="user-menu-button"')
        ->assertSeeHtml('data-test="user-menu-dropdown"')
        ->assertSeeHtml('data-test="user-menu-orders-item"')
        ->assertSeeHtml('data-test="user-menu-settings-item"')
        ->assertSeeHtml('data-test="user-menu-logout-item"')
        ->assertSee($user->name)
        ->assertSee($user->email)
        ->assertDontSeeHtml('data-test="open-login-modal-button"')
        ->assertDontSeeHtml('data-test="user-menu-admin-item"')
        ->assertDontSeeHtml('data-test="user-menu-verify-email-item"');
});

test('filament admin user menu shows only admin link and logout', function () {
    config()->set('settings.general.filament_admin_emails', ['admin@example.com']);

    $user = User::factory()->create([
        'name' => 'admin',
        'email' => 'admin@example.com',
    ]);

    Livewire::actingAs($user)
        ->test(UserMenu::class)
        ->assertSeeHtml('data-test="user-menu-button"')
        ->assertSeeHtml('data-test="user-menu-dropdown"')
        ->assertSeeHtml('data-test="user-menu-admin-item"')
        ->assertSeeHtml('data-test="user-menu-logout-item"')
        ->assertDontSeeHtml('data-test="user-menu-orders-item"')
        ->assertDontSeeHtml('data-test="user-menu-settings-item"')
        ->assertDontSeeHtml('data-test="user-menu-verify-email-item"')
        ->assertSee('Админка');
});

test('authenticated user menu shortens button label to first 11 chars of first name part', function () {
    $user = User::factory()->create([
        'name' => 'VeryLongUsername Person',
    ]);

    Livewire::actingAs($user)
        ->test(UserMenu::class)
        ->assertSeeHtml('<span class="hidden xl:block">VeryLongUse</span>');
});

test('authenticated user menu enables hover open behavior', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(UserMenu::class)
        ->assertSeeHtml('x-data="navDropdown()"')
        ->assertSeeHtml('@mouseenter="show()"')
        ->assertSeeHtml('@mouseleave="hide(150)"');
});

test('unverified user menu renders verify email action', function () {
    $user = User::factory()->unverified()->create();

    Livewire::actingAs($user)
        ->test(UserMenu::class)
        ->assertSeeHtml('data-test="user-menu-verify-email-item"')
        ->assertSee('Подтвердить email');
});

test('authenticated user menu can dispatch verify email modal event', function () {
    $user = User::factory()->unverified()->create();

    Livewire::actingAs($user)
        ->test(UserMenu::class)
        ->call('openVerifyEmailModal')
        ->assertDispatched('showVerifyEmailModal');
});
