<?php

namespace App\Models;

use App\Services\Ai\Data\AssistantReply;
use Illuminate\Database\Eloquent\Model;

/**
 * Строка расходной книги: один ход бота, оплаченный шлюзу.
 *
 * Существует отдельно от `chat_messages`, потому что переписку покупатель
 * может стереть, а потраченные деньги — нет. Подробности в миграции.
 *
 * Дописывается и не правится: `updated_at` у таблицы нет намеренно.
 *
 * ЧТО СЮДА НЕ ПОПАДАЕТ: прогоны `ai:chat`. Они тоже стоят денег, но это
 * расход на разработку, а книга отвечает на вопрос «сколько стоит бот
 * на витрине». Смешать их значило бы объявить владельцу магазина ценой
 * обслуживания покупателей ещё и стоимость отладки.
 */
class AiUsageEntry extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'conversation_ref',
        'model',
        'stop_reason',
        'input_tokens',
        'cached_tokens',
        'output_tokens',
        'cost_rub',
        'latency_ms',
        'escalated',
        'callback',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'cost_rub' => 'float',
            'escalated' => 'bool',
            'callback' => 'bool',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Что записать по этому ходу.
     *
     * Отдельным чистым методом, а не сборкой массива по месту: это
     * единственное место, где решается, что попадёт в бухгалтерию,
     * и его надо проверять тестом без базы.
     *
     * ЗАГЛУШКИ И ОТВЕТЫ ИЗ КЭША ЗАПИСЫВАЮТСЯ ТОЖЕ, с нулями. Иначе доля
     * бесплатных ответов считалась бы от одних платных и всегда равнялась
     * бы нулю, а именно она показывает, работает ли кэш повторных вопросов.
     *
     * @return array<string, mixed>
     */
    public static function attributesFor(
        ?AssistantReply $reply,
        ?int $conversationRef,
        bool $escalated = false,
        ?string $stopReason = null,
    ): array {
        return [
            'conversation_ref' => $conversationRef,
            'model' => $reply?->model ?: null,
            'stop_reason' => $reply?->stopReason ?: $stopReason,
            'input_tokens' => max(0, $reply?->inputTokens ?? 0),
            'cached_tokens' => max(0, $reply?->cachedTokens ?? 0),
            'output_tokens' => max(0, $reply?->outputTokens ?? 0),
            'cost_rub' => round(max(0.0, $reply?->costRub ?? 0.0), 4),
            'latency_ms' => max(0, $reply?->latencyMs ?? 0),
            // Эскалация приходит снаружи: её поднимает и сам бот
            // инструментом, и аварийная передача менеджеру, у которой
            // ответа нет вовсе.
            'escalated' => $escalated || (bool) $reply?->escalated,
            'callback' => (bool) $reply?->callbackRequested,
        ];
    }
}
