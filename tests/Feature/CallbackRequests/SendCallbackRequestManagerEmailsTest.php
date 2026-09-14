<?php

use App\Events\CallbackRequests\CallbackRequestSubmitted;
use App\Listeners\CallbackRequests\SendCallbackRequestManagerEmails;
use App\Mail\CallbackRequestManagerMail;
use App\Models\CallbackRequest;
use App\Support\CallbackRequestService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    config()->set('mail.from.address', 'noreply@intertooler.ru');
    config()->set('company.public_email', 'sales@intertooler.ru');
    config()->set('settings.general.manager_emails', [
        'manager.one@example.test',
        'manager.two@example.test',
        'manager.one@example.test',
        'not-an-email',
    ]);
});

it('listens for submitted callback requests', function (): void {
    Event::fake();

    Event::assertListening(CallbackRequestSubmitted::class, SendCallbackRequestManagerEmails::class);
});

it('emails every manager once and marks the request as notified', function (): void {
    Mail::fake();

    $request = app(CallbackRequestService::class)->submit(
        contact: ['name' => 'Иван Петров', 'phone' => '+79990001122', 'comments' => 'Перезвоните'],
        context: ['source' => CallbackRequest::SOURCE_SITE],
    );

    foreach (['manager.one@example.test', 'manager.two@example.test'] as $manager) {
        Mail::assertSent(CallbackRequestManagerMail::class, fn (CallbackRequestManagerMail $mail): bool => $mail->callbackRequest->is($request)
            && $mail->hasTo($manager)
            && $mail->hasReplyTo('sales@intertooler.ru')
            && $mail->hasSubject('Заявка на обратный звонок №'.$request->id));
    }

    Mail::assertSentCount(2);

    $request->refresh();

    expect($request->notified_at)->not->toBeNull()
        ->and($request->attempts)->toBe(1)
        ->and($request->last_error)->toBeNull();
});

it('promises a reply by email when the request has no phone', function (): void {
    $request = CallbackRequest::factory()->fromChat()->create([
        'email' => 'maria@example.test',
        'comments' => 'Счёт для организации',
    ]);

    (new CallbackRequestManagerMail($request))
        ->assertHasSubject('Заявка на ответ письмом №'.$request->id)
        ->assertSeeInHtml('mailto:maria@example.test')
        ->assertSeeInHtml('Чат')
        ->assertSeeInHtml('Счёт для организации')
        ->assertDontSeeInHtml('Телефон:');
});

it('records a missing manager list instead of pretending the email went out', function (): void {
    config()->set('settings.general.manager_emails', []);
    Mail::fake();

    $request = CallbackRequest::factory()->create();

    app(SendCallbackRequestManagerEmails::class)->handle(new CallbackRequestSubmitted($request));

    Mail::assertNothingSent();

    $request->refresh();

    expect($request->notified_at)->toBeNull()
        ->and($request->attempts)->toBe(1)
        ->and($request->last_error)->toContain('general.manager_emails');
});

it('does not email managers twice for an already notified request', function (): void {
    Mail::fake();

    $request = CallbackRequest::factory()->create(['notified_at' => now()->subMinute(), 'attempts' => 1]);

    app(SendCallbackRequestManagerEmails::class)->handle(new CallbackRequestSubmitted($request));

    Mail::assertNothingSent();
    expect($request->refresh()->attempts)->toBe(1);
});

it('keeps the transport error on the request and lets the queue retry', function (): void {
    Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP недоступен'));

    $request = CallbackRequest::factory()->create();

    expect(fn () => app(SendCallbackRequestManagerEmails::class)->handle(new CallbackRequestSubmitted($request)))
        ->toThrow(RuntimeException::class, 'SMTP недоступен');

    $request->refresh();

    expect($request->notified_at)->toBeNull()
        ->and($request->attempts)->toBe(1)
        ->and($request->last_error)->toBe('SMTP недоступен');
});
