<?php

namespace App\Services\Ai\Data;

/**
 * Итог одного обращения к ассистенту.
 *
 * Провал — не исключение, а штатный исход со своим stopReason. Решать, что
 * показать посетителю, обязан вызывающий: в чате это мягкая эскалация,
 * в песочнице админа — честное «модель не справилась», в замере — строка
 * статистики. Поэтому текст-заглушку агент не выдумывает.
 */
final readonly class AssistantReply
{
    /**
     * @param  list<array<string, mixed>>  $messages  история, пригодная для следующего хода
     * @param  list<array{chunk_id: string, score: float, title: string, url: ?string}>  $citations
     * @param  list<array{name: string, arguments: array<string, mixed>, ms: int}>  $toolCalls
     */
    public function __construct(
        public string $text,
        public string $stopReason,
        public array $messages = [],
        public array $toolCalls = [],
        public array $citations = [],
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $cachedTokens = 0,
        public float $costRub = 0.0,
        public bool $escalated = false,
        public bool $callbackRequested = false,
        /** Фраза модели для менеджера: ради чего его позвали. */
        public ?string $escalationReason = null,
        /** Тема обратного звонка — идёт в комментарий заявки. */
        public ?string $callbackTopic = null,
        public ?float $bestScore = null,
        /**
         * Вектор вопроса, посчитанный поиском по базе знаний. Уезжает
         * в `chat_messages.embedding` — по нему экран «Пробелы» группирует
         * вопросы по смыслу, не обращаясь к шлюзу заново.
         *
         * @var list<float>|null
         */
        public ?array $questionEmbedding = null,
        public int $latencyMs = 0,
        /** Модель, назвавшаяся в ответе шлюза. Уезжает в chat_messages.model. */
        public string $model = '',
    ) {}

    /**
     * Одни имена вызванных инструментов — для лога, где подробности
     * аргументов только мешают: они лежат в базе рядом с ответом.
     *
     * @return list<string>
     */
    public function toolNames(): array
    {
        return array_column($this->toolCalls, 'name');
    }

    /**
     * Ответа для посетителя нет: модель исчерпала шаги, вернула пустоту,
     * проговорилась об идентичности, выдала заглушку редактора вместо
     * контакта, вызов упал или шлюз заблокировал запрос.
     */
    public function isFailure(): bool
    {
        return in_array($this->stopReason, ['max_iterations', 'empty', 'contaminated', 'placeholder_leak', 'error', 'pii_blocked'], true);
    }

    /**
     * Поиск не нашёл ничего релевантного. Сигнал СЛАБЫЙ: замер показал, что
     * по нему не отличить «в базе дыра» от «спросили не про магазин».
     * Годится как один вход из трёх на экране «Пробелы», не как вывод.
     */
    public function isKbMiss(float $minScore): bool
    {
        return $this->bestScore !== null && $this->bestScore < $minScore;
    }
}
