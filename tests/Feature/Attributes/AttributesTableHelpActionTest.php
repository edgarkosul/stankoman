<?php

use App\Models\User;

test('attributes page renders link to help center', function (): void {
    config([
        'settings.general.filament_admin_emails' => ['admin@example.com'],
    ]);

    $this->actingAs(User::factory()->create([
        'email' => 'admin@example.com',
    ]));

    $this->get(route('filament.admin.resources.attributes.index'))
        ->assertOk()
        ->assertSee('Инструкция')
        ->assertSee('https://help.stankoman.ru/attributes/', false);
});
