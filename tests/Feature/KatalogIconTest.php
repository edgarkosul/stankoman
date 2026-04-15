<?php

it('uses currentColor for the katalog icon fill', function () {
    $svg = file_get_contents(resource_path('svg/katalog.svg'));

    expect($svg)->toContain('fill="currentColor"');
});

it('sets the katalog icon class to text-white in the header', function () {
    $header = file_get_contents(resource_path('views/components/layouts/partials/header.blade.php'));

    expect($header)->toContain('name="katalog" class="w-5 h-5 text-white"');
});

it('collapses header navigation items below xl with a dropdown', function () {
    $headerMenu = file_get_contents(resource_path('views/components/header-menu.blade.php'));

    expect($headerMenu)
        ->toContain('relative lg:hidden')
        ->toContain('hidden lg:flex xl:hidden')
        ->toContain('hidden xl:flex')
        ->toContain('x-data="{ open: false }"')
        ->toContain('x-show="open"')
        ->toContain('Ещё');
});
