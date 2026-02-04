<?php

test('home page renders base sections', function () {
    $response = $this->get(route('home'));

    $response
        ->assertOk()
        ->assertSee('<div class="flex min-h-screen flex-col">', false)
        ->assertSee('<main class="flex-1">', false)
        ->assertSee('Направления')
        ->assertSee('Нужна консультация?')
        ->assertSee('От запроса до запуска оборудования');
});
