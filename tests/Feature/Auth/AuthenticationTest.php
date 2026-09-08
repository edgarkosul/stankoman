<?php

use App\Models\User;
use Filament\Facades\Filament;

test('login screen can be rendered', function () {
    $response = $this->get(route('login'));

    $response->assertOk();
});

test('login screen translates flashed password reset status', function () {
    app()->setLocale('ru');

    $response = $this->withSession([
        'status' => 'passwords.reset',
    ])->get(route('login'));

    $response
        ->assertOk()
        ->assertSeeText('Ваш пароль был сброшен.')
        ->assertDontSeeText('passwords.reset');
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home', absolute: false));

    $this->assertAuthenticated();
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertSessionHasErrorsIn('email');

    $this->assertGuest();
});

test('filament admins are sent to the panel login instead of being told the password is wrong', function () {
    config()->set('settings.general.filament_admin_emails', ['admin@example.com']);

    $user = User::factory()->create([
        'email' => 'admin@example.com',
    ]);

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'auth.panel_only')
        ->assertRedirect(Filament::getPanel('admin')->getLoginUrl());

    $this->assertGuest();
});

test('filament admins with a wrong password get the generic error, not the panel hint', function () {
    config()->set('settings.general.filament_admin_emails', ['admin@example.com']);

    $user = User::factory()->create([
        'email' => 'admin@example.com',
    ]);

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response
        ->assertSessionHasErrorsIn('email')
        ->assertSessionMissing('status');

    $this->assertGuest();
});

test('panel login screen explains why the storefront form refused an admin', function () {
    app()->setLocale('ru');

    $response = $this->withSession([
        'status' => 'auth.panel_only',
    ])->get(Filament::getPanel('admin')->getLoginUrl());

    $response
        ->assertOk()
        ->assertSeeText('Это учётная запись администратора — вход только через панель управления.')
        ->assertDontSeeText('auth.panel_only');
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('logout'));

    $response->assertRedirect(route('home'));
    $this->assertGuest();
});
