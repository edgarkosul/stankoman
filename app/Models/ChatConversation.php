<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Разговор посетителя с ассистентом.
 *
 * @property int $id
 * @property string $token
 * @property string $status
 * @property bool $assistant_enabled
 * @property int $messages_count
 */
class ChatConversation extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const STATUS_BOT = 'bot';

    public const STATUS_OPERATOR = 'operator';

    public const STATUS_CLOSED = 'closed';

    /** Вопрос у менеджера, но отвечать он ещё не начал. */
    public const STAFF_WORKING = 'working';

    /** Менеджер набирает ответ прямо сейчас. */
    public const STAFF_TYPING = 'typing';

    protected $fillable = [
        'token',
        'user_id',
        'status',
        'assistant_enabled',
        'escalated_at',
        'operator_id',
        'last_message_at',
        'last_seen_at',
        'messages_count',
        'input_tokens',
        'output_tokens',
        'cached_tokens',
        'cost_rub',
        'callback_request_id',
        'consent_at',
        'ip_hash',
        'user_agent',
        'referer_url',
        'unread_for_visitor',
        'unread_for_staff',
    ];

    protected function casts(): array
    {
        return [
            'assistant_enabled' => 'bool',
            'escalated_at' => 'datetime',
            'last_message_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'consent_at' => 'datetime',
            'cost_rub' => 'float',
        ];
    }

    /** @return HasMany<ChatMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    /**
     * Последняя реплика — для колонки «о чём говорят» в списке диалогов.
     * Отношением, а не подзапросом в колонке: иначе двадцать пять строк
     * таблицы это двадцать пять отдельных запросов.
     *
     * @return HasOne<ChatMessage, $this>
     */
    public function latestMessage(): HasOne
    {
        return $this->hasOne(ChatMessage::class)->latestOfMany()->withoutEmbedding();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    /** @return BelongsTo<CallbackRequest, $this> */
    public function callbackRequest(): BelongsTo
    {
        return $this->belongsTo(CallbackRequest::class);
    }

    /**
     * Токен из вечной httpOnly-куки — единственный ключ анонима к переписке.
     */
    public function scopeByToken(Builder $query, string $token): Builder
    {
        return $query->where('token', $token);
    }

    /**
     * 40 символов из алфавита Str::random — 238 бит. Секрет живёт в куке
     * годами и даёт доступ к переписке, в которой может быть телефон,
     * так что перебор должен быть безнадёжен.
     */
    public static function freshToken(): string
    {
        return Str::random(40);
    }

    /**
     * Ключ сессии для кэша промпта на шлюзе.
     *
     * Один на разговор и стабильный во времени: кэш префикса привязан
     * к сессии, и без общего ключа каждый ход оплачивался бы как первый
     * (замер донора 28.08.2026 — втрое дороже).
     *
     * Хэш, а не сам токен: токен даёт доступ к переписке, и отправлять
     * его наружу незачем.
     */
    public function promptSessionId(): string
    {
        return substr(hash('sha256', 'intertooler-chat|'.$this->token), 0, 32);
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    /** Разговор ведёт бот: не закрыт, не перехвачен оператором, тумблер цел. */
    public function isBotLed(): bool
    {
        return $this->status === self::STATUS_BOT && $this->assistant_enabled;
    }

    public function isEscalated(): bool
    {
        return $this->escalated_at !== null;
    }

    /** Разговор перехвачен живым оператором — бот в него не вмешивается. */
    public function isOperatorLed(): bool
    {
        return $this->status === self::STATUS_OPERATOR;
    }

    /**
     * Разговоры, ждущие человека.
     *
     * Два случая, и они разные. Первый: бот передал вопрос менеджеру,
     * но разговор до сих пор ведёт он — значит, эскалацию никто не взял.
     * Второй: оператор уже за рулём, и посетитель написал ему что-то,
     * чего он ещё не прочитал.
     *
     * Разговор у оператора БЕЗ непрочитанного в счётчик не идёт: там
     * последнее слово за человеком, и напоминать ему о собственном
     * ответе незачем.
     */
    public function scopeAwaitingStaff(Builder $query): Builder
    {
        return $query
            ->where('status', '!=', self::STATUS_CLOSED)
            ->where(function (Builder $query): void {
                $query
                    ->where(fn (Builder $q) => $q
                        ->where('status', self::STATUS_BOT)
                        ->whereNotNull('escalated_at'))
                    ->orWhere(fn (Builder $q) => $q
                        ->where('status', self::STATUS_OPERATOR)
                        ->where('unread_for_staff', '>', 0));
            });
    }

    /**
     * Чем занят менеджер — глазами посетителя.
     *
     * Живёт на модели, а не в компоненте, чтобы быть проверяемой: правило
     * короткое, но у него три развилки, и ошибка в любой означает либо
     * обещание, которого магазин не давал, либо тишину там, где человек
     * ждёт ответа лично.
     *
     * Признак набора приходит снаружи: он живёт в кэше, а модель о кэше
     * знать не должна.
     *
     * @param  string|null  $lastMessageRole  роль последней реплики в ленте
     * @return string|null STAFF_TYPING, STAFF_WORKING или null
     */
    public function staffActivity(bool $typing, ?string $lastMessageRole): ?string
    {
        if ($this->isClosed()) {
            return null;
        }

        /*
         * Набор показываем даже в разговоре, который формально ещё ведёт
         * бот. Пометка о наборе — не догадка, а прямое свидетельство:
         * кто-то прямо сейчас пишет в это окно из админки.
         */
        if ($typing) {
            return self::STAFF_TYPING;
        }

        /*
         * А вот «в работе» — только про разговор, который менеджер уже
         * взял. Переданный, но никем не взятый сюда не попадает: за столом
         * может не быть никого, и это стало бы обещанием, которого магазин
         * не давал. Про такой разговор посетителю говорит карточка контактов.
         */
        if (! $this->isOperatorLed()) {
            return null;
        }

        // Последнее слово за менеджером — ждать нечего, и «в работе»
        // под его же репликой означало бы, что надо ждать снова.
        return $lastMessageRole === ChatMessage::ROLE_OPERATOR ? null : self::STAFF_WORKING;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_OPERATOR => 'у оператора',
            self::STATUS_CLOSED => 'закрыт',
            default => $this->isEscalated() ? 'ждёт менеджера' : 'ведёт бот',
        };
    }
}
