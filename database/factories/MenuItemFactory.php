<?php

namespace Database\Factories;

use App\Models\Menu;
use App\Models\MenuItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MenuItem>
 */
class MenuItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'menu_id' => Menu::factory(),
            'parent_id' => null,
            'label' => fake()->words(2, true),
            'type' => 'url',
            'url' => fake()->url(),
            'route_name' => null,
            'route_params' => null,
            'page_id' => null,
            'sort' => 0,
            'is_active' => true,
            'target' => null,
            'rel' => null,
        ];
    }
}
