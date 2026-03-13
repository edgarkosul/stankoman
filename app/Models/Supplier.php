<?php

namespace App\Models;

use Database\Factories\SupplierFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Supplier extends Model
{
    /** @use HasFactory<SupplierFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'bool',
    ];

    public function productReferences(): HasMany
    {
        return $this->hasMany(ProductSupplierReference::class);
    }

    public function importSources(): HasMany
    {
        return $this->hasMany(SupplierImportSource::class)
            ->orderBy('sort')
            ->orderBy('name');
    }

    public function importRuns(): HasMany
    {
        return $this->hasMany(ImportRun::class)
            ->latest('id');
    }

    protected static function booted(): void
    {
        static::saving(function (self $supplier): void {
            $supplier->name = trim($supplier->name);
            $supplier->slug = trim((string) $supplier->slug);

            if ($supplier->slug !== '') {
                return;
            }

            $baseSlug = Str::slug($supplier->name) ?: 'supplier';
            $slug = $baseSlug;
            $suffix = 2;

            while (
                static::query()
                    ->where('slug', $slug)
                    ->when(
                        $supplier->exists,
                        fn ($query) => $query->where('id', '!=', $supplier->getKey()),
                    )
                    ->exists()
            ) {
                $slug = $baseSlug.'-'.$suffix;
                $suffix++;
            }

            $supplier->slug = $slug;
        });
    }
}
