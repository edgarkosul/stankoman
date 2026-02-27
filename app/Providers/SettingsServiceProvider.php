<?php

namespace App\Providers;

use App\Models\Setting;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class SettingsServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        try {
            $settings = cache()->rememberForever(Setting::CACHE_KEY, function () {
                return Setting::query()
                    ->where('autoload', true)
                    ->get();
            });
        } catch (\Throwable) {
            return;
        }

        foreach ($settings as $setting) {
            config()->set(
                'settings.'.$setting->key,
                $setting->getValueForConfig(),
            );
        }
    }
}
