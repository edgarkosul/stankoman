<?php

test('header tooltips use full row as trigger', function (): void {
    $component = file_get_contents(resource_path('views/components/layouts/partials/header.blade.php'));

    expect($component)
        ->toMatch('/<x-tooltip[^>]*subtitle="г\\. Краснодар, трасса М4-ДОН"[\\s\\S]*?<x-slot:trigger>[\\s\\S]*?Краснодар[\\s\\S]*?<\\/x-slot:trigger>/')
        ->toMatch('/<x-tooltip[^>]*subtitle="ПН - Пт: 9:00 - 18:00"[\\s\\S]*?<x-slot:trigger>[\\s\\S]*?Режим работы[\\s\\S]*?<\\/x-slot:trigger>/');
});

test('header shows user tooltip only for guests', function (): void {
    $component = file_get_contents(resource_path('views/components/layouts/partials/header.blade.php'));

    expect($component)
        ->toContain("x-tooltip.smart.bottom.offset-10.lt-xl=\"'Войти'\"")
        ->not->toContain("x-tooltip.smart.bottom.offset-10.lt-xl=\"@js(auth()->check() ? 'Кабинет' : 'Войти')\"");
});
