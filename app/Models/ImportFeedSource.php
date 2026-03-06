<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportFeedSource extends Model
{
    protected $fillable = [
        'supplier',
        'source_type',
        'fingerprint',
        'source_url',
        'stored_path',
        'original_filename',
        'content_hash',
        'size_bytes',
        'created_by',
        'last_run_id',
        'last_used_at',
        'last_validated_at',
        'meta',
    ];

    protected $casts = [
        'size_bytes' => 'int',
        'created_by' => 'int',
        'last_run_id' => 'int',
        'last_used_at' => 'datetime',
        'last_validated_at' => 'datetime',
        'meta' => 'array',
    ];
}
