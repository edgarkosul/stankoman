<?php

test('brand colors are defined in the tailwind theme', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)
        ->toContain('--color-brand-green: #0f6a24;')
        ->toContain('--color-brand-red: #ea0005;')
        ->toContain('--color-brand-gray: #a1a1a1;');
});
