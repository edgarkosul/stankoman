<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_import_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')
                ->constrained('suppliers')
                ->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('driver_key', 120);
            $table->string('profile_key', 120)->nullable();
            $table->json('settings')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamp('last_used_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['supplier_id', 'name'], 'supplier_import_sources_supplier_name_unique');
            $table->index(['supplier_id', 'is_active', 'sort'], 'supplier_import_sources_supplier_active_sort_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_import_sources');
    }
};
