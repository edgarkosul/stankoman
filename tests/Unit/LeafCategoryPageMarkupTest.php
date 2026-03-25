<?php

use Tests\TestCase;

uses(TestCase::class);

test('leaf category page keeps the products column shrinkable next to filters', function (): void {
    $view = file_get_contents(resource_path('views/livewire/pages/categories/leaf.blade.php'));

    expect($view)
        ->toContain('mx-auto w-full min-w-0 max-w-7xl px-4 py-6 space-y-4 bg-zinc-100/80 flex-1')
        ->toContain('flex-1 min-w-0 space-y-4')
        ->toContain('grid min-w-0 grid-cols-2 gap-4 xl:grid-cols-3');
});
