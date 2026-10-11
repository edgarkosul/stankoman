<?php

use App\Models\User;
use Filament\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Siteko\Chatbot\Chat\Contracts\EscalationTarget;
use Siteko\Chatbot\Chat\Data\EscalationNotice;

function escalationNotice(): EscalationNotice
{
    return new EscalationNotice(
        conversationId: 7,
        trigger: 'visitor',
        title: 'Посетитель просит менеджера в чате',
        summary: 'Нужен станок под нержавейку.',
        url: 'http://localhost/admin/chat-conversations/7',
    );
}

/*
 * Шов «персонал»: кто в магазине сотрудник и как назвать покупателя.
 * Тест Feature, а не Unit, потому что получатели уведомления ищутся
 * в таблице пользователей — и именно на этом стыке живёт самая тихая
 * авария: почта в настройке есть, пользователя с ней нет.
 */

it('письма идут менеджерам, уведомления — тем, кто ходит в админку', function (): void {
    config([
        'settings.general.manager_emails' => ['  Manager@Intertooler.TEST ', 'не-почта', 'manager@intertooler.test'],
        'settings.general.filament_admin_emails' => ['admin@intertooler.test'],
    ]);

    Notification::fake();

    $admin = User::factory()->create(['email' => 'admin@intertooler.test']);
    $customer = User::factory()->create(['email' => 'customer@example.test']);

    $target = app(EscalationTarget::class);

    // Регистр и пробелы нормализуются, мусор отсекается, дубли схлопываются.
    expect($target->managerEmails())->toBe(['manager@intertooler.test']);

    $target->notifyPanel(escalationNotice());

    Notification::assertSentTo($admin, DatabaseNotification::class);
    Notification::assertNotSentTo($customer, DatabaseNotification::class);
});

it('без менеджерских почт письмо уходит админам панели', function (): void {
    config([
        'settings.general.manager_emails' => [],
        'settings.general.filament_admin_emails' => ['admin@intertooler.test'],
    ]);

    expect(app(EscalationTarget::class)->managerEmails())->toBe(['admin@intertooler.test']);
});

it('почта в настройке без пользователя не даёт получателя уведомления', function (): void {
    config([
        'settings.general.manager_emails' => ['manager@intertooler.test'],
        'settings.general.filament_admin_emails' => ['admin@intertooler.test'],
    ]);

    Notification::fake();
    Log::spy();

    // Пользователя с такой почтой нет — уведомление в колокольчик ушло бы
    // в никуда, и ошибки не было бы нигде. Поэтому каждая эскалация пишет
    // выпавшие адреса в лог.
    app(EscalationTarget::class)->notifyPanel(escalationNotice());

    Notification::assertNothingSent();
    Log::shouldHaveReceived('warning')->with('Escalation panel recipients without user rows', Mockery::on(
        static fn (array $context): bool => $context['emails'] === ['admin@intertooler.test'],
    ));
});

it('называет покупателя именем с почтой, а гостя — анонимом', function (): void {
    $user = User::factory()->create(['name' => 'Иван Петров', 'email' => 'ivan@example.test']);

    $target = app(EscalationTarget::class);

    expect($target->displayName($user->id))->toBe('Иван Петров (ivan@example.test)')
        ->and($target->displayName(null))->toBe('аноним')
        ->and($target->displayName($user->id + 100))->toBe('аноним');
});

it('ведёт ссылкой на карточку диалога в админке', function (): void {
    expect(app(EscalationTarget::class)->conversationUrl(42))->toEndWith('/admin/chat-conversations/42');
});
