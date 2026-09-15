<?php

namespace Database\Factories;

use App\Models\ChatConversation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatConversation>
 */
class ChatConversationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'token' => ChatConversation::freshToken(),
            'status' => ChatConversation::STATUS_BOT,
            'assistant_enabled' => true,
            'ip_hash' => hash('sha256', '127.0.0.1'),
        ];
    }

    public function escalated(): static
    {
        return $this->state(fn (): array => ['escalated_at' => now()]);
    }

    public function operatorLed(): static
    {
        return $this->state(fn (): array => [
            'status' => ChatConversation::STATUS_OPERATOR,
            'escalated_at' => now(),
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn (): array => ['status' => ChatConversation::STATUS_CLOSED]);
    }
}
