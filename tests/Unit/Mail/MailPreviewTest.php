<?php

use App\Support\Mail\MailPreviewFactory;
use Tests\TestCase;

uses(TestCase::class);

test('mail preview index can be rendered without database bootstrap', function () {
    $response = $this->get(route('mail.preview.index'));

    $response->assertOk();
    $response->assertSee('Mail Previews', escape: false);
});

test('mail preview catalog renders expected content', function () {
    $factory = app(MailPreviewFactory::class);

    foreach ($factory->catalog() as $preview) {
        $response = $this->get(route('mail.preview.show', $preview['key']));

        $response->assertOk();
        $response->assertSeeText($preview['expectedText']);
    }
});
