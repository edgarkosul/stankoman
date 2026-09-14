<?php

use App\Events\CallbackRequests\CallbackRequestSubmitted;
use App\Livewire\Common\RequestCallback;
use App\Models\CallbackRequest;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

it('saves a site callback request with a normalized phone and announces it', function (): void {
    Event::fake([CallbackRequestSubmitted::class]);

    $product = Product::query()->create([
        'name' => 'Станок ленточнопильный IT-4500',
        'slug' => 'it-4500',
        'is_active' => true,
        'price_amount' => 199900,
    ]);

    $component = Livewire::test(RequestCallback::class, ['productId' => $product->id])
        ->assertSee(RequestCallback::CALL_BUTTON)
        ->assertSet('isOpen', false)
        ->call('open')
        ->assertSet('isOpen', true)
        ->assertSee('Станок ленточнопильный IT-4500')
        ->assertSee('/page/privacy', escape: false)
        ->set('customerName', '  Иван   Петров ')
        ->set('customerPhone', '8 (999) 000-11-22')
        ->set('callTime', 'после 14:00')
        ->set('comments', 'Подойдёт для нержавейки?')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('submitted', true)
        ->assertSee('Менеджер перезвонит вам в рабочее время.');

    $request = CallbackRequest::query()->sole();

    $component->assertDispatched('callback-request-created', requestId: $request->id);

    expect($request->name)->toBe('Иван Петров')
        ->and($request->phone)->toBe('+79990001122')
        ->and($request->phone_hash)->toBe(hash('sha256', '79990001122'))
        ->and($request->email)->toBeNull()
        ->and($request->email_hash)->toBeNull()
        ->and($request->call_time)->toBe('после 14:00')
        ->and($request->comments)->toBe('Подойдёт для нержавейки?')
        ->and($request->product_id)->toBe($product->id)
        ->and($request->user_id)->toBeNull()
        ->and($request->source)->toBe(CallbackRequest::SOURCE_SITE)
        ->and($request->status)->toBe(CallbackRequest::STATUS_PENDING)
        ->and($request->notified_at)->toBeNull();

    Event::assertDispatched(CallbackRequestSubmitted::class, fn (CallbackRequestSubmitted $event): bool => $event->callbackRequest->is($request));
});

it('requires a phone on the site form and checks an optional email', function (): void {
    Livewire::test(RequestCallback::class)
        ->call('open')
        ->call('submit')
        ->assertHasErrors(['customerName', 'customerPhone'])
        ->assertHasNoErrors(['customerEmail'])
        ->set('customerPhone', '123')
        ->set('customerEmail', 'broken@')
        ->call('submit')
        ->assertHasErrors(['customerPhone', 'customerEmail']);

    expect(CallbackRequest::query()->count())->toBe(0);
});

it('asks for an email in the chat channel and carries the topic into comments', function (): void {
    Event::fake([CallbackRequestSubmitted::class]);

    Livewire::test(RequestCallback::class, [
        'topic' => 'Счёт для организации',
        'source' => CallbackRequest::SOURCE_CHAT,
        'channel' => RequestCallback::CHANNEL_EMAIL,
    ])
        ->assertSee(RequestCallback::CONTACT_BUTTON)
        ->call('open')
        ->assertSet('comments', 'Счёт для организации')
        ->assertDontSee('Удобное время звонка')
        ->set('customerName', 'Мария')
        ->call('submit')
        ->assertHasErrors(['customerEmail'])
        ->assertHasNoErrors(['customerPhone'])
        ->set('customerEmail', ' Maria@Example.TEST ')
        ->set('callTime', 'утром')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSee('Менеджер ответит вам на почту.');

    $request = CallbackRequest::query()->sole();

    expect($request->source)->toBe(CallbackRequest::SOURCE_CHAT)
        ->and($request->email)->toBe('maria@example.test')
        ->and($request->email_hash)->toBe(hash('sha256', 'maria@example.test'))
        ->and($request->phone)->toBeNull()
        ->and($request->phone_hash)->toBeNull()
        ->and($request->call_time)->toBeNull()
        ->and($request->comments)->toBe('Счёт для организации');
});

it('does not let the browser rewrite where the request came from', function (): void {
    Livewire::test(RequestCallback::class)
        ->set('source', CallbackRequest::SOURCE_CHAT);
})->throws(CannotUpdateLockedPropertyException::class);

it('falls back to the site source and phone channel on unknown values', function (): void {
    Livewire::test(RequestCallback::class, ['source' => 'evil', 'channel' => 'fax'])
        ->assertSet('source', CallbackRequest::SOURCE_SITE)
        ->assertSet('channel', RequestCallback::CHANNEL_PHONE);
});

it('prefills contacts of a signed-in customer and links the request to them', function (): void {
    Event::fake([CallbackRequestSubmitted::class]);

    $user = User::factory()->create([
        'name' => 'Павел Сидоров',
        'email' => 'pavel@example.test',
        'phone' => '+79991112233',
        'shipping_city' => 'Краснодар',
    ]);

    Livewire::actingAs($user)
        ->test(RequestCallback::class)
        ->call('open')
        ->assertSet('customerName', 'Павел Сидоров')
        ->assertSet('customerEmail', 'pavel@example.test')
        ->assertSet('customerPhone', '+79991112233')
        ->assertSet('city', 'Краснодар')
        ->call('submit')
        ->assertHasNoErrors();

    expect(CallbackRequest::query()->sole()->user_id)->toBe($user->id);
});

it('refuses a second request with the same phone within a minute', function (): void {
    Event::fake([CallbackRequestSubmitted::class]);

    Livewire::test(RequestCallback::class)
        ->call('open')
        ->set('customerName', 'Иван')
        ->set('customerPhone', '+79990001122')
        ->call('submit')
        ->assertHasNoErrors()
        ->call('open')
        ->set('customerName', 'Иван')
        ->set('customerPhone', '8 999 000 11 22')
        ->call('submit')
        ->assertHasErrors(['customerPhone'])
        ->assertSet('submitted', false);

    expect(CallbackRequest::query()->count())->toBe(1);
});

it('shows the callback button on the product page', function (): void {
    $product = Product::query()->create([
        'name' => 'Товар со звонком',
        'slug' => 'tovar-so-zvonkom',
        'is_active' => true,
        'in_stock' => true,
        'price_amount' => 100000,
    ]);

    $this->get(route('product.show', $product))
        ->assertOk()
        ->assertSee(RequestCallback::CALL_BUTTON);
});
