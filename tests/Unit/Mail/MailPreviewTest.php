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

test('welcome verify preview renders contact header and non-breaking buttons', function () {
    $response = $this->get(route('mail.preview.show', 'welcome-verify-and-set-password'));
    $publicEmail = (string) config('company.public_email');

    $response->assertOk();
    $response->assertSee('www.intertooler.ru', escape: false);
    $response->assertSee('+7 (900) 246-86-60', escape: false);
    $response->assertSee('mailto:'.$publicEmail, escape: false);
    $response->assertSee('padding: 14px 26px', escape: false);
    $response->assertSee('white-space: nowrap', escape: false);
});
