<?php

use App\Livewire\Auth\ForgotPasswordInline;
use App\Livewire\Auth\LoginInline;
use App\Livewire\Auth\RegisterInline;
use App\Livewire\Auth\VerifyEmailInline;
use App\Models\User;
use App\Notifications\Auth\ResetPasswordNotification;
use App\Notifications\Auth\VerifyEmailNotification;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

test('login inline can switch to register modal', function () {
    Livewire::test(LoginInline::class)
        ->call('open')
        ->assertSet('open', true)
        ->call('openRegisterModal')
        ->assertSet('open', false)
        ->assertDispatched('showRegisterModal');
});

test('login inline can switch to forgot password modal', function () {
    Livewire::test(LoginInline::class)
        ->call('open')
        ->assertSet('open', true)
        ->call('openForgotPasswordModal')
        ->assertSet('open', false)
        ->assertDispatched('showForgotPasswordModal');
});

test('register inline can switch to login modal', function () {
    Livewire::test(RegisterInline::class)
        ->call('open')
        ->assertSet('open', true)
        ->call('openLoginModal')
        ->assertSet('open', false)
        ->assertDispatched('showLoginModal');
});

test('register inline form is rendered only when modal is open', function () {
    Livewire::test(RegisterInline::class)
        ->assertDontSee('register-inline-button')
        ->call('open')
        ->assertSee('register-inline-button')
        ->assertSeeHtml('id="inline-register-name"')
        ->assertSeeHtml('id="inline-register-email"')
        ->assertSeeHtml('id="inline-register-password"')
        ->assertSeeHtml('id="inline-register-password-confirmation"');
});

test('new users can register via inline modal', function () {
    $response = Livewire::test(RegisterInline::class)
        ->call('open')
        ->set('name', 'Inline User')
        ->set('email', 'inline-user@example.com')
        ->set('password', 'password')
        ->set('password_confirmation', 'password')
        ->call('register');

    $response
        ->assertHasNoErrors()
        ->assertSet('open', false)
        ->assertDispatched('auth:logged-in')
        ->assertDispatched('showVerifyEmailModal')
        ->assertNotDispatched('auth:redirect');

    $this->assertAuthenticated();
    $this->assertDatabaseHas('users', [
        'email' => 'inline-user@example.com',
    ]);
});

test('register inline validates duplicate email', function () {
    User::factory()->create([
        'email' => 'duplicate@example.com',
    ]);

    Livewire::test(RegisterInline::class)
        ->set('name', 'Duplicate User')
        ->set('email', 'duplicate@example.com')
        ->set('password', 'password')
        ->set('password_confirmation', 'password')
        ->call('register')
        ->assertHasErrors('email');
});

test('forgot password inline sends reset link and reopens login flow', function () {
    Notification::fake();

    $user = User::factory()->create();

    $response = Livewire::test(ForgotPasswordInline::class)
        ->call('open')
        ->set('email', $user->email)
        ->call('sendPasswordResetLink');

    $response
        ->assertHasNoErrors()
        ->assertSet('open', false)
        ->assertDispatched('showLoginModal');

    Notification::assertSentTo($user, ResetPasswordNotification::class);
});

test('forgot password inline form is rendered only when modal is open', function () {
    Livewire::test(ForgotPasswordInline::class)
        ->assertDontSee('forgot-password-inline-button')
        ->call('open')
        ->assertSee('forgot-password-inline-button')
        ->assertSeeHtml('id="inline-forgot-email"');
});

test('verify email inline can resend verification email', function () {
    Notification::fake();

    $user = User::factory()->unverified()->create();

    $response = Livewire::actingAs($user)
        ->test(VerifyEmailInline::class)
        ->call('open')
        ->assertSet('open', true)
        ->call('resendVerificationNotification');

    $response
        ->assertHasNoErrors()
        ->assertSet('linkSent', true);

    Notification::assertSentTo($user, VerifyEmailNotification::class);
});

test('verify email inline shows correct initial text and resend button loading attributes', function () {
    $user = User::factory()->unverified()->create();

    Livewire::actingAs($user)
        ->test(VerifyEmailInline::class)
        ->call('open')
        ->assertSee('Для продолжения подтвердите адрес электронной почты')
        ->assertSee('Нажмите кнопку ниже, чтобы отправить письмо с ссылкой для подтверждения.')
        ->assertDontSee('Мы отправили ссылку для подтверждения')
        ->assertSeeHtml('wire:loading.attr="disabled"')
        ->assertSeeHtml('wire:target="resendVerificationNotification"');
});

test('verify email inline blocks continue action for unverified user', function () {
    $user = User::factory()->unverified()->create();

    Livewire::actingAs($user)
        ->test(VerifyEmailInline::class)
        ->call('open')
        ->call('continueToApp')
        ->assertHasErrors('verification')
        ->assertNotDispatched('auth:redirect');
});

test('verify email inline can continue to app for verified user', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(VerifyEmailInline::class)
        ->call('continueToApp')
        ->assertHasNoErrors()
        ->assertDispatched('auth:redirect');
});
