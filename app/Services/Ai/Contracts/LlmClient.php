<?php

namespace App\Services\Ai\Contracts;

use App\Services\Ai\Data\ChatResult;
use App\Services\Ai\Data\EmbeddingBatch;

/**
 * Языковая модель и эмбеддинги за одним интерфейсом.
 *
 * Смысл абстракции — не «а вдруг захотим сменить вендора», а вполне конкретная
 * задача: в фазе замера мы гоняем один и тот же набор вопросов через несколько
 * моделей и сравниваем. Без общего интерфейса это превращается в правку кода
 * агента под каждого кандидата. Вторая причина — заглушка без сети для тестов.
 */
interface LlmClient
{
    /**
     * Один ход диалога. Может вернуться как текст, так и запрос на вызов
     * инструментов — цикл tool-use живёт в агенте, а не здесь.
     *
     * @param  list<array<string, mixed>>  $messages  история в формате OpenAI:
     *                                                role = user|assistant|tool
     * @param  list<array<string, mixed>>  $tools  описания инструментов; пустой
     *                                             массив — обычный ответ текстом
     * @param  string|null  $sessionId  ключ сессии для кеша промпта. Передавать
     *                                  ВСЕГДА, где он есть: без него привязка
     *                                  к провайдеру включается только после
     *                                  первого попадания в кэш, то есть на
     *                                  коротких диалогах не включается никогда.
     *                                  Разница по деньгам — втрое.
     * @param  string|null  $toolChoice  'auto' | 'none' | 'required' | имя инструмента.
     *                                   Агент задаёт его на КРАЯХ цикла: на первом ходе
     *                                   требует вызова, на последнем запрещает, чтобы
     *                                   ход ушёл на ответ, а не на очередной вызов.
     */
    public function chat(
        string $system,
        array $messages,
        array $tools = [],
        ?int $maxTokens = null,
        ?string $sessionId = null,
        ?string $toolChoice = null,
    ): ChatResult;

    /**
     * Векторы для списка текстов, в порядке передачи.
     *
     * @param  list<string>  $texts
     * @param  'doc'|'query'  $mode  у симметричных моделей (наш случай — Qwen)
     *                               вырождается в no-op. Из интерфейса не убирать:
     *                               асимметричные пары «документ/запрос» существуют,
     *                               и перепутанные местами они дают тихо плохой
     *                               поиск вместо явной ошибки.
     */
    public function embed(array $texts, string $mode = 'doc'): EmbeddingBatch;

    /** Имя модели диалога — для телеметрии и для колонки embed_model рядом с вектором. */
    public function chatModel(): string;

    /** Имя модели эмбеддингов. */
    public function embeddingModel(): string;

    /** Размерность вектора. Пишется рядом с каждым вектором и сверяется при поиске. */
    public function embeddingDimensions(): int;
}
