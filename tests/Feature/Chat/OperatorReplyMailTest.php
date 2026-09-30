<?php

use App\Jobs\NotifyVisitorAboutOperatorReplyJob;
use App\Mail\ChatOperatorRepliedMail;
use App\Models\ChatConversation;
use App\Models\User;
use App\Services\Chat\ChatEscalationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

/*
 * Ответ менеджера в закрытую вкладку. Чат на витрине живёт только в открытой
 * вкладке, поэтому письмо — единственный способ вернуть покупателя в разговор.
 * И ровно поэтому же оно не должно превращаться в рассылку: три условия
 * ниже отсекают анонима, того, кто и так смотрит в чат, и второе письмо
 * за час.
 */

beforeEach(function (): void {
    Cache::flush();
});

it('зовёт покупателя обратно письмом', function (): void {
    Queue::fake();

    $user = User::factory()->create();
    $conversation = ChatConversation::factory()->create([
        'user_id' => $user->id,
        'last_seen_at' => now()->subHour(),
    ]);

    app(ChatEscalationService::class)->reply($conversation, $user->id, 'Отгрузим **завтра** со склада.');

    Queue::assertPushed(
        NotifyVisitorAboutOperatorReplyJob::class,
        // Разметка снята здесь, а не в письме: в письме реплика показана
        // текстом, и звёздочки уехали бы в почту как есть.
        fn (NotifyVisitorAboutOperatorReplyJob $job): bool => $job->conversationId === $conversation->id
            && $job->preview === 'Отгрузим завтра со склада.',
    );
});

it('анониму писать некуда', function (): void {
    Queue::fake();

    $operator = User::factory()->create();
    $conversation = ChatConversation::factory()->create(['last_seen_at' => now()->subHour()]);

    app(ChatEscalationService::class)->reply($conversation, $operator->id, 'Ответ менеджера.');

    Queue::assertNothingPushed();
});

it('не пишет тому, кто прямо сейчас смотрит в чат', function (): void {
    Queue::fake();

    $user = User::factory()->create();
    $conversation = ChatConversation::factory()->create([
        'user_id' => $user->id,
        'last_seen_at' => now()->subSeconds(30),
    ]);

    app(ChatEscalationService::class)->reply($conversation, $user->id, 'Ответ менеджера.');

    Queue::assertNothingPushed();
});

it('не превращает переписку из пяти реплик в пять писем', function (): void {
    Queue::fake();

    $user = User::factory()->create();
    $conversation = ChatConversation::factory()->create([
        'user_id' => $user->id,
        'last_seen_at' => now()->subHour(),
    ]);

    $escalation = app(ChatEscalationService::class);
    $escalation->reply($conversation, $user->id, 'Первый ответ.');
    $escalation->reply($conversation->fresh(), $user->id, 'Второй ответ.');

    Queue::assertPushed(NotifyVisitorAboutOperatorReplyJob::class, 1);
});

it('в письме лежит подписанная ссылка на эту переписку', function (): void {
    Mail::fake();

    $user = User::factory()->create();
    $conversation = ChatConversation::factory()->create(['user_id' => $user->id]);

    (new NotifyVisitorAboutOperatorReplyJob($conversation->id, 'Отгрузим завтра со склада.'))->handle();

    Mail::assertSent(ChatOperatorRepliedMail::class, function (ChatOperatorRepliedMail $mail) use ($user, $conversation): bool {
        return $mail->hasTo($user->email)
            && str_contains($mail->resumeUrl, '/chat/'.$conversation->token)
            && str_contains($mail->resumeUrl, 'signature=');
    });
});

it('без почты у покупателя письмо не уходит', function (): void {
    Mail::fake();

    $conversation = ChatConversation::factory()->create();

    (new NotifyVisitorAboutOperatorReplyJob($conversation->id, 'Ответ менеджера.'))->handle();

    Mail::assertNothingSent();
});
