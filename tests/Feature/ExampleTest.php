<?php

test('home page renders base sections', function () {
    $response = $this->get(route('home'));

    $response
        ->assertOk()
        ->assertSee('Stankoman')
        ->assertSee('Направления')
        ->assertSee('Нужна консультация?');
});
