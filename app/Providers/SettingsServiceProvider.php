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
            $value = $setting->getValueForConfig();

            if ($this->shouldSkipOverride($setting->key, $value)) {
                continue;
            }

            config()->set(
                $this->resolveConfigPath($setting->key),
                $value,
            );
        }
    }

    protected function resolveConfigPath(string $key): string
    {
        if (str_starts_with($key, 'company.') || str_starts_with($key, 'mail.')) {
            return $key;
        }

        return 'settings.'.$key;
    }

    protected function shouldSkipOverride(string $key, mixed $value): bool
    {
        if (! in_array($key, [
            'general.manager_emails',
            'general.filament_admin_emails',
            'company.public_email',
            'mail.from.address',
        ], true)) {
            return false;
        }

        return blank($value);
    }
}
