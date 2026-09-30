<?php

namespace App\Models;

use App\Observers\KbArticleObserver;
use Database\Factories\KbArticleFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Статья базы знаний ИИ-ассистента.
 *
 * @property int $id
 * @property int|null $kb_category_id
 * @property string $title
 * @property array<string, mixed>|null $content
 * @property string|null $public_url
 * @property bool $is_published
 * @property Carbon|null $indexed_at
 * @property int $chunks_count
 */
#[ObservedBy([KbArticleObserver::class])]
class KbArticle extends Model
{
    /** @use HasFactory<KbArticleFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'kb_category_id',
        'title',
        'content',
        'public_url',
        'is_published',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'is_published' => 'bool',
            'indexed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<KbCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(KbCategory::class, 'kb_category_id');
    }

    /**
     * @param  Builder<KbArticle>  $query
     * @return Builder<KbArticle>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    /**
     * Ключ документа в индексе. Не slug и не url: статья переезжает между
     * разделами и меняет заголовок, а её фрагменты должны оставаться теми же.
     */
    public function documentKey(): string
    {
        return (string) $this->getKey();
    }

    /** Правки есть, а до индекса они ещё не доехали. */
    public function isIndexStale(): bool
    {
        if (! $this->is_published) {
            return false;
        }

        return $this->indexed_at === null || $this->indexed_at->lt($this->updated_at);
    }
}
