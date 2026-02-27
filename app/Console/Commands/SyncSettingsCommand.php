<?php

namespace App\Console\Commands;

use App\Enums\SettingType;
use App\Models\Setting;
use Illuminate\Console\Command;

class SyncSettingsCommand extends Command
{
    protected $signature = 'settings:sync
                            {--force : Override existing values with config defaults}';

    protected $description = 'Sync settings table with config/settings.php';

    public function handle(): int
    {
        $config = config('settings', []);

        if (! is_array($config)) {
            $this->error('config("settings") must be an array.');

            return self::FAILURE;
        }

        $flat = $this->flattenConfig($config);

        $created = 0;
        $updated = 0;

        foreach ($flat as $key => $default) {
            $type = $this->inferType($default);
            $value = $this->prepareValue($default, $type);

            $setting = Setting::query()->where('key', $key)->first();

            if (! $setting instanceof Setting) {
                Setting::query()->create([
                    'key' => $key,
                    'type' => $type,
                    'value' => $value,
                    'autoload' => true,
                    'description' => null,
                ]);

                $created++;
                $this->line("Created: {$key}");

                continue;
            }

            $changes = [];

            if (! $setting->type instanceof SettingType || $setting->type !== $type) {
                $changes['type'] = $type;
            }

            if ((bool) $this->option('force')) {
                $changes['value'] = $value;
            }

            if ($changes !== []) {
                $setting->fill($changes)->save();
                $updated++;
                $this->line("Updated: {$key}");
            }
        }

        $this->info("Sync completed. Created: {$created}, Updated: {$updated}.");

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    protected function flattenConfig(array $config, string $prefix = ''): array
    {
        $result = [];

        foreach ($config as $key => $value) {
            $fullKey = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (is_array($value) && $this->isAssoc($value)) {
                $result += $this->flattenConfig($value, $fullKey);
            } else {
                $result[$fullKey] = $value;
            }
        }

        return $result;
    }

    protected function isAssoc(array $array): bool
    {
        return array_keys($array) !== range(0, count($array) - 1);
    }

    protected function inferType(mixed $value): SettingType
    {
        return match (true) {
            is_bool($value) => SettingType::Bool,
            is_int($value) => SettingType::Int,
            is_float($value) => SettingType::Float,
            is_array($value) => SettingType::Json,
            default => SettingType::String,
        };
    }

    protected function prepareValue(mixed $value, SettingType $type): ?string
    {
        return match ($type) {
            SettingType::Json => json_encode($value, JSON_UNESCAPED_UNICODE),
            default => $value === null ? null : (string) $value,
        };
    }
}
