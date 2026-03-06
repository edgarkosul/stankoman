<?php

namespace App\Models;

use App\Observers\ImportRunObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ObservedBy([ImportRunObserver::class])]
class ImportRun extends Model
{
    protected $fillable = [
        'type',
        'status',
        'columns',
        'totals',
        'source_filename',
        'stored_path',
        'user_id',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'columns' => 'array',
        'totals' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function issues(): HasMany
    {
        return $this->hasMany(ImportIssue::class, 'run_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ImportRunEvent::class, 'run_id');
    }

    public function media(): HasMany
    {
        return $this->hasMany(ProductImportMedia::class, 'run_id');
    }

    public function mediaIssues(): HasMany
    {
        return $this->hasMany(ImportMediaIssue::class, 'run_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
