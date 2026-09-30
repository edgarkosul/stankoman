<?php

use App\Events\CallbackRequests\CallbackRequestSubmitted;
use App\Events\Orders\OrderSubmitted;
use App\Filament\Pages\MessengerNotifications;
use App\Jobs\DeliverMaxMessageJob;
use App\Listeners\CallbackRequests\NotifyMessengersAboutCallback;
use App\Listeners\Orders\NotifyMessengersAboutOrder;
use App\Models\CallbackRequest;
use App\Models\ChatConversation;
use App\Models\MessengerChannel;
use App\Models\Order;
use App\Models\User;
use App\Services\Messengers\ChatGone;
use App\Services\Messengers\MaxClient;
use App\Services\Messengers\MaxLinks;
use App\Services\Notifications\Contracts\EscalationNotifier;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/*
 * Уведомления менеджерам в MAX: чаты подключаются кнопкой, дальше туда уходят
 * заказы, заявки и вопросы из чата. Проверяем стыки, а не сам мессенджер:
 * кто на что подписан, что не уходит дважды и что происходит, когда бота
 * выгнали из чата.
 */

beforeEach(function (): void {
    config([
        'services.max.token' => 'max-token',
        'services.max.bot_link' => 'https://max.ru/intertooler_bot',
        'services.max.webhook_secret' => 's3cret',
    ]);
});

function maxChannel(array $attributes = []): MessengerChannel
{
    return MessengerChannel::query()->create([
        'label' => 'Менеджеры',
        'chat_id' => '777',
        ...$attributes,
    ]);
}

it('слушатели подписаны на заказ и на заявку', function (): void {
    Event::fake()->assertListening(OrderSubmitted::class, NotifyMessengersAboutOrder::class);
    Event::assertListening(CallbackRequestSubmitted::class, NotifyMessengersAboutCallback::class);
});

it('новый заказ уходит в чаты, подписанные на заказы', function (): void {
    Queue::fake();

    maxChannel(['label' => 'Заказы', 'chat_id' => '1']);
    maxChannel(['label' => 'Только вопросы', 'chat_id' => '2', 'notify_orders' => false]);
    maxChannel(['label' => 'Выключенный', 'chat_id' => '3', 'enabled' => false]);

    $order = Order::factory()->create([
        'customer_name' => 'Пётр',
        'customer_phone' => '79002468660',
        'grand_total' => 145000,
    ]);

    /*
     * Слушатель резолвится контейнером — тем же способом, каким его создаёт
     * очередь. Если бы зависимость стояла вторым параметром handle(), падало
     * бы именно здесь: Laravel передаёт слушателю только событие.
     */
    app(NotifyMessengersAboutOrder::class)->handle(new OrderSubmitted($order));

    Queue::assertPushed(DeliverMaxMessageJob::class, 1);
    Queue::assertPushed(fn (DeliverMaxMessageJob $job): bool => str_contains($job->text, 'Новый заказ № '.$order->order_number)
        && str_contains($job->text, 'Покупатель: Пётр')
        // Телефон с плюсом: иначе MAX не предложит позвонить.
        && str_contains($job->text, 'Телефон: +79002468660')
        && str_contains($job->text, '/admin/'));
});

it('заявка на звонок уходит один раз и не дублирует эскалацию из чата', function (): void {
    Queue::fake();
    maxChannel();

    $fromSite = CallbackRequest::factory()->create(['source' => CallbackRequest::SOURCE_SITE]);
    app(NotifyMessengersAboutCallback::class)->handle(new CallbackRequestSubmitted($fromSite));

    Queue::assertPushed(DeliverMaxMessageJob::class, 1);

    // Ту же форму показывает панель чата: там заявку привязывают к диалогу,
    // и про неё уже написала эскалация — второе сообщение было бы дублем.
    $fromChat = CallbackRequest::factory()->create(['source' => CallbackRequest::SOURCE_CHAT]);
    ChatConversation::factory()->create(['callback_request_id' => $fromChat->getKey()]);

    app(NotifyMessengersAboutCallback::class)->handle(new CallbackRequestSubmitted($fromChat));

    Queue::assertPushed(DeliverMaxMessageJob::class, 1);
});

it('вопрос из чата идёт в те же чаты, подписанные на вопросы', function (): void {
    $push = app(EscalationNotifier::class);

    expect($push->isConfigured())->toBeFalse();

    maxChannel(['notify_chat' => false]);
    expect($push->isConfigured())->toBeFalse();

    maxChannel(['chat_id' => '778']);
    expect($push->isConfigured())->toBeTrue();

    Queue::fake();

    expect($push->send('Покупатель ждёт ответа', 'https://intertooler.ru/admin/chat/1'))->toBeTrue();

    Queue::assertPushed(fn (DeliverMaxMessageJob $job): bool => str_contains($job->text, 'Покупатель ждёт ответа')
        && str_contains($job->text, 'https://intertooler.ru/admin/chat/1'));
});

it('доставка выключает чат, из которого бота выгнали, и оставляет причину', function (): void {
    $channel = maxChannel();

    Http::fake(['platform-api.max.ru/*' => Http::response(['message' => 'chat.not.found'], 404)]);

    (new DeliverMaxMessageJob($channel->id, 'Новый заказ'))->handle(app(MaxClient::class));

    $channel->refresh();

    expect($channel->enabled)->toBeFalse()
        ->and($channel->last_error)->toContain('chat.not.found')
        ->and($channel->failed_at)->not->toBeNull();
});

it('временный сбой MAX не выключает чат, а уходит в повтор', function (): void {
    $channel = maxChannel();

    Http::fake(['platform-api.max.ru/*' => Http::response(['message' => 'too.many'], 429)]);

    expect(fn () => (new DeliverMaxMessageJob($channel->id, 'x'))->handle(app(MaxClient::class)))
        ->toThrow(RuntimeException::class);

    expect($channel->fresh()->enabled)->toBeTrue()
        ->and($channel->fresh()->last_error)->toContain('too.many');
});

it('подключает чат по одноразовому коду из вебхука и выключает по bot_removed', function (): void {
    Http::fake(['platform-api.max.ru/*' => Http::response([])]);

    $code = app(MaxLinks::class)->issue();

    $this->withHeader('X-Max-Bot-Api-Secret', 's3cret')
        ->postJson('/hooks/max', [
            'update_type' => 'bot_started',
            'chat_id' => 555,
            'payload' => $code,
            'user' => ['name' => 'Роман'],
        ])
        ->assertOk();

    expect(MessengerChannel::query()->where('chat_id', '555')->value('label'))->toBe('Роман');

    // Код одноразовый: вторым запросом чат не подключить.
    $this->withHeader('X-Max-Bot-Api-Secret', 's3cret')
        ->postJson('/hooks/max', ['update_type' => 'bot_started', 'chat_id' => 556, 'payload' => $code])
        ->assertOk();

    expect(MessengerChannel::query()->where('chat_id', '556')->exists())->toBeFalse();

    $this->withHeader('X-Max-Bot-Api-Secret', 's3cret')
        ->postJson('/hooks/max', ['update_type' => 'bot_removed', 'chat_id' => 555])
        ->assertOk();

    expect(MessengerChannel::query()->where('chat_id', '555')->value('enabled'))->toBeFalsy();
});

it('вебхук без верного секрета не пускает никого', function (): void {
    $this->postJson('/hooks/max', ['update_type' => 'bot_started', 'chat_id' => 1])->assertForbidden();

    $this->withHeader('X-Max-Bot-Api-Secret', 'guess')
        ->postJson('/hooks/max', ['update_type' => 'bot_started', 'chat_id' => 1])
        ->assertForbidden();

    expect(MessengerChannel::query()->count())->toBe(0);
});

it('страница в админке подключает, переключает и отключает чаты', function (): void {
    config(['settings.general.filament_admin_emails' => ['admin@intertooler.test']]);
    $this->actingAs(User::factory()->create(['email' => 'admin@intertooler.test']));

    $channel = maxChannel();

    $page = Livewire::test(MessengerNotifications::class)
        ->assertSee('Менеджеры')
        ->callAction('connect')
        ->assertHasNoErrors();

    // Кнопка открывает бота ссылкой с кодом и начинает ждать чат.
    expect($page->get('awaitingUntil'))->toBeGreaterThan(now()->getTimestamp());

    $page->call('toggle', $channel->id, MessengerChannel::TOPIC_ORDERS);
    expect($channel->fresh()->notify_orders)->toBeFalse();

    $page->callAction('remove', arguments: ['channel' => $channel->id]);
    expect(MessengerChannel::query()->count())->toBe(0);
});

it('без настроенного бота кнопка подключения не работает', function (): void {
    config(['services.max.token' => null]);
    config(['settings.general.filament_admin_emails' => ['admin@intertooler.test']]);

    $this->actingAs(User::factory()->create(['email' => 'admin@intertooler.test']));

    Livewire::test(MessengerNotifications::class)
        ->assertSee('Бот MAX не настроен')
        ->assertActionDisabled('connect');
});

it('проверка кнопкой поднимает выключенный чат, если всё починилось', function (): void {
    config(['settings.general.filament_admin_emails' => ['admin@intertooler.test']]);
    $this->actingAs(User::factory()->create(['email' => 'admin@intertooler.test']));

    $channel = maxChannel(['enabled' => false, 'last_error' => 'Бота остановили или удалили из чата MAX.']);

    Http::fake(['platform-api.max.ru/*' => Http::response([])]);

    Livewire::test(MessengerNotifications::class)
        ->callAction('test', arguments: ['channel' => $channel->id])
        ->assertHasNoErrors();

    $channel->refresh();

    expect($channel->enabled)->toBeTrue()
        ->and($channel->last_error)->toBeNull()
        ->and($channel->last_sent_at)->not->toBeNull();
});

it('чат, из которого бота выгнали, проверкой не поднимается', function (): void {
    config(['settings.general.filament_admin_emails' => ['admin@intertooler.test']]);
    $this->actingAs(User::factory()->create(['email' => 'admin@intertooler.test']));

    $channel = maxChannel();

    Http::fake(['platform-api.max.ru/*' => Http::response(['message' => 'chat.denied'], 403)]);

    Livewire::test(MessengerNotifications::class)
        ->callAction('test', arguments: ['channel' => $channel->id]);

    expect($channel->fresh()->enabled)->toBeFalse();

    expect(fn () => app(MaxClient::class)->send('777', 'x'))->toThrow(ChatGone::class);
});
