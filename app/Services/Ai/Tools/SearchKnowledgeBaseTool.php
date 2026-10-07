<?php

namespace App\Services\Ai\Tools;

use App\Services\Ai\Support\PiiRedactor;
use App\Services\Kb\KbVectorStore;
use Throwable;

/**
 * Поиск по базе знаний магазина: условия заказа, оплата, доставка, гарантия,
 * возврат, документы, контакты — и то, что магазин СОВЕТУЕТ из своего
 * ассортимента.
 *
 * Второе добавлено 07.10.2026, и вот почему. В описании инструмента стояли
 * одни условия магазина, а системный промпт отдельно велит брать факты
 * о товаре из каталога. Модель это и делала: на «какая винтовая пара
 * в компрессорах CrossAir и Hansmann» она сделала два поиска и ШЕСТЬ
 * запросов карточек — ровно потолок `max_iterations`, то есть упёрлась
 * в лимит, а не закончила, — 51 570 токенов, 0.82 ₽ и 50 секунд против
 * обычных 0.3 ₽ и десяти. Ответ на этот же вопрос лежал в статье,
 * написанной по словам владельца, и находился ПЕРВЫМ (0.7241) за один
 * вызов. Просто искать его здесь модели никто не разрешал.
 *
 * ⚠️ Граница в описании не украшение, а условие правки: цена, наличие
 * и характеристики конкретной модели остаются только за каталогом. Статья
 * про ассортимент стареет, а карточка живая, и дешёвую ошибку «не заглянул
 * в базу» легко обменять на дорогую «назвал цену из статьи».
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
                    .'Здесь же лежит то, что магазин СОВЕТУЕТ из своего ассортимента: какие '
                    .'бренды смотреть в первую очередь, чем они отличаются, что предложить '
                    .'при скромном бюджете. Вызывай перед любым ответом по этим темам, перед '
                    .'советом «что лучше взять» и перед тем, как сказать, что информации нет. '
                    .'ЦЕНУ, НАЛИЧИЕ И ХАРАКТЕРИСТИКИ конкретной модели здесь не ищи: они живут '
                    .'только в каталоге (search_products, get_product). Если статья и карточка '
                    .'расходятся в числах, прав каталог.',
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
