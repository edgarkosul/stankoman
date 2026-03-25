<?php

use Tests\TestCase;

uses(TestCase::class);

test('product card root container stretches to full parent size', function (): void {
    $view = file_get_contents(resource_path('views/components/product/card.blade.php'));

    expect($view)
        ->toContain('relative h-full w-full overflow-hidden')
        ->toContain('relative flex min-h-0 flex-1 flex-col')
        ->toContain('product-card__swiper h-48 w-full min-w-0 flex-none')
        ->toContain('grid grow content-start w-full grid-cols-[max-content_max-content]');
});
