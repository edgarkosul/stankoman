<?php

use App\Filament\Resources\ChatConversations\ChatConversationResource;
use App\Livewire\Admin\OperatorPresenceToggle;
use App\Models\User;
use App\Services\Ai\AssistantConfig;
use App\Services\Chat\OperatorPresence;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

/*
 * Переключатель «я на смене» в топбаре админки.
 *
 * Главное здесь — подпись. Она считается по КОМБИНАЦИИ «работает ли бот»
 * и «есть ли человек»: у донора она считалась по одному присутствию и над
 * выключенным ботом писала «отвечает бот», причём этот скриншот дошёл
 * до заказчика.
 */

beforeEach(function (): void {
    Cache::flush();

    config(['settings.general.filament_admin_emails' => ['admin@example.com']]);

    $this->admin = User::factory()->create(['email' => 'admin@example.com']);
});

it('подпись считает бота и присутствие вместе', function (): void {
    expect(OperatorPresenceToggle::badge(botEnabled: true, online: true))
        ->toBe(['Чат: отвечает бот, зовёт менеджера', 'live'])
        ->and(OperatorPresenceToggle::badge(botEnabled: true, online: false))
        ->toBe(['Чат: отвечает бот, просит контакты', 'live'])
        // Выключенный бот перебивает присутствие: поля для вопроса нет,
        // и обещать в подписи ответ в чате нельзя.
        ->and(OperatorPresenceToggle::badge(botEnabled: false, online: true))
        ->toBe(['Чат: только форма контактов', 'contacts'])
        ->and(OperatorPresenceToggle::badge(botEnabled: false, online: false))
        ->toBe(['Чат: только форма контактов', 'contacts']);
});

it('переключает присутствие вручную и возвращает автоопределение', function (): void {
    $presence = app(OperatorPresence::class);

    Livewire::actingAs($this->admin)
        ->test(OperatorPresenceToggle::class)
        ->call('goOffline');

    expect($presence->isOnline())->toBeFalse()
        ->and($presence->source())->toBe(OperatorPresence::SOURCE_OVERRIDE);

    Livewire::actingAs($this->admin)
        ->test(OperatorPresenceToggle::class)
        ->call('goOnline');

    expect($presence->isOnline())->toBeTrue()
        // Ручное решение живёт не дольше своего срока: переключатель
        // без TTL забывают включённым.
        ->and($presence->override()['until'])->not->toBeNull();

    Livewire::actingAs($this->admin)
        ->test(OperatorPresenceToggle::class)
        ->call('followSchedule');

    expect($presence->override())->toBeNull();
});

it('называет виновника, когда бот выключен в админке', function (): void {
    // Настройки разложены в config на старте приложения — бот читает
    // именно их, а не таблицу.
    config(['settings.'.AssistantConfig::PREFIX.'enabled' => false]);

    Livewire::actingAs($this->admin)
        ->test(OperatorPresenceToggle::class)
        ->assertSee('Чат: только форма контактов')
        ->assertSee('Бот выключен в «Настройках бота».')
        ->assertSee('Открыть настройки');
});

it('аварийный рубильник в конфиге отличает от выключателя в админке', function (): void {
    config(['ai_support.agent.enabled' => false]);

    Livewire::actingAs($this->admin)
        ->test(OperatorPresenceToggle::class)
        ->assertSee('Бот выключен разработчиком в конфигурации — из админки не включить.')
        ->assertDontSee('Открыть настройки');
});

it('видна в топбаре админки', function (): void {
    $this->actingAs($this->admin)
        ->get(ChatConversationResource::getUrl('index', panel: 'admin'))
        ->assertOk()
        // Хук USER_MENU_BEFORE рисует компонент рядом с профилем.
        ->assertSee('Чат: отвечает бот', escape: false);
});
