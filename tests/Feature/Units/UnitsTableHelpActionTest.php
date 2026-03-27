<?php

use App\Models\User;

test('units page renders link to help center', function (): void {
    config([
        'filament_admin.emails' => ['admin@example.com'],
        'settings.general.filament_admin_emails' => ['admin@example.com'],
    ]);

    $this->actingAs(User::factory()->create([
        'email' => 'admin@example.com',
    ]));

    $this->get(route('filament.admin.resources.units.index'))
        ->assertOk()
        ->assertSee('Инструкция')
        ->assertSee('https://help.stankoman.ru/units/', false);
});
