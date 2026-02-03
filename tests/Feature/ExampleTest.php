<?php

test('home page renders base sections', function () {
    $response = $this->get(route('home'));

    $response
        ->assertOk()
        ->assertSee('Направления')
        ->assertSee('Нужна консультация?')
        ->assertSee('Режим работы колл-центра');
});
