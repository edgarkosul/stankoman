<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportMediaIssue extends Model
{
    protected $fillable = [
        'media_id',
        'run_id',
        'product_id',
        'code',
        'message',
        'context',
    ];

    protected $casts = [
        'media_id' => 'int',
        'run_id' => 'int',
        'product_id' => 'int',
        'context' => 'array',
    ];

    public function media(): BelongsTo
    {
        return $this->belongsTo(ProductImportMedia::class, 'media_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ImportRun::class, 'run_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
