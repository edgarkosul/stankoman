<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_supplier_references', function (Blueprint $table) {
            $table->id();
            $table->string('supplier', 120);
            $table->string('external_id');
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('first_seen_run_id')->nullable()->constrained('import_runs')->nullOnDelete();
            $table->foreignId('last_seen_run_id')->nullable()->constrained('import_runs')->nullOnDelete();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['supplier', 'external_id'], 'product_supplier_reference_unique');
            $table->index(['supplier', 'product_id'], 'product_supplier_reference_supplier_product_idx');
            $table->index(['supplier', 'last_seen_run_id'], 'product_supplier_reference_supplier_run_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_supplier_references');
    }
};
