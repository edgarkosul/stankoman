<?php

test('header tooltips use full row as trigger', function (): void {
    $component = file_get_contents(resource_path('views/components/layouts/partials/header.blade.php'));

    expect($component)
        ->toMatch('/<x-tooltip[^>]*subtitle="г\\. Краснодар, трасса М4-ДОН"[\\s\\S]*?<x-slot:trigger>[\\s\\S]*?Краснодар[\\s\\S]*?<\\/x-slot:trigger>/')
        // Часы в подсказке — из настройки «Режим работы», а не строкой в шаблоне;
        // что показывается именно она, проверяет SettingsDrivenPageBlocksTest.
        ->toMatch('/<x-tooltip[^>]*:subtitle="\\$workScheduleLines\\[0\\][^"]*"[\\s\\S]*?<x-slot:trigger>[\\s\\S]*?Режим работы[\\s\\S]*?<\\/x-slot:trigger>/')
        ->not->toContain('9:00 - 18:00');
});

test('header shows user tooltip only for guests', function (): void {
    $component = file_get_contents(resource_path('views/components/layouts/partials/header.blade.php'));

    expect($component)
        ->toContain("x-tooltip.smart.bottom.offset-10.lt-xl=\"'Войти'\"")
        ->not->toContain("x-tooltip.smart.bottom.offset-10.lt-xl=\"@js(auth()->check() ? 'Кабинет' : 'Войти')\"");
});
