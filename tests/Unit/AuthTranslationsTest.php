<?php

test('russian auth translations include inline auth strings and auth route labels', function (): void {
    $translations = json_decode(
        file_get_contents(dirname(__DIR__, 2).'/lang/ru.json'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    expect($translations)->toBeArray()
        ->toHaveKeys([
            'Close',
            'Log in to your account',
            'Enter your email and password below to log in',
            'Email address',
            'Password',
            'Forgot your password?',
            'Remember me',
            'Log in',
            'Sign up',
            'Create an account',
            'Enter your details below to create your account',
            'Name',
            'Full name',
            'Confirm password',
            'Create account',
            'Already have an account?',
            'Forgot password',
            'Enter your email to receive a password reset link',
            'Email Address',
            'Email password reset link',
            'Verify Email Address',
            'Please click the button below to verify your email address.',
            'If you did not create an account, no further action is required.',
            'Reset Password Notification',
            'You are receiving this email because we received a password reset request for your account.',
            'Reset Password',
            'This password reset link will expire in :count minutes.',
            'If you did not request a password reset, no further action is required.',
            'Whoops!',
            'Hello!',
            'Regards,',
            "If you're having trouble clicking the \":actionText\" button, copy and paste the URL below\ninto your web browser:",
            'All rights reserved.',
            'Or, return to',
            'log in',
            'login',
            'register',
            'forgot-password',
        ])
        ->and($translations['Log in'])->toBe('Войти')
        ->and($translations['Create an account'])->toBe('Создать аккаунт')
        ->and($translations['Forgot password'])->toBe('Забыли пароль')
        ->and($translations['Verify Email Address'])->toBe('Подтвердите адрес электронной почты')
        ->and($translations['Reset Password Notification'])->toBe('Уведомление о сбросе пароля')
        ->and($translations['Regards,'])->toBe('С уважением,')
        ->and($translations['login'])->toBe('вход')
        ->and($translations['register'])->toBe('регистрация')
        ->and($translations['forgot-password'])->toBe('забыли-пароль');
});
