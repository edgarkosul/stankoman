<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SurveyResponse extends Model
{
    /** Анкета по ценам и Excel-импорту (август 2026). */
    public const SURVEY_PRICING = 'pricing_2026_08';

    /** Вопросы владельцу перед запуском ИИ-помощника (сентябрь 2026): одна строка на каждую отправку. */
    public const SURVEY_BOT_KNOWLEDGE = 'bot_knowledge_2026_09';

    /**
     * Черновик той же анкеты — одна строка, переписывается на каждое сохранение.
     * Отдельным ключом, а не последней отправкой: автосохранение не должно
     * выглядеть как «владелец закончил и отправил».
     */
    public const SURVEY_BOT_KNOWLEDGE_DRAFT = 'bot_knowledge_2026_09_draft';

    protected $fillable = [
        'survey',
        'user_id',
        'answers',
    ];

    protected $casts = [
        'answers' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function latestFor(string $survey): ?self
    {
        return self::query()
            ->where('survey', $survey)
            ->latest('id')
            ->first();
    }
}
