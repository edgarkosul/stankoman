<?php

use App\Models\CallbackRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Siteko\Chatbot\Ai\Tools\ToolContext;
use Siteko\Chatbot\Chat\ChatLabels;
use Siteko\Chatbot\Chat\Contracts\LeadIntake;
use Siteko\Chatbot\Chat\OperatorPresence;
use Siteko\Chatbot\Jobs\GenerateChatReplyJob;
use Siteko\Chatbot\Models\ChatConversation;
use Siteko\Chatbot\Models\ChatMessage;

/*
 * Панель чата — компонент пакета siteko/chatbot в рамке /chat/panel. Сама
 * панель проверена в пакете; здесь — её швы к магазину: токен в куке
 * `intertooler_chat`, цены «для своих», вошедший покупатель, заявка
 * «перезвоните» как форма контактов, режим работы магазина.
 */

const SHOP_ORIGIN = 'http://localhost';

beforeEach(function (): void {
    // Джоба ответа едет на redis-assistant мимо QUEUE_CONNECTION=sync:
    // без подмены тест поставил бы задачу в живую очередь дева.
    Queue::fake();
});

function chatCookieName(): string
{
    return (string) config('ai_support.chat.cookie');
}

/**
 * Панель так, как её открывает рамка: монтирование с тем, что знает о
 * посетителе страница /chat/panel, и рукопожатие `attach` без токена —
 * токен в куке, и рамка со своего домена читает её сама.
 *
 * @param  array<string, mixed>  $mount
 */
function shopPanel(?string $cookie = null, array $mount = [], array $page = [])
{
    $test = $cookie === null ? Livewire::withoutLazyLoading() : Livewire::withCookie(chatCookieName(), $cookie);

    return $test->test('chatbot.chat-panel', array_merge([
        'siteId' => 1,
        'origins' => [SHOP_ORIGIN],
        'visitor' => [ToolContext::SEES_MEMBER_PRICES => false],
    ], $mount))->call('attach', null, $page, []);
}

it('заводит разговор на первом вопросе, кладёт токен в куку и ставит ответ в очередь', function (): void {
    shopPanel(page: ['url' => SHOP_ORIGIN.'/page/dostavka-i-oplata', 'title' => 'Доставка и оплата'])
        ->set('draft', '  Какая у вас доставка?  ')
        ->call('sendWithToken')
        ->assertHasNoErrors()
        ->assertSet('draft', '')
        ->assertSet('awaiting', true);

    $conversation = ChatConversation::query()->sole();
    $message = $conversation->messages()->sole();

    expect($conversation->site_id)->toBe(1)
        ->and($message->body)->toBe('Какая у вас доставка?')
        ->and($message->page_context['url'] ?? null)->toBe(SHOP_ORIGIN.'/page/dostavka-i-oplata')
        // Кука — единственный ключ к переписке, httpOnly, и ставит её сервер.
        ->and(Cookie::queued(chatCookieName())?->getValue())->toBe($conversation->token)
        ->and(Cookie::queued(chatCookieName())?->isHttpOnly())->toBeTrue();

    Queue::assertPushed(GenerateChatReplyJob::class, fn (GenerateChatReplyJob $job): bool => $job->siteId === 1
        && $job->conversationId === $conversation->id
        && $job->visitorMessageId === $message->id
        && $job->attributes === [ToolContext::SEES_MEMBER_PRICES => false]
        && $job->connection === 'redis-assistant'
        && $job->queue === 'assistant');
});

it('вошедшему покупателю отвечает ценами «для своих» и помнит его в разговоре', function (): void {
    $user = User::factory()->create();

    shopPanel(mount: ['userId' => $user->id, 'visitor' => [ToolContext::SEES_MEMBER_PRICES => true]])
        ->set('draft', 'Сколько стоит компрессор?')
        ->call('sendWithToken');

    $conversation = ChatConversation::query()->sole();

    expect($conversation->user_id)->toBe($user->id);

    Queue::assertPushed(GenerateChatReplyJob::class, fn (GenerateChatReplyJob $job): bool => $job->attributes === [ToolContext::SEES_MEMBER_PRICES => true]);
});

it('открывает разговор, начатый до пакета, той же кукой — и только ею', function (): void {
    $conversation = ChatConversation::factory()->create(['unread_for_visitor' => 2]);
    ChatMessage::factory()->for($conversation, 'conversation')->fromAssistant()->create([
        'body' => 'Доставляем транспортной компанией по всей России.',
    ]);

    // Без куки чужой разговор не открыть ничем.
    shopPanel()->assertDontSee('Доставляем транспортной компанией');

    shopPanel($conversation->token)->assertSee('Доставляем транспортной компанией');

    expect($conversation->fresh()->unread_for_visitor)->toBe(0);
});

it('«Очистить переписку» забывает куку', function (): void {
    $conversation = ChatConversation::factory()->create();

    shopPanel($conversation->token)->call('clearHistory');

    // Удаление куки — та же кука с прошедшим сроком.
    expect(Cookie::queued(chatCookieName()))->not->toBeNull()
        ->and(Cookie::queued(chatCookieName())->getExpiresTime())->toBeLessThan(time())
        ->and(ChatConversation::query()->find($conversation->id))->toBeNull();
});

it('цепляет к разговору заявку, оставленную формой из чата', function (): void {
    $conversation = ChatConversation::factory()->create();
    $lead = CallbackRequest::factory()->fromChat()->create(['ip_address' => '127.0.0.1']);

    shopPanel($conversation->token)->dispatch('callback-request-created', requestId: $lead->id);

    $conversation->refresh();

    expect($conversation->lead_ref)->toBe($lead->id)
        ->and($conversation->escalated_at)->not->toBeNull();
});

it('не цепляет витринную, чужую и старую заявку', function (): void {
    $conversation = ChatConversation::factory()->create();

    // Кнопка звонка на карточке товара.
    $fromProductPage = CallbackRequest::factory()->create(['ip_address' => '127.0.0.1']);
    // Номер чужой заявки, подставленный руками.
    $foreign = CallbackRequest::factory()->fromChat()->create(['ip_address' => '10.0.0.7']);
    $stale = CallbackRequest::factory()->fromChat()->create([
        'ip_address' => '127.0.0.1',
        'created_at' => now()->subMinutes(10),
    ]);

    $component = shopPanel($conversation->token);

    foreach ([$fromProductPage, $foreign, $stale] as $lead) {
        $component->dispatch('callback-request-created', requestId: $lead->id);
    }

    expect($conversation->fresh()->lead_ref)->toBeNull()
        ->and($conversation->fresh()->escalated_at)->toBeNull();
});

it('заявка открыта, пока менеджер не ответил', function (): void {
    $lead = CallbackRequest::factory()->fromChat()->create();
    $conversation = ChatConversation::factory()->create(['lead_ref' => $lead->id]);
    $intake = app(LeadIntake::class);

    expect($intake->hasOpenLead($conversation))->toBeTrue();

    $lead->forceFill(['status' => CallbackRequest::STATUS_CALLED])->save();

    expect($intake->hasOpenLead($conversation))->toBeFalse()
        ->and($intake->hasOpenLead(ChatConversation::factory()->create()))->toBeFalse();
});

it('тема заявки из чата начинается с «Вопрос из чата»', function (): void {
    $parameters = app(LeadIntake::class)
        ->formParameters(ChatConversation::factory()->create(), 'счёт на компрессор');

    expect($parameters['topic'])->toBe('Вопрос из чата: счёт на компрессор')
        ->and($parameters['source'])->toBe(CallbackRequest::SOURCE_CHAT);
});

it('зовёт менеджера по режиму работы магазина', function (): void {
    config(['company.work_schedule' => ['days' => [1 => ['09:00', '18:00']], 'note' => '']]);

    // Понедельник, полдень.
    $this->travelTo(CarbonImmutable::parse('2026-09-14 12:00', 'Europe/Moscow'));

    expect(app(OperatorPresence::class)->isOnline())->toBeTrue();

    // Воскресенье: менеджера нет.
    $this->travelTo(CarbonImmutable::parse('2026-09-13 12:00', 'Europe/Moscow'));
    app()->forgetScopedInstances();

    expect(app(OperatorPresence::class)->isOnline())->toBeFalse();
});

it('с выключенным ботом вместо поля вопроса — форма контактов магазина', function (): void {
    config(['chatbot.agent.enabled' => false]);

    shopPanel()->assertSee(ChatLabels::CONTACT_BUTTON);

    expect(ChatConversation::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});
