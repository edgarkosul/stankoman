<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SurveyResponse extends Model
{
    /** Анкета по ценам и Excel-импорту (август 2026). */
    public const SURVEY_PRICING = 'pricing_2026_08';

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
