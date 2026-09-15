<?php

use App\Models\AiUsageEntry;
use App\Models\ChatConversation;
use App\Models\ChatMessage;

it('удаляет полугодовые разговоры насовсем, обезличивает месячные и не трогает расходную книгу', function (): void {
    $ancient = ChatConversation::factory()->create(['last_message_at' => now()->subDays(200)]);
    ChatMessage::factory()->for($ancient, 'conversation')->create();

    // Спрятанный из админки — те же данные, и срок у него тот же.
    $trashed = ChatConversation::factory()->create(['last_message_at' => now()->subDays(300), 'deleted_at' => now()]);

    $old = ChatConversation::factory()->create([
        'last_message_at' => now()->subDays(40),
        'user_agent' => 'Firefox',
        'referer_url' => 'https://ya.ru/',
    ]);

    // Начат давно, но продолжен вчера — свежий.
    $revived = ChatConversation::factory()->create([
        'created_at' => now()->subDays(250),
        'last_message_at' => now()->subDay(),
        'user_agent' => 'Chrome',
    ]);

    AiUsageEntry::query()->create(AiUsageEntry::attributesFor(null, $ancient->id, stopReason: 'stop') + [
        'created_at' => now()->subDays(200),
    ]);

    $this->artisan('chat:purge')->assertSuccessful();

    expect(ChatConversation::withTrashed()->find($ancient->id))->toBeNull()
        ->and(ChatConversation::withTrashed()->find($trashed->id))->toBeNull()
        ->and(ChatMessage::query()->count())->toBe(0)
        ->and($old->fresh()->ip_hash)->toBeNull()
        ->and($old->fresh()->user_agent)->toBeNull()
        ->and($old->fresh()->referer_url)->toBeNull()
        ->and($revived->fresh()->user_agent)->toBe('Chrome')
        ->and(AiUsageEntry::query()->count())->toBe(1);
});

it('сухой прогон ничего не меняет', function (): void {
    $ancient = ChatConversation::factory()->create(['last_message_at' => now()->subDays(200)]);

    $this->artisan('chat:purge', ['--dry-run' => true])
        ->expectsOutputToContain('[сухой прогон] Удалено диалогов: 1')
        ->assertSuccessful();

    expect($ancient->fresh())->not->toBeNull();
});
