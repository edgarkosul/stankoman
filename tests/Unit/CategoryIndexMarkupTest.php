<?php

use Tests\TestCase;

uses(TestCase::class);

test('non-leaf category page container stays within the viewport when popular products slider is present', function (): void {
    $view = file_get_contents(resource_path('views/pages/categories/index.blade.php'));

    expect($view)->toContain('mx-auto w-full min-w-0 max-w-7xl px-4 py-6');
});
