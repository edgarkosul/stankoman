<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\OrderItem>
 */
class OrderItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $price = fake()->randomFloat(2, 1000, 100000);
        $quantity = fake()->numberBetween(1, 10);

        return [
            'order_id' => Order::factory(),
            'product_id' => Product::query()->inRandomOrder()->value('id'),
            'sku' => fake()->bothify('SKU-####'),
            'name' => fake()->words(3, true),
            'quantity' => $quantity,
            'price_amount' => $price,
            'total_amount' => round($price * $quantity, 2),
        ];
    }
}
