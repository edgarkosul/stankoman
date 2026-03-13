<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_supplier_references', function (Blueprint $table) {
            $table->foreignId('supplier_id')
                ->nullable()
                ->after('supplier')
                ->constrained('suppliers')
                ->nullOnDelete();
        });

        $distinctSuppliers = DB::table('product_supplier_references')
            ->select('supplier')
            ->distinct()
            ->pluck('supplier')
            ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->values();

        foreach ($distinctSuppliers as $supplierKey) {
            $normalizedKey = trim((string) $supplierKey);
            $definition = match ($normalizedKey) {
                'vactool' => ['name' => 'Vactool', 'slug' => 'vactool'],
                'metalmaster' => ['name' => 'Metalmaster', 'slug' => 'metalmaster'],
                'yandex_market_feed' => ['name' => 'Yandex Market Feed', 'slug' => 'yandex-market-feed'],
                default => [
                    'name' => Str::of($normalizedKey)
                        ->replace(['_', '-'], ' ')
                        ->title()
                        ->toString(),
                    'slug' => Str::slug($normalizedKey) ?: 'supplier',
                ],
            };

            $supplierId = DB::table('suppliers')
                ->where('slug', $definition['slug'])
                ->value('id');

            if (! is_numeric($supplierId)) {
                $supplierId = DB::table('suppliers')->insertGetId([
                    'name' => $definition['name'],
                    'slug' => $definition['slug'],
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('product_supplier_references')
                ->where('supplier', $normalizedKey)
                ->update([
                    'supplier_id' => (int) $supplierId,
                ]);
        }

        Schema::table('product_supplier_references', function (Blueprint $table) {
            $table->dropUnique('product_supplier_reference_unique');
            $table->unique(
                ['supplier_id', 'external_id'],
                'product_supplier_reference_supplier_entity_unique',
            );
            $table->index(
                ['supplier', 'external_id'],
                'product_supplier_reference_supplier_external_idx',
            );
            $table->index(
                ['supplier_id', 'product_id'],
                'product_supplier_reference_supplier_entity_product_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('product_supplier_references', function (Blueprint $table) {
            $table->dropIndex('product_supplier_reference_supplier_entity_product_idx');
            $table->dropIndex('product_supplier_reference_supplier_external_idx');
            $table->dropUnique('product_supplier_reference_supplier_entity_unique');
            $table->unique(['supplier', 'external_id'], 'product_supplier_reference_unique');
            $table->dropConstrainedForeignId('supplier_id');
        });
    }
};
