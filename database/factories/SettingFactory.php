<?php

namespace Database\Factories;

use App\Enums\SettingType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Setting>
 */
class SettingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'key' => 'test.'.fake()->unique()->slug(3),
            'value' => fake()->word(),
            'type' => SettingType::String,
            'description' => fake()->optional()->sentence(),
            'autoload' => fake()->boolean(80),
        ];
    }
}
