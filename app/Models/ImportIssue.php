<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportIssue extends Model
{
    protected $fillable = [
        'run_id',
        'row_index',
        'code',
        'severity',
        'message',
        'row_snapshot',
    ];

    protected $casts = [
        'row_snapshot' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(ImportRun::class, 'run_id');
    }
}
