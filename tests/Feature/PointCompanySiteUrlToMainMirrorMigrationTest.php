<?php

use App\Enums\SettingType;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

function companySiteUrlMigration(): object
{
    return require database_path('migrations/2026_09_17_000000_point_company_site_url_to_main_mirror.php');
}

function storeCompanySiteUrl(string $value): void
{
    DB::table('settings')->updateOrInsert(
        ['key' => 'company.site_url'],
        [
            'value' => $value,
            'type' => SettingType::String->value,
            'autoload' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    );
}

it('moves the default www site url to the main mirror and flushes the settings cache', function (): void {
    storeCompanySiteUrl('https://www.intertooler.ru');
    cache()->forever(Setting::CACHE_KEY, collect());

    companySiteUrlMigration()->up();

    expect(DB::table('settings')->where('key', 'company.site_url')->value('value'))
        ->toBe('https://intertooler.ru')
        ->and(cache()->has(Setting::CACHE_KEY))->toBeFalse();

    companySiteUrlMigration()->down();

    expect(DB::table('settings')->where('key', 'company.site_url')->value('value'))
        ->toBe('https://www.intertooler.ru');
});

it('keeps a site url that was set by hand', function (): void {
    storeCompanySiteUrl('https://shop.example.com');

    companySiteUrlMigration()->up();

    expect(DB::table('settings')->where('key', 'company.site_url')->value('value'))
        ->toBe('https://shop.example.com');
});
