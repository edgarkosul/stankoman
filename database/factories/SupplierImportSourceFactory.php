<?php

namespace Database\Factories;

use App\Models\Supplier;
use App\Models\SupplierImportSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierImportSource>
 */
class SupplierImportSourceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'supplier_id' => Supplier::factory(),
            'name' => fake()->unique()->words(3, true),
            'driver_key' => 'vactool_html',
            'profile_key' => 'vactool_html',
            'settings' => [
                'sitemap' => 'https://vactool.ru/sitemap.xml',
                'match' => '/catalog/product-',
                'delay_ms' => 250,
                'download_images' => true,
            ],
            'is_active' => true,
            'sort' => 0,
            'last_used_at' => null,
        ];
    }
}
