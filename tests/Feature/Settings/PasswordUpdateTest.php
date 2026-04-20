<?php

use App\Livewire\Settings\Password;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

test('password page is displayed', function () {
    $this->actingAs($user = User::factory()->create());

    $this->get('/settings/password')
        ->assertOk()
        ->assertSee('data-test="settings-password-form"', false)
        ->assertSee('class="font-semibold">Пароль</span>', false);
});

test('filament admins are redirected away from password page', function () {
    config()->set('settings.general.filament_admin_emails', ['admin@example.com']);

    $this->actingAs(User::factory()->create([
        'email' => 'admin@example.com',
    ]));

    $this->get('/settings/password')
        ->assertRedirect(route('home'));
});

test('password can be updated', function () {
    $user = User::factory()->create([
        'password' => Hash::make('password'),
    ]);

    $this->actingAs($user);

    $response = Livewire::test(Password::class)
        ->set('current_password', 'password')
        ->set('password', 'new-password')
        ->set('password_confirmation', 'new-password')
        ->call('updatePassword');

    $response->assertHasNoErrors();

    expect(Hash::check('new-password', $user->refresh()->password))->toBeTrue();
});

test('correct password must be provided to update password', function () {
    $user = User::factory()->create([
        'password' => Hash::make('password'),
    ]);

    $this->actingAs($user);

    $response = Livewire::test(Password::class)
        ->set('current_password', 'wrong-password')
        ->set('password', 'new-password')
        ->set('password_confirmation', 'new-password')
        ->call('updatePassword');

    $response->assertHasErrors(['current_password']);
});
