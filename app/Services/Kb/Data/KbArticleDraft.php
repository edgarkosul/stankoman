<?php

namespace App\Services\Kb\Data;

/**
 * Черновик статьи, собранный ботом по группе вопросов покупателей.
 *
 * Черновик — предложение, а не ответ. Он открывается в форме, человек правит
 * и публикует; без его подписи в базу знаний не попадает ничего. Это и есть
 * безопасная форма «обучения на диалогах»: бот подсказывает, чего не хватает,
 * но не закрепляет собственные ошибки — между ним и базой стоит человек.
 */
final readonly class KbArticleDraft
{
    /**
     * @param  list<string>  $paragraphs  текст статьи абзацами
     * @param  list<string>  $missingFacts  чего в материалах не нашлось —
     *                                      это и есть работа, которую человек
     *                                      обязан доделать руками
     * @param  list<KbHit>  $sources  фрагменты базы, из которых собран черновик
     */
    public function __construct(
        public string $title,
        public array $paragraphs,
        public array $missingFacts = [],
        public array $sources = [],
        public float $costRub = 0.0,
        public string $model = '',
    ) {}

    public function isEmpty(): bool
    {
        return $this->paragraphs === [];
    }

    /**
     * Содержимое в формате редактора статей (Tiptap-документ).
     *
     * @return array{type: string, content: list<array<string, mixed>>}
     */
    public function toTiptap(): array
    {
        return [
            'type' => 'doc',
            'content' => array_map(
                static fn (string $text): array => [
                    'type' => 'paragraph',
                    'content' => [['type' => 'text', 'text' => $text]],
                ],
                $this->paragraphs,
            ),
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'paragraphs' => $this->paragraphs,
            'missing_facts' => $this->missingFacts,
            'sources' => array_map(static fn (KbHit $hit): array => [
                'title' => $hit->title,
                'url' => $hit->url,
                'score' => round($hit->score, 3),
            ], $this->sources),
            'cost_rub' => $this->costRub,
            'model' => $this->model,
        ];
    }
}
