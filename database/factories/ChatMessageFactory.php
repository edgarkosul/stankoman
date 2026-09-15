<?php

namespace Database\Factories;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatMessage>
 */
class ChatMessageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'chat_conversation_id' => ChatConversation::factory(),
            'role' => ChatMessage::ROLE_VISITOR,
            'body' => 'Какая у вас доставка?',
        ];
    }

    public function fromAssistant(string $stopReason = 'stop'): static
    {
        return $this->state(fn (): array => [
            'role' => ChatMessage::ROLE_ASSISTANT,
            'body' => 'Доставляем транспортной компанией по всей России.',
            'stop_reason' => $stopReason,
        ]);
    }
}
