<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Одно сообщение переписки. У ответа бота рядом лежит его телеметрия:
 * чем закончился ход, что вызывалось, что нашлось, сколько стоило.
 *
 * @property int $id
 * @property string $role
 * @property string $body
 */
class ChatMessage extends Model
{
    use HasFactory;

    public const ROLE_VISITOR = 'visitor';

    public const ROLE_ASSISTANT = 'assistant';

    public const ROLE_OPERATOR = 'operator';

    /** Служебные пометки в ленте: «передал менеджеру», «разговор закрыт». */
    public const ROLE_SYSTEM = 'system';

    public const RATING_UP = 1;

    public const RATING_DOWN = -1;

    /**
     * Ход, кончившийся ничем: вместо ответа посетитель увидел заглушку.
     *
     * Надмножество `AssistantReply::isFailure()` — там перечислены провалы
     * самого агента, а сюда добавлены исходы, до агента не дошедшие вовсе:
     * очередь не приняла задачу, воркер её не взял, бот выключен рубильником.
     * Для админа это один и тот же вопрос «почему покупатель остался
     * без ответа».
     */
    public const FAILED_STOP_REASONS = [
        'max_iterations',
        'empty',
        'contaminated',
        'placeholder_leak',
        'error',
        'exception',
        'pii_blocked',
        'disabled',
        'crashed',
        'not_queued',
        'stalled',
        'queue_stuck',
        'daily_budget',
    ];

    protected $fillable = [
        'chat_conversation_id',
        'role',
        'body',
        'operator_id',
        'stop_reason',
        'tool_calls',
        'citations',
        'input_tokens',
        'output_tokens',
        'cached_tokens',
        'cost_rub',
        'latency_ms',
        'model',
        'rating',
        'kb_miss',
        'embedding',
        'page_context',
        'meta',
        'read_at',
    ];

    /**
     * Вектор наружу не отдаём никогда. Это 4 КБ бинарного мусора, который
     * ломает json_encode невалидным UTF-8, — а модель сообщения ездит
     * в снапшотах Livewire.
     *
     * @var list<string>
     */
    protected $hidden = ['embedding'];

    protected function casts(): array
    {
        return [
            'tool_calls' => 'array',
            'citations' => 'array',
            'page_context' => 'array',
            'meta' => 'array',
            'kb_miss' => 'bool',
            // Сравнивается с RATING_UP/RATING_DOWN строгим равенством —
            // строка из драйвера сломала бы «повторный клик снимает оценку».
            'rating' => 'integer',
            'cost_rub' => 'float',
            'read_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ChatConversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'chat_conversation_id');
    }

    /** @return BelongsTo<User, $this> */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    /**
     * Лента без вектора вопроса.
     *
     * Панель перечитывает переписку на тиках опроса, а вектор там не нужен:
     * без этого ограничения виджет тянул бы из базы лишние сотни килобайт.
     *
     * Имена колонок квалифицируем таблицей: `latestMessage()` строится
     * через `latestOfMany()`, а это join с производной таблицей, и голый
     * `chat_conversation_id` в списке полей стал бы неоднозначным.
     */
    public function scopeWithoutEmbedding(Builder $query): Builder
    {
        $table = $this->getTable();

        $columns = array_merge(
            ['id', 'created_at', 'updated_at'],
            array_values(array_diff($this->getFillable(), ['embedding'])),
        );

        return $query->select(array_map(
            static fn (string $column): string => $table.'.'.$column,
            $columns,
        ));
    }

    /**
     * Вызовы инструментов в едином виде `{name, arguments, ms}`.
     *
     * @return list<array{name: string, arguments: array<string, mixed>, ms: int}>
     */
    public function toolCallsDetailed(): array
    {
        $calls = [];

        foreach ((array) ($this->tool_calls ?? []) as $call) {
            if (is_string($call)) {
                $calls[] = ['name' => $call, 'arguments' => [], 'ms' => 0];

                continue;
            }

            if (! is_array($call) || ! is_string($call['name'] ?? null)) {
                continue;
            }

            $calls[] = [
                'name' => $call['name'],
                'arguments' => is_array($call['arguments'] ?? null) ? $call['arguments'] : [],
                'ms' => (int) ($call['ms'] ?? 0),
            ];
        }

        return $calls;
    }

    /**
     * Текст служебной пометки, написанный для покупателя, — или null,
     * если пометка касается только магазина.
     *
     * Видит покупатель далеко не всё: «Оператор Эдгар взял разговор
     * в работу» это заметка на полях, а вот «менеджер передал разговор
     * консультанту» он обязан знать — иначе на экране остаётся обещание,
     * которое перестало быть правдой.
     */
    public function visitorNote(): ?string
    {
        if ($this->role !== self::ROLE_SYSTEM) {
            return null;
        }

        $body = $this->meta['visitor_body'] ?? null;

        return is_string($body) && trim($body) !== '' ? $body : null;
    }

    public function isFromVisitor(): bool
    {
        return $this->role === self::ROLE_VISITOR;
    }

    public function isFromAssistant(): bool
    {
        return $this->role === self::ROLE_ASSISTANT;
    }

    /**
     * Есть ли смысл спрашивать «помог ли ответ».
     *
     * Заглушку («не получается ответить», «передал менеджеру») оценивать
     * нечего: она не ответ бота, а признание, что ответа нет. Собранные
     * по ней 👎 засоряли бы экран «Пробелы» тем, что и так известно.
     */
    public function isRateable(): bool
    {
        return $this->isFromAssistant()
            && trim((string) $this->body) !== ''
            && ! in_array((string) $this->stop_reason, self::FAILED_STOP_REASONS, true)
            && (string) $this->stop_reason !== 'handed_to_operator';
    }
}
