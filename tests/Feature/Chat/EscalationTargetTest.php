<?php

use App\Models\User;
use App\Services\Chat\Contracts\EscalationTarget;

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

    $admin = User::factory()->create(['email' => 'admin@intertooler.test']);
    User::factory()->create(['email' => 'customer@example.test']);

    $target = app(EscalationTarget::class);

    // Регистр и пробелы нормализуются, мусор отсекается, дубли схлопываются.
    expect($target->managerEmails())->toBe(['manager@intertooler.test'])
        ->and(collect($target->panelRecipients())->pluck('id')->all())->toBe([$admin->id]);
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

    // Пользователя с такой почтой нет — уведомление в колокольчик уйдёт
    // в никуда, и ошибки не будет нигде. Джоба пишет об этом в лог.
    expect(collect(app(EscalationTarget::class)->panelRecipients())->all())->toBe([]);
});

it('называет покупателя именем с почтой, а гостя — анонимом', function (): void {
    $user = User::factory()->create(['name' => 'Иван Петров', 'email' => 'ivan@example.test']);

    $target = app(EscalationTarget::class);

    expect($target->displayName($user->id))->toBe('Иван Петров (ivan@example.test)')
        ->and($target->displayName(null))->toBe('аноним')
        ->and($target->displayName($user->id + 100))->toBe('аноним');
});
