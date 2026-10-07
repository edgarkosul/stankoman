<?php

use App\Enums\SettingType;
use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ссылки на MAX и Telegram в шапке и подвале — из настроек, а не из шаблона.
 *
 * Строки заводит миграция, а не `settings:sync`: хук деплоя синхронизацию
 * не зовёт. Значения пустые: ссылки на профиль MAX у нас нет, а зашитая
 * `https://max.ru/` открывала главную мессенджера вместо чата — до заполнения
 * значка MAX на сайте не будет. Пустой Telegram ведёт по номеру, как и раньше.
 */
return new class extends Migration
{
    private const SETTINGS = [
        'company.max_url' => 'Ссылка на профиль в MAX (https://max.ru/u/...). Пусто — значка MAX на сайте нет.',
        'company.telegram_url' => 'Ссылка на Telegram (https://t.me/...). Пусто — чат по номеру телефона компании.',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        foreach (self::SETTINGS as $key => $description) {
            if (DB::table('settings')->where('key', $key)->exists()) {
                continue;
            }

            DB::table('settings')->insert([
                'key' => $key,
                'type' => SettingType::String->value,
                'value' => '',
                'description' => $description,
                'autoload' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Хук чистит кэш до миграций, но старый релиз успевает собрать его заново без новых строк.
        Cache::forget(Setting::CACHE_KEY);
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        DB::table('settings')->whereIn('key', array_keys(self::SETTINGS))->delete();

        Cache::forget(Setting::CACHE_KEY);
    }
};
