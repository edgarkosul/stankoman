<?php

namespace App\Services\Ai\Tools;

use App\Services\Ai\Support\PiiRedactor;
use App\Services\Kb\KbVectorStore;
use Throwable;

/**
 * Поиск по базе знаний магазина: условия заказа, оплата, доставка, гарантия,
 * возврат, документы, контакты.
 */
final class SearchKnowledgeBaseTool implements AssistantTool
{
    public function __construct(
        private readonly KbVectorStore $store,
        private readonly PiiRedactor $redactor,
        private readonly float $minScore,
        private readonly int $topK,
    ) {}

    public function name(): string
    {
        return 'search_knowledge_base';
    }

    public function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name(),
                'description' => 'Найти информацию об условиях магазина: как оформить и оплатить '
                    .'заказ, доставка и самовывоз, гарантия и сервис, возврат товара, документы '
                    .'для юридических лиц, кредит и лизинг, реквизиты, контакты и режим работы. '
                    .'Вызывай перед любым ответом по этим темам и перед тем, как сказать, '
                    .'что информации нет.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'Вопрос своими словами, как его задал бы покупатель.',
                        ],
                    ],
                    'required' => ['query'],
                ],
            ],
        ];
    }

    public function run(array $arguments, ToolContext $context): string
    {
        $query = trim((string) ($arguments['query'] ?? ''));

        if ($query === '') {
            return 'Пустой запрос. Сформулируй вопрос и повтори вызов.';
        }

        try {
            // Вопрос уходит на эмбеддинг, а на маршруте /embeddings маскирование
            // шлюза не работает вовсе — здесь наш редактор единственный слой.
            //
            // Вектор считаем сами и передаём в поиск готовым: он нужен ещё
            // и после хода — уезжает в chat_messages.embedding как сырьё
            // для кластеризации «Пробелов». Через store->search() он остался
            // бы внутри метода, и за него пришлось бы платить второй раз.
            $vector = $this->store->embedQuery($this->redactor->redact($query));
            $hits = $vector === [] ? [] : $this->store->searchByVector($vector, topK: $this->topK);
        } catch (Throwable $e) {
            // Модели — коротко и без внутренностей: она не должна пересказывать
            // посетителю, что у нас отвалился шлюз эмбеддингов.
            return 'Поиск временно недоступен. Предложи связаться с менеджером.';
        }

        if ($vector !== [] && $context->questionEmbedding === null) {
            $context->questionEmbedding = $vector;
        }

        if ($hits === []) {
            $context->bestScore = 0.0;

            return 'Ничего не найдено.';
        }

        $context->bestScore = max($context->bestScore ?? 0.0, $hits[0]->score);

        $relevant = array_values(array_filter(
            $hits,
            fn ($hit): bool => $hit->score >= $this->minScore,
        ));

        if ($relevant === []) {
            // Отсекаем сами, а не отдаём модели слабые совпадения: получив
            // хоть какой-то текст, она склонна построить на нём ответ.
            return 'В базе знаний магазина нет ответа на этот вопрос.';
        }

        $blocks = [];

        foreach ($relevant as $hit) {
            $context->citations[] = [
                'chunk_id' => $hit->chunkId,
                'score' => $hit->score,
                'title' => $hit->title,
                'url' => $hit->url,
            ];

            $blocks[] = $hit->url !== null
                ? $hit->text."\n[ссылка: {$hit->url}]"
                : $hit->text;
        }

        return implode("\n\n---\n\n", $blocks);
    }
}
