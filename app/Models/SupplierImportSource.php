<?php

namespace App\Models;

use Database\Factories\SupplierImportSourceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierImportSource extends Model
{
    /** @use HasFactory<SupplierImportSourceFactory> */
    use HasFactory;

    protected $fillable = [
        'supplier_id',
        'name',
        'driver_key',
        'profile_key',
        'settings',
        'is_active',
        'sort',
        'last_used_at',
    ];

    protected $casts = [
        'supplier_id' => 'int',
        'settings' => 'array',
        'is_active' => 'bool',
        'sort' => 'int',
        'last_used_at' => 'datetime',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function importRuns(): HasMany
    {
        return $this->hasMany(ImportRun::class)
            ->latest('id');
    }
}
