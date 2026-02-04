<?php

use App\View\Components\HeaderMenu;

test('header menu component renders', function () {
    $view = $this->component(HeaderMenu::class);

    $view->assertSee('aria-label="Primary"', false);
});
