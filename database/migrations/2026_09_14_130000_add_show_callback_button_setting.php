<?php

use App\Enums\SettingType;
use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Переключатель кнопки «Заказать звонок менеджера» на карточке товара.
 *
 * Строку заводит миграция, а не `settings:sync`: хук деплоя синхронизацию
 * не зовёт, и без миграции переключателя в админке на бою просто не было бы.
 * Значение по умолчанию — включено: после выката сайт выглядит так же, как до.
 */
return new class extends Migration
{
    private const KEY = 'product.show_callback_button';

    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        if (! DB::table('settings')->where('key', self::KEY)->exists()) {
            DB::table('settings')->insert([
                'key' => self::KEY,
                'type' => SettingType::Bool->value,
                'value' => '1',
                'description' => 'Кнопка на карточке товара. Форма в чате ассистента от неё не зависит.',
                'autoload' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        /*
         * Настройки лежат в кэше навсегда. Хук чистит кэш до миграций, но между
         * этими шагами запрос к старому релизу успевает собрать его заново —
         * без новой строки.
         */
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
