<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportRunEvent extends Model
{
    protected $fillable = [
        'run_id',
        'supplier',
        'stage',
        'result',
        'source_ref',
        'external_id',
        'product_id',
        'source_category_id',
        'row_index',
        'code',
        'message',
        'context',
    ];

    protected $casts = [
        'product_id' => 'int',
        'source_category_id' => 'int',
        'row_index' => 'int',
        'context' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(ImportRun::class, 'run_id');
    }
}
