<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductSupplierReference extends Model
{
    protected $fillable = [
        'supplier',
        'supplier_id',
        'external_id',
        'source_category_id',
        'product_id',
        'first_seen_run_id',
        'last_seen_run_id',
        'last_seen_at',
    ];

    protected $casts = [
        'supplier_id' => 'int',
        'source_category_id' => 'int',
        'first_seen_run_id' => 'int',
        'last_seen_run_id' => 'int',
        'last_seen_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function supplierEntity(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function firstSeenRun(): BelongsTo
    {
        return $this->belongsTo(ImportRun::class, 'first_seen_run_id');
    }

    public function lastSeenRun(): BelongsTo
    {
        return $this->belongsTo(ImportRun::class, 'last_seen_run_id');
    }
}
