<?php

namespace App\Models;

use Database\Factories\KbCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Раздел базы знаний — папка для админа. В поиске участвует только словом
 * в крошках статьи.
 *
 * @property int $id
 * @property string $name
 * @property int $position
 */
class KbCategory extends Model
{
    /** @use HasFactory<KbCategoryFactory> */
    use HasFactory;

    protected $fillable = ['name', 'position'];

    /** @return HasMany<KbArticle, $this> */
    public function articles(): HasMany
    {
        return $this->hasMany(KbArticle::class);
    }
}
