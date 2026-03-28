<?php

use App\Models\User;
use App\Notifications\Auth\ResetPasswordNotification;
use App\Notifications\Auth\VerifyEmailNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rules\Password as PasswordRule;

test('reset password link screen can be rendered', function () {
    $response = $this->get(route('password.request'));

    $response->assertOk();
});

test('reset password link can be requested', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.request'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPasswordNotification::class);
});

test('reset password screen can be rendered', function () {
    Notification::fake();

    app()->setLocale('ru');

    $user = User::factory()->create();

    $this->post(route('password.request'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPasswordNotification::class, function ($notification) {
        $response = $this->get(route('password.reset', $notification->token));
        $response
            ->assertOk()
            ->assertSeeText('Сбросить пароль')
            ->assertSeeText('Введите новый пароль ниже')
            ->assertSeeText('Адрес электронной почты')
            ->assertSeeText('Подтвердите пароль');

        return true;
    });
});

test('password can be reset with valid token', function () {
    Notification::fake();

    $user = User::factory()->unverified()->create();
    $newPassword = 'ValidPassword1!';

    $this->post(route('password.request'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPasswordNotification::class, function ($notification) use ($newPassword, $user) {
        $response = $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => $newPassword,
            'password_confirmation' => $newPassword,
        ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login', absolute: false));

        return true;
    });

    Notification::assertSentTo($user, VerifyEmailNotification::class);
});

test('verified user does not receive verification email after password reset', function () {
    Notification::fake();

    $user = User::factory()->create();
    $newPassword = 'ValidPassword1!';

    $this->post(route('password.request'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPasswordNotification::class, function ($notification) use ($newPassword, $user) {
        $response = $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => $newPassword,
            'password_confirmation' => $newPassword,
        ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login', absolute: false));

        return true;
    });

    Notification::assertNotSentTo($user, VerifyEmailNotification::class);
});

test('reset password validation error is localized when password misses a symbol', function () {
    Notification::fake();

    app()->setLocale('ru');

    try {
        PasswordRule::defaults(fn (): PasswordRule => PasswordRule::min(12)->mixedCase()->letters()->numbers()->symbols());

        $user = User::factory()->create();

        $this->post(route('password.request'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPasswordNotification::class, function ($notification) use ($user) {
            $response = $this->from(route('password.reset', [
                'token' => $notification->token,
                'email' => $user->email,
            ]))->post(route('password.update'), [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'C3FiFYfLFzY8Bo',
                'password_confirmation' => 'C3FiFYfLFzY8Bo',
            ]);

            $response->assertSessionHasErrors([
                'password' => 'Поле пароль должно содержать хотя бы один спецсимвол.',
            ]);

            return true;
        });
    } finally {
        PasswordRule::defaults(fn (): ?PasswordRule => null);
    }
});

test('custom password reset notification is used', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.request'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $notification) use ($user) {
        $mailMessage = $notification->toMail($user);

        expect((string) $mailMessage->render())
            ->toContain('Сброс пароля')
            ->toContain('Сбросить пароль')
            ->toContain($user->email);

        return true;
    });
});
