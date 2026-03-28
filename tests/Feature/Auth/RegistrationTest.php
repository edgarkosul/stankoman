<?php

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk()
        ->assertSee('/page/terms', escape: false)
        ->assertSee('/page/privacy', escape: false)
        ->assertSee('Пользовательским соглашением')
        ->assertSee('Политикой обработки персональных данных');
});

test('new users can register', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'accept_terms' => 'on',
    ]);

    $response->assertSessionHasNoErrors()
        ->assertRedirect(route('home', absolute: false));

    $this->assertAuthenticated();
});

test('users must accept legal terms to register', function () {
    $response = $this->from(route('register'))->post(route('register.store'), [
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertRedirect(route('register', absolute: false))
        ->assertSessionHasErrors(['accept_terms']);

    $this->assertGuest();
});
