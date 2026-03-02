<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;

test('reset password link screen can be rendered', function () {
    $response = $this->get(route('password.request'));

    $response->assertOk();
});

test('reset password link can be requested', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.request'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class);
});

test('reset password screen can be rendered', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.request'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
        $response = $this->get(route('password.reset', $notification->token));
        $response->assertOk();

        return true;
    });
});

test('password can be reset with valid token', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.request'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
        $response = $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login', absolute: false));

        return true;
    });
});

test('password reset notification is localized to russian', function () {
    Notification::fake();
    app()->setLocale('ru');

    $user = User::factory()->create();

    $this->post(route('password.request'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
        $mailMessage = $notification->toMail($user);
        $expirationMinutes = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        expect($mailMessage->subject)->toBe('Уведомление о сбросе пароля')
            ->and($mailMessage->introLines)->toContain('Вы получили это письмо, потому что мы получили запрос на сброс пароля для вашей учетной записи.')
            ->and($mailMessage->actionText)->toBe('Сбросить пароль')
            ->and($mailMessage->outroLines)->toContain("Срок действия ссылки для сброса пароля истекает через {$expirationMinutes} мин.")
            ->and($mailMessage->outroLines)->toContain('Если вы не запрашивали сброс пароля, никаких дополнительных действий не требуется.');

        return true;
    });
});
