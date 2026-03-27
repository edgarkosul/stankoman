<?php

use App\Models\User;
use App\Notifications\Auth\ResetPasswordNotification;
use App\Notifications\Auth\VerifyEmailNotification;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

uses(TestCase::class);

function previewUser(array $attributes = []): User
{
    $user = new User;
    $user->forceFill(array_merge([
        'id' => 1001,
        'name' => 'Preview User',
        'email' => 'preview@example.test',
        'phone' => '+79990000000',
        'email_verified_at' => null,
    ], $attributes));
    $user->exists = true;
    $user->wasRecentlyCreated = false;
    $user->syncOriginal();

    return $user;
}

test('user sends custom verification email notification', function () {
    Notification::fake();

    $user = previewUser();

    $user->sendEmailVerificationNotification();

    Notification::assertSentTo($user, VerifyEmailNotification::class, function ($notification) use ($user) {
        expect((string) $notification->toMail($user)->render())
            ->toContain('Подтвердите e-mail')
            ->toContain('brand-logo-link')
            ->toContain('images/logo.svg')
            ->toContain('word-break: break-all');

        return true;
    });
});

test('user sends custom reset password notification', function () {
    Notification::fake();

    $user = previewUser(['email_verified_at' => now()]);

    $user->sendPasswordResetNotification('preview-reset-token');

    Notification::assertSentTo($user, ResetPasswordNotification::class, function ($notification) use ($user) {
        expect((string) $notification->toMail($user)->render())
            ->toContain('Сброс пароля')
            ->toContain('brand-logo-link')
            ->toContain('images/logo.svg')
            ->toContain('word-break: break-all');

        return true;
    });
});
