<?php

use App\Models\CallbackRequest;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Переход таблиц бота к пакету siteko/chatbot (реестр bots 10.3.4) — две
 * миграции: принимающая (до пакетных) и перенос настроек (после них).
 *
 * Самое дорогое здесь — откат: выкладку откатывают прошлым релизом
 * и `migrate:rollback`, и старый движок обязан найти схему и данные
 * в своём виде. Поэтому тест гоняет down() и up() на строках с данными.
 */

function adoptionMigration(): object
{
    return require database_path('migrations/2026_09_18_235900_adopt_chatbot_package.php');
}

function settingsMigration(): object
{
    return require database_path('migrations/2026_10_11_120000_move_assistant_settings_to_chatbot.php');
}

it('принимает таблицы: номер сайта, заявка без внешнего ключа, источник статей', function (): void {
    $lead = CallbackRequest::factory()->create();

    DB::table('chat_conversations')->insert([
        'token' => str_repeat('a', 40), 'status' => 'bot', 'lead_ref' => $lead->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('kb_chunks')->insert([
        'chunk_id' => 'articles:7#0', 'source' => 'articles', 'doc_key' => '7', 'title' => 'Гарантия',
        'breadcrumb' => '[]', 'section_path' => '[]', 'text' => 'Год.', 'chars' => 4, 'content_hash' => str_repeat('0', 64),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    settingsMigration()->down();
    adoptionMigration()->down();

    // Старый вид: колонка заявки прежняя, номера сайта нет, источник старый.
    expect(Schema::hasColumn('chat_conversations', 'callback_request_id'))->toBeTrue()
        ->and(Schema::hasColumn('chat_conversations', 'lead_ref'))->toBeFalse()
        ->and(Schema::hasColumn('kb_chunks', 'site_id'))->toBeFalse()
        ->and(DB::table('chat_conversations')->value('callback_request_id'))->toBe($lead->id)
        ->and(DB::table('kb_chunks')->value('chunk_id'))->toBe('intertooler-kb:7#0')
        ->and(DB::table('kb_chunks')->value('source'))->toBe('intertooler-kb');

    adoptionMigration()->up();
    settingsMigration()->up();

    expect(DB::table('chat_conversations')->value('lead_ref'))->toBe($lead->id)
        ->and(DB::table('chat_conversations')->value('site_id'))->toBe(1)
        ->and(DB::table('kb_chunks')->value('chunk_id'))->toBe('articles:7#0')
        ->and(DB::table('kb_chunks')->value('source'))->toBe('articles')
        ->and(DB::table('kb_chunks')->value('site_id'))->toBe(1);
});

it('переносит настройки бота в настройки пакета и возвращает их при откате', function (): void {
    // Чистый лист: свежая база уже прошла перенос, откат вернул бы его тексты.
    settingsMigration()->down();
    DB::table('chatbot_settings')->delete();
    DB::table('settings')->where('key', 'like', 'assistant.%')->delete();

    foreach ([
        ['assistant.enabled', '0', 'bool'],
        ['assistant.bot_name', 'Ирина', 'string'],
        ['assistant.bot_gender', 'female', 'string'],
        ['assistant.about', '', 'string'],
        ['assistant.rules', json_encode(['Счёт выставляем в день обращения'], JSON_UNESCAPED_UNICODE), 'json'],
        ['assistant.avatar', 'chat-avatars/face.webp', 'string'],
        ['assistant.launcher', json_encode(['image' => 'avatar']), 'json'],
    ] as [$key, $value, $type]) {
        Setting::query()->create(['key' => $key, 'value' => $value, 'type' => $type, 'autoload' => true]);
    }

    settingsMigration()->up();

    $stored = DB::table('chatbot_settings')->where('site_id', 1)->pluck('value', 'key')
        ->map(fn (string $value): mixed => json_decode($value, true));

    expect($stored['assistant.enabled'])->toBeFalse()
        ->and($stored['assistant.bot_name'])->toBe('Ирина')
        ->and($stored['assistant.bot_gender'])->toBe('female')
        ->and($stored['widget.avatar'])->toBe('chat-avatars/face.webp')
        ->and($stored['widget.color'])->toBe('#0f6a24')
        // Тексты, которых у владельца не было, — магазинные, а не пакетные.
        ->and($stored['assistant.invite'])->toBe('Подскажу наличие, цену и условия доставки — спрашивайте.')
        ->and($stored['assistant.about'])->toStartWith('Интернет-магазин промышленного оборудования')
        // Правило владельца первым, правила магазина из старого промпта — следом.
        ->and($stored['assistant.rules'][0])->toBe('Счёт выставляем в день обращения')
        ->and(count($stored['assistant.rules']))->toBeGreaterThan(1)
        // Лаунчер — витрина магазина: остаётся в его настройках.
        ->and(Setting::query()->where('key', 'assistant.launcher')->exists())->toBeTrue()
        ->and(Setting::query()->where('key', 'assistant.bot_name')->exists())->toBeFalse();

    // Повторный прогон правил не дублирует.
    $rules = count($stored['assistant.rules']);
    settingsMigration()->down();
    settingsMigration()->up();

    expect(json_decode((string) DB::table('chatbot_settings')->where('key', 'assistant.rules')->value('value'), true))
        ->toHaveCount($rules);

    settingsMigration()->down();

    expect(Setting::query()->where('key', 'assistant.bot_name')->value('value'))->toBe('Ирина')
        ->and(Setting::query()->where('key', 'assistant.enabled')->value('value'))->toBe('0')
        ->and(Setting::query()->where('key', 'assistant.avatar')->value('value'))->toBe('chat-avatars/face.webp')
        ->and(DB::table('chatbot_settings')->where('key', 'like', 'assistant.%')->count())->toBe(0);
});
