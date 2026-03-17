<?php

test('brand colors are defined in the tailwind theme', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)
        ->toContain('--color-brand-green: #0f6a24;')
        ->toContain('--color-brand-red: #ea0005;')
        ->toContain('--color-brand-gray: #a1a1a1;')
        ->toContain('.tippy-box[data-theme~="ks"]')
        ->toContain('.tippy-box[data-theme~="ks-light"]')
        ->toContain('border-radius: 0;');
});

test('rich content video blocks have shared responsive styles outside static pages', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)
        ->toContain('.video {')
        ->toContain('@apply w-full aspect-video shadow-xl;')
        ->toContain('.video iframe {')
        ->toContain('.video.video--left {')
        ->toContain('.video.video--center {')
        ->toContain('.static-page .video {');
});
