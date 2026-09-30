<?php

use App\Listeners\Chat\AttachConversationToUser;
use App\Listeners\Chat\ForgetConversationCookie;
use App\Models\ChatConversation;
use App\Models\Page;
use App\Models\User;
use App\Services\Chat\ChatPreviewGate;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;

function chatRoutesCookie(): string
{
    return (string) config('ai_support.chat.cookie');
}

it('свёрнутый чат без куки не ждёт ничего и ответ не кэшируется', function (): void {
    $this->getJson(route('chat.unread'))
        ->assertOk()
        ->assertExactJson(['unread' => 0, 'poll' => null])
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('считает непрочитанное по куке и сторожит свежий вопрос к боту', function (): void {
    $answered = ChatConversation::factory()->create(['unread_for_visitor' => 2, 'last_message_at' => now()]);
    $waiting = ChatConversation::factory()->create(['last_message_at' => now()]);

    // Обычный get, а не getJson: JSON-запрос теста куки без withCredentials() не шлёт,
    // а лаунчер ходит сюда с `credentials: 'same-origin'`.
    $this->withCookie(chatRoutesCookie(), $answered->token)
        ->get(route('chat.unread'))
        ->assertExactJson(['unread' => 2, 'poll' => null]);

    $this->withCookie(chatRoutesCookie(), $waiting->token)
        ->get(route('chat.unread'))
        ->assertExactJson(['unread' => 0, 'poll' => 20]);
});

it('возвращает переписку только по подписанной ссылке', function (): void {
    $conversation = ChatConversation::factory()->create();

    $this->get('/chat/'.$conversation->token)->assertForbidden();

    $this->get(URL::temporarySignedRoute('chat.resume', now()->addDay(), ['token' => $conversation->token]))
        ->assertRedirect(route('home', ['chat' => 1]))
        ->assertCookie(chatRoutesCookie(), $conversation->token);

    $this->get(URL::temporarySignedRoute('chat.resume', now()->addDay(), ['token' => str_repeat('a', 40)]))
        ->assertNotFound();
});

it('продлевает сессию и отдаёт свежий токен', function (): void {
    $this->getJson(route('session.keepalive'))
        ->assertOk()
        ->assertJsonStructure(['token'])
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('показывает виджет на витрине, пока предпросмотр выключен', function (): void {
    config(['ai_support.chat.preview.enabled' => false]);
    $page = Page::factory()->create(['slug' => 'dostavka-i-oplata', 'is_published' => true]);

    $this->get(route('page.show', $page->slug))
        ->assertOk()
        ->assertSee('chatLauncher(', escape: false);
});

it('в предпросмотре прячет виджет от покупателей и открывает по ссылке с ключом', function (): void {
    config(['ai_support.chat.preview.enabled' => true, 'ai_support.chat.preview.key' => 'klyuch-1']);
    $page = Page::factory()->create(['slug' => 'dostavka-i-oplata', 'is_published' => true]);

    $this->get(route('page.show', $page->slug))
        ->assertOk()
        ->assertDontSee('chatLauncher(', escape: false);

    $this->get(route('page.show', ['page' => $page->slug, 'bot' => 'klyuch-1']))
        ->assertSee('chatLauncher(', escape: false)
        ->assertCookie(ChatPreviewGate::COOKIE, 'klyuch-1');
});

it('в предпросмотре показывает виджет сотруднику без всякой ссылки', function (): void {
    config([
        'ai_support.chat.preview.enabled' => true,
        'ai_support.chat.preview.key' => 'klyuch-1',
        'settings.general.filament_admin_emails' => ['manager@intertooler.test'],
    ]);
    $page = Page::factory()->create(['slug' => 'dostavka-i-oplata', 'is_published' => true]);

    $this->actingAs(User::factory()->create(['email' => 'manager@intertooler.test']))
        ->get(route('page.show', $page->slug))
        ->assertSee('chatLauncher(', escape: false);
});

it('привязывает разговор к покупателю, вошедшему посреди него', function (): void {
    Event::fake()->assertListening(Login::class, AttachConversationToUser::class);
    Event::assertListening(Logout::class, ForgetConversationCookie::class);

    $user = User::factory()->create();
    $conversation = ChatConversation::factory()->create();
    request()->cookies->set(chatRoutesCookie(), $conversation->token);

    app(AttachConversationToUser::class)->handle(new Login('web', $user, false));

    expect($conversation->fresh()->user_id)->toBe($user->id);
});
