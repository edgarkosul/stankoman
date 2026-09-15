<?php

namespace Database\Factories;

use App\Models\KbArticle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KbArticle>
 */
class KbArticleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kb_category_id' => null,
            'title' => fake()->sentence(4),
            'content' => self::document(fake()->paragraph()),
            'public_url' => null,
            'is_published' => true,
            'position' => 0,
        ];
    }

    public function draft(): static
    {
        return $this->state(['is_published' => false]);
    }

    /**
     * Документ Tiptap из абзацев — в том виде, в каком его сохраняет редактор статей.
     *
     * @return array{type: string, content: list<array<string, mixed>>}
     */
    public static function document(string ...$paragraphs): array
    {
        return [
            'type' => 'doc',
            'content' => array_map(
                static fn (string $text): array => [
                    'type' => 'paragraph',
                    'content' => [['type' => 'text', 'text' => $text]],
                ],
                array_values($paragraphs),
            ),
        ];
    }
}
