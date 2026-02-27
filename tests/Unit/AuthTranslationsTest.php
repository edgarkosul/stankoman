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
            'Or, return to',
            'log in',
            'login',
            'register',
            'forgot-password',
        ])
        ->and($translations['Log in'])->toBe('Войти')
        ->and($translations['Create an account'])->toBe('Создать аккаунт')
        ->and($translations['Forgot password'])->toBe('Забыли пароль')
        ->and($translations['login'])->toBe('вход')
        ->and($translations['register'])->toBe('регистрация')
        ->and($translations['forgot-password'])->toBe('забыли-пароль');
});
