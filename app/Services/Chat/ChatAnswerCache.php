<?php

namespace App\Services\Chat;

use App\Services\Ai\Data\AssistantReply;
use Illuminate\Support\Facades\Cache;

/**
 * Повторный вопрос — без вызова модели.
 *
 * Крупный рычаг по деньгам у магазина с узкой базой знаний: «как оплатить»,
 * «везёте ли в регионы», «есть ли гарантия» — это не редкие вопросы, это
 * заметная доля трафика чата. Ход у нас стоит 0,25–0,34 ₽, и каждый такой
 * повтор оплачивается ровно за тот же ответ.
 *
 * ЧТО КЛАДЁТСЯ В КЭШ — вопрос куда более важный, чем как он устроен, потому
 * что цена ошибки здесь несимметрична: лишний вызов модели стоит копейки,
 * а выданный из кэша неверный ответ — покупателя. Поэтому условий пять,
 * и каждое отсекает свой способ соврать:
 *
 *   1) ПЕРВЫЙ ход разговора. «А сколько он стоит?» без предыдущей реплики
 *      не значит ничего;
 *   2) ход удался и ничем не кончился — ни эскалацией, ни просьбой
 *      контактов: это состояния РАЗГОВОРА, а не ответы;
 *   3) товарные инструменты не вызывались. Цена и наличие живые, и
 *      «в наличии, 45 900 ₽» недельной свежести — худший из возможных
 *      ответов: он выглядит точным;
 *   4) поиск по базе что-то нашёл (не kb_miss). Промахи — это сырьё
 *      экрана «Пробелы», и кэшировать их значило бы прятать от админа
 *      ровно те вопросы, ради которых экран сделан;
 *   5) ответ непустой.
 *
 * ОТПЕЧАТОК. Ключ считается не по одному тексту вопроса: тот же вопрос со
 * страницы товара, от вошедшего покупателя или после правки правил магазина
 * в админке — это другой вопрос к модели. Всё, что меняет системный промпт,
 * входит в отпечаток.
 */
final class ChatAnswerCache
{
    /** Инструмент, после которого ответ ещё можно кэшировать. */
    private const SAFE_TOOL = 'search_knowledge_base';

    public function __construct(
        private readonly int $ttl,
        private readonly float $minScore,
    ) {}

    /**
     * @param  array<string, mixed>  $fingerprint
     * @return array{text: string, citations: list<array<string, mixed>>}|null
     */
    public function get(string $question, array $fingerprint): ?array
    {
        if ($this->ttl <= 0) {
            return null;
        }

        $hit = Cache::get($this->key($question, $fingerprint));

        return is_array($hit) && isset($hit['text']) && is_string($hit['text']) ? [
            'text' => $hit['text'],
            'citations' => is_array($hit['citations'] ?? null) ? $hit['citations'] : [],
        ] : null;
    }

    /**
     * @param  array<string, mixed>  $fingerprint
     */
    public function put(string $question, array $fingerprint, AssistantReply $reply): void
    {
        if ($this->ttl <= 0) {
            return;
        }

        Cache::put($this->key($question, $fingerprint), [
            'text' => $reply->text,
            'citations' => $reply->citations,
        ], $this->ttl);
    }

    public function isCacheable(AssistantReply $reply, bool $firstTurn): bool
    {
        if (! $firstTurn) {
            return false;
        }

        if ($reply->stopReason !== 'stop' || trim($reply->text) === '') {
            return false;
        }

        if ($reply->escalated || $reply->callbackRequested) {
            return false;
        }

        foreach ($reply->toolNames() as $name) {
            if ($name !== self::SAFE_TOOL) {
                return false;
            }
        }

        return ! $reply->isKbMiss($this->minScore);
    }

    /**
     * Нормализация формулировки.
     *
     * Дешёвая и намеренно грубая: регистр, «ё», знаки препинания и лишние
     * пробелы. Цель — свести «Как оплатить?», «как оплатить» и «Как
     * оплатить...» к одной строке, а не понять смысл: понимать смысл умеет
     * векторный поиск, но он сам стоит вызова шлюза.
     */
    public static function normalize(string $question): string
    {
        $text = mb_strtolower(trim($question));
        $text = str_replace('ё', 'е', $text);
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?? $text;

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * @param  array<string, mixed>  $fingerprint
     */
    private function key(string $question, array $fingerprint): string
    {
        ksort($fingerprint);

        return 'chat:answer:'.hash('sha256', json_encode(
            [self::normalize($question), $fingerprint],
            JSON_UNESCAPED_UNICODE,
        ) ?: '');
    }
}
