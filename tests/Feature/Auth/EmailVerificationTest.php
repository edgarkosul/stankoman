<?php

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

test('email verification screen can be rendered', function () {
    $user = User::factory()->unverified()->create();

    $response = $this->actingAs($user)->get(route('verification.notice'));

    $response->assertOk();
});

test('email can be verified', function () {
    $user = User::factory()->unverified()->create();

    Event::fake();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)]
    );

    $response = $this->actingAs($user)->get($verificationUrl);

    Event::assertDispatched(Verified::class);

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    $response->assertRedirect(route('home', absolute: false).'?verified=1');
});

test('email is not verified with invalid hash', function () {
    $user = User::factory()->unverified()->create();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1('wrong-email')]
    );

    $this->actingAs($user)->get($verificationUrl);

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('already verified user visiting verification link is redirected without firing event again', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    Event::fake();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)]
    );

    $this->actingAs($user)->get($verificationUrl)
        ->assertRedirect(route('home', absolute: false).'?verified=1');

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    Event::assertNotDispatched(Verified::class);
});

test('verification email notification is localized to russian', function () {
    Notification::fake();
    app()->setLocale('ru');

    $user = User::factory()->unverified()->create();

    $user->sendEmailVerificationNotification();

    Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user) {
        $mailMessage = $notification->toMail($user);

        expect($mailMessage->subject)->toBe('Подтвердите адрес электронной почты')
            ->and($mailMessage->introLines)->toContain('Пожалуйста, нажмите кнопку ниже, чтобы подтвердить адрес электронной почты.')
            ->and($mailMessage->actionText)->toBe('Подтвердите адрес электронной почты')
            ->and($mailMessage->outroLines)->toContain('Если вы не создавали аккаунт, никаких дополнительных действий не требуется.');

        return true;
    });
});
