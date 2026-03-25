<?php

use Tests\TestCase;

uses(TestCase::class);

test('product card root container stretches to full parent size', function (): void {
    $view = file_get_contents(resource_path('views/components/product/card.blade.php'));

    expect($view)
        ->toContain('relative flex h-full min-w-0 w-full flex-col')
        ->toContain('relative flex min-h-0 min-w-0 flex-1 flex-col')
        ->toContain('product-card__swiper h-48 w-full min-w-0 flex-none')
        ->toContain('grid-cols-[minmax(0,1fr)_auto]')
        ->toContain('min-w-0 py-1 pr-2 text-zinc-500 wrap-anywhere')
        ->toContain('wrap-anywhere');
});
