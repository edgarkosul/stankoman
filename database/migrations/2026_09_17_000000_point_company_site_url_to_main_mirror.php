<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * company.site_url живёт в таблице settings и перекрывает конфиг, поэтому
 * правки config/company.php боевому сайту мало. www.intertooler.ru отвечает
 * 301 на intertooler.ru, а из site_url собираются все адреса sitemap, фида
 * Маркета и микроразметки — поисковик получал карту сайта из одних редиректов.
 *
 * Меняем только прежнее значение по умолчанию: адрес, заданный в админке
 * руками, не трогаем.
 */
return new class extends Migration
{
    private const OLD_URL = 'https://www.intertooler.ru';

    private const NEW_URL = 'https://intertooler.ru';

    public function up(): void
    {
        $this->replaceSiteUrl(self::OLD_URL, self::NEW_URL);
    }

    public function down(): void
    {
        $this->replaceSiteUrl(self::NEW_URL, self::OLD_URL);
    }

    private function replaceSiteUrl(string $from, string $to): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        $updated = DB::table('settings')
            ->where('key', 'company.site_url')
            ->whereIn('value', [$from, $from.'/'])
            ->update([
                'value' => $to,
                'updated_at' => now(),
            ]);

        // Настройки кешируются навсегда; без сброса сайт продолжит строить
        // адреса со старым значением.
        if ($updated > 0) {
            Setting::flushCache();
        }
    }
};
