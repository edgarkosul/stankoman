<?php

use App\Jobs\GenerateChatReplyJob;
use App\Livewire\Common\RequestCallback;
use App\Livewire\Support\ChatPanel;
use App\Models\AiUsageEntry;
use App\Models\CallbackRequest;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Page;
use App\Services\Chat\ChatConversationService;
use App\Services\Chat\ChatPollingCadence;
use App\Services\Chat\OperatorPresence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/*
 * Панель чата на sqlite. У донора у панели тестов не было: Feature-тест
 * на его машине стирал дев-базу. Здесь предохранитель в tests/Pest.php
 * этого не допускает.
 */

beforeEach(function (): void {
    // Джоба ответа едет на redis-assistant мимо QUEUE_CONNECTION=sync:
    // без подмены тест поставил бы задачу в живую очередь дева.
    Queue::fake();
});

function chatCookieName(): string
{
    return (string) config('ai_support.chat.cookie');
}

it('заводит разговор на первом вопросе и ставит ответ в очередь ассистента', function (): void {
    Livewire::test(ChatPanel::class)
        ->set('draft', '  Какая у вас доставка?  ')
        ->call('send')
        ->assertHasNoErrors()
        ->assertSet('draft', '')
        ->assertSet('awaiting', true)
        ->assertSet('pollInterval', '2s')
        ->assertSee('Печатает…');

    $conversation = ChatConversation::query()->sole();
    $message = $conversation->messages()->sole();

    expect($message->role)->toBe(ChatMessage::ROLE_VISITOR)
        ->and($message->body)->toBe('Какая у вас доставка?')
        ->and($conversation->messages_count)->toBe(1)
        ->and($conversation->consent_at)->not->toBeNull()
        ->and(app(ChatConversationService::class)->isPending($conversation))->toBeTrue()
        // Кука — единственный ключ к переписке, и ставит её сервер.
        ->and(Cookie::queued(chatCookieName())?->getValue())->toBe($conversation->token);

    Queue::assertPushed(GenerateChatReplyJob::class, fn (GenerateChatReplyJob $job): bool => $job->conversationId === $conversation->id
        && $job->visitorMessageId === $message->id
        && $job->connection === 'redis-assistant'
        && $job->queue === 'assistant');
});

it('не принимает пустой вопрос', function (): void {
    Livewire::test(ChatPanel::class)
        ->set('draft', '   ')
        ->call('send')
        ->assertHasErrors(['draft' => 'required']);

    expect(ChatConversation::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('показывает переписку только по куке и отмечает её прочитанной', function (): void {
    $conversation = ChatConversation::factory()->create(['unread_for_visitor' => 2]);
    ChatMessage::factory()->for($conversation, 'conversation')->fromAssistant()->create([
        'body' => 'Доставляем транспортной компанией по всей России.',
    ]);

    // Без куки чужой разговор не открыть ничем.
    Livewire::test(ChatPanel::class)
        ->assertDontSee('Доставляем транспортной компанией')
        ->assertSee('Здравствуйте!');

    Livewire::withCookie(chatCookieName(), $conversation->token)
        ->test(ChatPanel::class)
        ->assertSee('Доставляем транспортной компанией')
        ->assertDontSee('Здравствуйте!');

    expect($conversation->fresh()->unread_for_visitor)->toBe(0);
});

it('снимает ожидание и показывает ответ, как только он дописан', function (): void {
    $conversation = ChatConversation::factory()->create();

    $component = Livewire::withCookie(chatCookieName(), $conversation->token)
        ->test(ChatPanel::class)
        ->set('draft', 'Как оплатить заказ?')
        ->call('send')
        ->assertSet('awaiting', true);

    // Пока пометка на месте, тик ничего не меняет.
    $component->call('poll')->assertSet('awaiting', true);

    $chat = app(ChatConversationService::class);
    $chat->addAssistantMessage($conversation->fresh(), 'Оплатить можно **по счёту**.', stopReason: 'stop');
    $chat->clearPending($conversation);

    $component->call('poll')
        ->assertSet('awaiting', false)
        ->assertSet('pollInterval', '10s')
        ->assertSeeHtml('<strong>по счёту</strong>');

    expect($conversation->fresh()->unread_for_visitor)->toBe(0);
});

it('не оставляет покупателя с тишиной, если ответ так и не пришёл', function (): void {
    $conversation = ChatConversation::factory()->create();

    $component = Livewire::withCookie(chatCookieName(), $conversation->token)
        ->test(ChatPanel::class)
        ->set('draft', 'Есть станок IT-4500?')
        ->call('send');

    // Воркер не поднят: задачу никто не взял, и даже failed() не сработает.
    $this->travel(ChatPollingCadence::GIVE_UP_AFTER_SECONDS + 1)->seconds();

    $component->call('poll')
        ->assertSet('awaiting', false)
        ->assertSee('Не получается ответить прямо сейчас.')
        ->assertSee(RequestCallback::CONTACT_BUTTON);

    $conversation->refresh();

    expect($conversation->escalated_at)->not->toBeNull()
        ->and($conversation->messages()->where('role', ChatMessage::ROLE_ASSISTANT)->sole()->stop_reason)->toBe('stalled');
});

it('кладёт рядом с вопросом страницу, на которой он задан', function (): void {
    Page::factory()->create(['slug' => 'dostavka-i-oplata', 'title' => 'Доставка и оплата', 'is_published' => true]);

    Livewire::test(ChatPanel::class, ['page' => ['type' => 'page', 'slug' => 'dostavka-i-oplata']])
        ->set('draft', 'А в Казань везёте?')
        ->call('send');

    expect(ChatMessage::query()->sole()->page_context)->toMatchArray([
        'type' => 'page',
        'title' => 'Доставка и оплата',
    ]);
});

it('удаляет переписку насовсем, но не заявку и не расходную книгу', function (): void {
    $lead = CallbackRequest::factory()->fromChat()->create();
    $conversation = ChatConversation::factory()->create(['callback_request_id' => $lead->id]);
    app(ChatConversationService::class)->addAssistantMessage($conversation, 'Ответ.', stopReason: 'stop');

    Livewire::withCookie(chatCookieName(), $conversation->token)
        ->test(ChatPanel::class)
        ->call('clearHistory')
        ->assertSee('Здравствуйте!');

    expect(ChatConversation::withTrashed()->count())->toBe(0)
        ->and(ChatMessage::query()->count())->toBe(0)
        ->and($lead->fresh())->not->toBeNull()
        ->and(AiUsageEntry::query()->count())->toBe(1);
});

it('ставит оценку, снимает её повторным кликом и не дотягивается до чужой переписки', function (): void {
    $mine = ChatConversation::factory()->create();
    $answer = ChatMessage::factory()->for($mine, 'conversation')->fromAssistant()->create();
    $foreign = ChatMessage::factory()->fromAssistant()->create();

    $component = Livewire::withCookie(chatCookieName(), $mine->token)->test(ChatPanel::class);

    $component->call('rate', $answer->id, -1)->assertSee('Спасибо — посмотрим, что улучшить');
    expect($answer->fresh()->rating)->toBe(ChatMessage::RATING_DOWN);

    $component->call('rate', $answer->id, -1);
    expect($answer->fresh()->rating)->toBeNull();

    $component->call('rate', $foreign->id, 1);
    expect($foreign->fresh()->rating)->toBeNull();
});

it('цепляет к разговору заявку, оставленную формой из чата', function (): void {
    $conversation = ChatConversation::factory()->create();
    $lead = CallbackRequest::factory()->fromChat()->create(['ip_address' => '127.0.0.1']);

    Livewire::withCookie(chatCookieName(), $conversation->token)
        ->test(ChatPanel::class)
        ->dispatch('callback-request-created', requestId: $lead->id);

    $conversation->refresh();

    expect($conversation->callback_request_id)->toBe($lead->id)
        ->and($conversation->escalated_at)->not->toBeNull()
        ->and($conversation->messages()->where('role', ChatMessage::ROLE_SYSTEM)->pluck('body')->all())
        ->toContain('Покупатель оставил почту, заявка №'.$lead->id.'.');
});

it('не цепляет витринную, чужую и старую заявку', function (): void {
    $conversation = ChatConversation::factory()->create();

    // Кнопка звонка на карточке товара при открытой панели чата.
    $fromProductPage = CallbackRequest::factory()->create(['ip_address' => '127.0.0.1']);
    // Номер чужой заявки, подставленный руками.
    $foreign = CallbackRequest::factory()->fromChat()->create(['ip_address' => '10.0.0.7']);
    $stale = CallbackRequest::factory()->fromChat()->create([
        'ip_address' => '127.0.0.1',
        'created_at' => now()->subMinutes(10),
    ]);

    $component = Livewire::withCookie(chatCookieName(), $conversation->token)->test(ChatPanel::class);

    foreach ([$fromProductPage, $foreign, $stale] as $lead) {
        $component->dispatch('callback-request-created', requestId: $lead->id);
    }

    expect($conversation->fresh()->callback_request_id)->toBeNull()
        ->and($conversation->fresh()->escalated_at)->toBeNull();
});

it('зовёт менеджера только в рабочее время', function (): void {
    config(['company.work_schedule' => ['days' => [1 => ['09:00', '18:00']], 'note' => '']]);

    // Понедельник, полдень.
    $this->travelTo(CarbonImmutable::parse('2026-09-14 12:00', 'Europe/Moscow'));

    $conversation = ChatConversation::factory()->create();

    Livewire::withCookie(chatCookieName(), $conversation->token)
        ->test(ChatPanel::class)
        ->assertSee('Позвать менеджера')
        ->call('callOperator')
        ->assertDontSee('Позвать менеджера')
        ->assertSee('Передал разговор менеджеру');

    expect($conversation->fresh()->escalated_at)->not->toBeNull();

    // Воскресенье: кнопки нет, а прямой вызов с клиента ничего не делает.
    $this->travelTo(CarbonImmutable::parse('2026-09-13 12:00', 'Europe/Moscow'));
    app()->forgetInstance(OperatorPresence::class);

    $sunday = ChatConversation::factory()->create();

    Livewire::withCookie(chatCookieName(), $sunday->token)
        ->test(ChatPanel::class)
        ->assertDontSee('Позвать менеджера')
        ->call('callOperator');

    expect($sunday->fresh()->escalated_at)->toBeNull();
});

it('с выключенным ботом не принимает вопрос и сразу предлагает оставить контакты', function (): void {
    config(['ai_support.agent.enabled' => false]);

    Livewire::test(ChatPanel::class)
        ->assertSee(RequestCallback::CONTACT_BUTTON)
        ->set('draft', 'Есть доставка?')
        ->call('send')
        ->assertHasErrors('draft');

    expect(ChatConversation::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});
