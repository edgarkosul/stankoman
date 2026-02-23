<?php

use App\Models\ImportRun;
use App\Models\User;
use App\Providers\Filament\AdminPanelProvider;
use Filament\Panel;

test('admin panel has database notifications enabled', function () {
    $provider = new AdminPanelProvider(app());
    $panel = $provider->panel(Panel::make());

    expect($panel->hasDatabaseNotifications())->toBeTrue();
    expect($panel->getDatabaseNotificationsPollingInterval())->toBe('10s');
});

test('import completion database notification is sent only to initiator for final statuses', function (string $status) {
    $initiator = User::factory()->create();
    $otherUser = User::factory()->create();

    $run = ImportRun::query()->create([
        'type' => 'metalmaster_products',
        'status' => 'pending',
        'columns' => [],
        'totals' => [
            'create' => 5,
            'update' => 2,
            'same' => 1,
            'conflict' => 0,
            'error' => $status === 'failed' ? 1 : 0,
        ],
        'user_id' => $initiator->id,
        'started_at' => now(),
    ]);

    $run->status = $status;
    $run->finished_at = now();
    $run->save();

    $initiatorNotification = $initiator->notifications()->latest()->first();

    expect($initiatorNotification)->not->toBeNull();
    expect($initiator->notifications()->count())->toBe(1);
    expect($otherUser->notifications()->count())->toBe(0);
    expect((string) data_get($initiatorNotification?->data, 'format'))->toBe('filament');
    expect((string) data_get($initiatorNotification?->data, 'title'))->toContain("#{$run->id}");
    expect((string) data_get($initiatorNotification?->data, 'body'))->toContain('Metalmaster');
})->with(['dry_run', 'applied', 'failed', 'cancelled']);

test('database notification is not sent when status remains non-final', function () {
    $initiator = User::factory()->create();

    $run = ImportRun::query()->create([
        'type' => 'vactool_products',
        'status' => 'pending',
        'columns' => [],
        'totals' => [
            'create' => 0,
            'update' => 0,
            'same' => 0,
            'conflict' => 0,
            'error' => 0,
        ],
        'user_id' => $initiator->id,
        'started_at' => now(),
    ]);

    $run->totals = array_merge((array) $run->totals, [
        'scanned' => 10,
    ]);
    $run->save();

    expect($initiator->notifications()->count())->toBe(0);
});
