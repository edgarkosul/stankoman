<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ShippingMethod;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Order>
 */
class OrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $orderDate = fake()->dateTimeBetween('-30 days');

        return [
            'user_id' => User::factory(),
            'order_date' => $orderDate,
            'seq' => fake()->numberBetween(1, 99),
            'order_number' => fake()->regexify('\d{2}-\d{2}-\d{2}/\d{2}'),
            'status' => OrderStatus::Submitted->value,
            'payment_status' => PaymentStatus::Awaiting->value,
            'is_company' => false,
            'customer_name' => fake()->name(),
            'customer_email' => fake()->safeEmail(),
            'customer_phone' => '+7'.fake()->numerify('9#########'),
            'shipping_method' => ShippingMethod::Delivery->value,
            'payment_method' => 'cash',
            'items_subtotal' => fake()->randomFloat(2, 1000, 100000),
            'discount_total' => 0,
            'shipping_total' => 0,
            'grand_total' => fake()->randomFloat(2, 1000, 100000),
            'currency' => 'RUB',
            'public_hash' => fake()->sha1(),
            'submitted_at' => now(),
        ];
    }
}
