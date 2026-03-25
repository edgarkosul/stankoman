<?php

use Tests\TestCase;

uses(TestCase::class);

test('product card swiper css keeps slide content shrinkable', function (): void {
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)
        ->toContain('.product-card__swiper .swiper-wrapper')
        ->toContain('@apply min-w-0;')
        ->toContain('.product-card__swiper .swiper-slide')
        ->toContain('.product-card__swiper .swiper-slide > *')
        ->toContain('@apply block h-full w-full min-w-0;');
});
