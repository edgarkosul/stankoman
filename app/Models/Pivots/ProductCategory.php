<?php

namespace App\Models\Pivots;

use App\Models\Category;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\Relations\Pivot;

class ProductCategory extends Pivot
{
    protected $table = 'product_categories';

    protected $casts = [
        'is_primary' => 'bool',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $pivot) {
            $isLeaf = Category::whereKey($pivot->category_id)
                ->leaf()->exists();

            if (! $isLeaf) {
                throw ValidationException::withMessages([
                    'category_id' => 'Товар можно привязывать только к листовой категории.',
                ]);
            }
        });
    }
}
