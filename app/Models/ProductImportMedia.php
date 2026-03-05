<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductImportMedia extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'run_id',
        'product_id',
        'source_url',
        'source_url_hash',
        'source_kind',
        'status',
        'mime_type',
        'bytes',
        'content_hash',
        'local_path',
        'attempts',
        'last_error',
        'processed_at',
        'meta',
    ];

    protected $casts = [
        'run_id' => 'int',
        'product_id' => 'int',
        'bytes' => 'int',
        'attempts' => 'int',
        'processed_at' => 'datetime',
        'meta' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(ImportRun::class, 'run_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function issues(): HasMany
    {
        return $this->hasMany(ImportMediaIssue::class, 'media_id');
    }
}
