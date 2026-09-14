<?php

namespace Database\Factories;

use App\Models\CallbackRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CallbackRequest>
 */
class CallbackRequestFactory extends Factory
{
    protected $model = CallbackRequest::class;

    public function definition(): array
    {
        $phone = '+7999'.fake()->unique()->numerify('#######');

        return [
            'name' => fake()->name(),
            'phone' => $phone,
            'phone_hash' => hash('sha256', ltrim($phone, '+')),
            'source' => CallbackRequest::SOURCE_SITE,
            'status' => CallbackRequest::STATUS_PENDING,
        ];
    }

    public function fromChat(): static
    {
        return $this->state(fn (): array => [
            'source' => CallbackRequest::SOURCE_CHAT,
            'phone' => null,
            'phone_hash' => null,
            'email' => $email = fake()->unique()->safeEmail(),
            'email_hash' => hash('sha256', $email),
        ]);
    }
}
