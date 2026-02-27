<?php

namespace Database\Seeders;

use App\Enums\SettingType;
use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    public function run(): void
    {
        Setting::query()->updateOrCreate(
            ['key' => 'product.stavka_nds'],
            [
                'value' => (string) config('settings.product.stavka_nds', 20),
                'type' => SettingType::Int,
                'autoload' => true,
                'description' => 'Ставка НДС в процентах.',
            ],
        );
    }
}
