<?php

it('uses currentColor for the katalog icon fill', function () {
    $svg = file_get_contents(resource_path('svg/katalog.svg'));

    expect($svg)->toContain('fill:currentColor');
});

it('sets the katalog icon class to text-white in the header', function () {
    $header = file_get_contents(resource_path('views/components/layouts/partials/header.blade.php'));

    expect($header)->toContain('<x-icon name="katalog" class="w-5 h-5 text-white" />');
});
