<?php

use App\Enums\SettingType;
use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Режим работы магазина — одна настройка на сайт и ассистента.
 *
 * Строку заводит миграция, а не `settings:sync`: хук деплоя синхронизацию
 * не зовёт, а сама синхронизация разложила бы вложенный массив по отдельным
 * ключам. Стартовое значение — то, что сайт обещал в шапке и подвале
 * (Пн–Пт 9–18), плюс оговорка про выходные со страницы «О компании»:
 * страница перестаёт хранить график сама, и оговорка не должна пропасть.
 */
return new class extends Migration
{
    private const KEY = 'company.work_schedule';

    private const DEFAULT = [
        'days' => [
            1 => ['09:00', '18:00'],
            2 => ['09:00', '18:00'],
            3 => ['09:00', '18:00'],
            4 => ['09:00', '18:00'],
            5 => ['09:00', '18:00'],
            6 => null,
            7 => null,
        ],
        'note' => 'В субботу и воскресенье — отгрузка по предварительной договорённости.',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        if (! DB::table('settings')->where('key', self::KEY)->exists()) {
            DB::table('settings')->insert([
                'key' => self::KEY,
                'type' => SettingType::Json->value,
                'value' => json_encode(self::DEFAULT, JSON_UNESCAPED_UNICODE),
                'description' => 'Шапка и подвал сайта, блок «Режим работы» на страницах, база знаний бота.',
                'autoload' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Настройки лежат в кэше навсегда — без сброса строка не доедет до конфига.
        Cache::forget(Setting::CACHE_KEY);
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        DB::table('settings')->where('key', self::KEY)->delete();

        Cache::forget(Setting::CACHE_KEY);
    }
};
