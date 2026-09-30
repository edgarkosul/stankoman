<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SurveyResponse extends Model
{
    /** Анкета по ценам и Excel-импорту (август 2026). */
    public const SURVEY_PRICING = 'pricing_2026_08';

    /*
     * Анкеты «bot_knowledge_2026_09» здесь больше нет: владелец ответил
     * 27.09.2026, по ответам написаны статьи бота, страница опросника снята.
     * Сами ответы лежат в `docs/ответы-заказчика-по-боту-2026-09-27.md`;
     * строки в таблице оставлены как след, но из кода их никто не читает.
     */

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
