<?php

use Illuminate\Support\Facades\Storage;

it('serves the generated market feed file', function (): void {
    Storage::fake('public');
    Storage::disk('public')->put('feeds/yandex-market.xml', '<root>feed</root>');

    $this->get('/market.xml')
        ->assertOk()
        ->assertHeader('content-type', 'text/xml; charset=utf-8');
});

it('returns 404 when the market feed file does not exist', function (): void {
    Storage::fake('public');

    $this->get('/market.xml')->assertNotFound();
});
