<?php

use App\Jobs\NotifyManagersAboutEscalationJob;
use App\Mail\ChatEscalationManagerMail;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use App\Services\Chat\ChatEscalationService;
use App\Services\Chat\Contracts\EscalationTarget;
use App\Services\Chat\OperatorPresence;
use App\Services\Notifications\Contracts\EscalationNotifier;
use Carbon\CarbonImmutable;
use Filament\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

/*
 * Эскалация доходит до человека: письмо менеджерам, уведомление в админке
 * и пуш в мессенджер. Проверяем не только «ушло», но и когда НЕ уходит:
 * тихое дублирование сигнала стоит менеджеру внимания, а разобранный
 * диалог, о котором пришло письмо, — доверия к каналу.
 */

beforeEach(function (): void {
    config([
        'settings.general.manager_emails' => ['manager@intertooler.test'],
        'settings.general.filament_admin_emails' => ['admin@intertooler.test'],
        'ai_support.escalation.notify_max' => false,
    ]);

    Cache::flush();
});

it('ставит уведомление в очередь один раз за кулдаун', function (): void {
    Queue::fake();

    $conversation = ChatConversation::factory()->create();
    $escalation = app(ChatEscalationService::class);

    $escalation->escalate($conversation, ChatEscalationService::TRIGGER_FAILURE, 'Шлюз недоступен.');
    $escalation->escalate($conversation->fresh(), ChatEscalationService::TRIGGER_BOT, 'Вопрос про сроки.');

    // Пометка одна, строки в ленте две, уведомление одно.
    expect($conversation->fresh()->escalated_at)->not->toBeNull()
        ->and($conversation->messages()->where('role', ChatMessage::ROLE_SYSTEM)->count())->toBe(2);

    Queue::assertPushed(NotifyManagersAboutEscalationJob::class, 1);
});

it('оставленные контакты проходят мимо кулдауна', function (): void {
    Queue::fake();

    $conversation = ChatConversation::factory()->create();
    $escalation = app(ChatEscalationService::class);

    $escalation->escalate($conversation, ChatEscalationService::TRIGGER_FAILURE);
    $escalation->escalate($conversation->fresh(), ChatEscalationService::TRIGGER_CALLBACK, 'Оставлены контакты для ответа.');

    Queue::assertPushed(NotifyManagersAboutEscalationJob::class, 2);
});

it('не тревожит менеджера, который уже ведёт разговор', function (): void {
    Queue::fake();

    $conversation = ChatConversation::factory()->operatorLed()->create();

    app(ChatEscalationService::class)->escalate($conversation, ChatEscalationService::TRIGGER_VISITOR);

    Queue::assertNothingPushed();
});

it('после возврата боту следующая эскалация снова доходит', function (): void {
    Queue::fake();

    $conversation = ChatConversation::factory()->create();
    $escalation = app(ChatEscalationService::class);

    $escalation->escalate($conversation, ChatEscalationService::TRIGGER_FAILURE);
    $escalation->returnToBot($conversation->fresh());
    $escalation->escalate($conversation->fresh(), ChatEscalationService::TRIGGER_FAILURE);

    Queue::assertPushed(NotifyManagersAboutEscalationJob::class, 2);
});

it('шлёт письмо менеджерам и уведомление в колокольчик', function (): void {
    Mail::fake();
    Notification::fake();

    $admin = User::factory()->create(['email' => 'admin@intertooler.test']);
    $conversation = ChatConversation::factory()->escalated()->create();
    ChatMessage::factory()->create([
        'chat_conversation_id' => $conversation->id,
        'body' => 'Успеете отгрузить до пятницы?',
    ]);

    (new NotifyManagersAboutEscalationJob(
        $conversation->id,
        ChatEscalationService::TRIGGER_VISITOR,
        'Покупатель просит менеджера.',
    ))->handle(app(OperatorPresence::class), app(EscalationNotifier::class), app(EscalationTarget::class));

    Mail::assertSent(ChatEscalationManagerMail::class, fn (ChatEscalationManagerMail $mail): bool => $mail->hasTo('manager@intertooler.test')
        && $mail->conversation->is($conversation));

    Notification::assertSentTo(
        $admin,
        DatabaseNotification::class,
    );
});

it('каждому менеджеру — своё письмо, без чужих адресов в «Кому»', function (): void {
    Mail::fake();
    Notification::fake();

    config(['settings.general.manager_emails' => ['first@intertooler.test', 'second@intertooler.test']]);

    $conversation = ChatConversation::factory()->escalated()->create();

    (new NotifyManagersAboutEscalationJob($conversation->id, ChatEscalationService::TRIGGER_BOT))
        ->handle(app(OperatorPresence::class), app(EscalationNotifier::class), app(EscalationTarget::class));

    Mail::assertSent(ChatEscalationManagerMail::class, 2);

    // Один мейлабл на двоих накопил бы оба адреса во втором письме.
    Mail::assertSent(
        ChatEscalationManagerMail::class,
        fn (ChatEscalationManagerMail $mail): bool => $mail->hasTo('second@intertooler.test')
            && ! $mail->hasTo('first@intertooler.test'),
    );
});

it('молчит про разговор, который уже разобрали', function (): void {
    Mail::fake();
    Notification::fake();

    User::factory()->create(['email' => 'admin@intertooler.test']);

    // Пока джоба ждала очереди, менеджер закрыл разговор.
    $conversation = ChatConversation::factory()->closed()->escalated()->create();

    (new NotifyManagersAboutEscalationJob($conversation->id, ChatEscalationService::TRIGGER_FAILURE))
        ->handle(app(OperatorPresence::class), app(EscalationNotifier::class), app(EscalationTarget::class));

    Mail::assertNothingSent();
    Notification::assertNothingSent();
});

it('откладывает пуш в мессенджер до начала смены', function (): void {
    Queue::fake();
    Mail::fake();
    Notification::fake();

    config(['ai_support.escalation.notify_max' => true]);

    // Вторник, четыре утра: смена начнётся в девять.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-24 04:00:00', 'Europe/Moscow'));

    $push = new class implements EscalationNotifier
    {
        public array $sent = [];

        public function isConfigured(): bool
        {
            return true;
        }

        public function send(string $text, ?string $url = null): bool
        {
            $this->sent[] = $text;

            return true;
        }
    };

    $conversation = ChatConversation::factory()->escalated()->create();

    (new NotifyManagersAboutEscalationJob($conversation->id, ChatEscalationService::TRIGGER_FAILURE))
        ->handle(app(OperatorPresence::class), $push, app(EscalationTarget::class));

    expect($push->sent)->toBe([]);

    Queue::assertPushed(
        NotifyManagersAboutEscalationJob::class,
        fn (NotifyManagersAboutEscalationJob $job): bool => $job->maxDeferred === true
            && $job->delay?->format('H:i') === '09:00',
    );

    CarbonImmutable::setTestNow();
});

it('в рабочее время шлёт пуш сразу', function (): void {
    Queue::fake();
    Mail::fake();
    Notification::fake();

    config(['ai_support.escalation.notify_max' => true]);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-24 11:00:00', 'Europe/Moscow'));

    $push = new class implements EscalationNotifier
    {
        public array $sent = [];

        public function isConfigured(): bool
        {
            return true;
        }

        public function send(string $text, ?string $url = null): bool
        {
            $this->sent[] = $text;

            return true;
        }
    };

    $conversation = ChatConversation::factory()->escalated()->create();

    (new NotifyManagersAboutEscalationJob($conversation->id, ChatEscalationService::TRIGGER_VISITOR))
        ->handle(app(OperatorPresence::class), $push, app(EscalationTarget::class));

    expect($push->sent)->toHaveCount(1)
        ->and($push->sent[0])->toContain('Покупатель просит менеджера в чате');

    Queue::assertNotPushed(NotifyManagersAboutEscalationJob::class);

    CarbonImmutable::setTestNow();
});
