<?php

namespace App\Support\CatalogImport\Suppliers;

use App\Models\Supplier;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SupplierEntityResolver
{
    public function resolveId(mixed $supplierId, ?string $importSupplier = null, bool $allowAutoCreate = true): ?int
    {
        $normalizedId = $this->positiveIntOrNull($supplierId);

        if (! $this->supportsSuppliersTable()) {
            return $normalizedId;
        }

        if ($normalizedId !== null && Supplier::query()->whereKey($normalizedId)->exists()) {
            return $normalizedId;
        }

        if (! $allowAutoCreate) {
            return null;
        }

        $definition = $this->defaultDefinition($importSupplier);

        if ($definition === null) {
            return null;
        }

        $supplier = Supplier::query()->firstOrCreate(
            ['slug' => $definition['slug']],
            [
                'name' => $definition['name'],
                'is_active' => true,
            ],
        );

        return (int) $supplier->getKey();
    }

    public function supportsSuppliersTable(): bool
    {
        return Schema::hasTable('suppliers');
    }

    /**
     * @return array{name:string,slug:string}|null
     */
    public function defaultDefinition(?string $importSupplier): ?array
    {
        $normalized = trim(mb_strtolower((string) $importSupplier));

        if ($normalized === '') {
            return null;
        }

        return match ($normalized) {
            'vactool' => ['name' => 'Vactool', 'slug' => 'vactool'],
            'metalmaster' => ['name' => 'Metalmaster', 'slug' => 'metalmaster'],
            'yandex_market_feed' => ['name' => 'Yandex Market Feed', 'slug' => 'yandex-market-feed'],
            default => [
                'name' => Str::of($normalized)
                    ->replace(['_', '-'], ' ')
                    ->title()
                    ->toString(),
                'slug' => Str::slug($normalized) ?: 'supplier',
            ],
        };
    }

    private function positiveIntOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
            $parsed = (int) trim($value);

            return $parsed > 0 ? $parsed : null;
        }

        return null;
    }
}
