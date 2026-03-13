<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_runs', function (Blueprint $table) {
            $table->foreignId('supplier_id')
                ->nullable()
                ->after('stored_path')
                ->constrained('suppliers')
                ->nullOnDelete();
            $table->foreignId('supplier_import_source_id')
                ->nullable()
                ->after('supplier_id')
                ->constrained('supplier_import_sources')
                ->nullOnDelete();

            $table->index(['supplier_id', 'type', 'id'], 'import_runs_supplier_type_id_index');
            $table->index(['supplier_import_source_id', 'id'], 'import_runs_supplier_source_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('import_runs', function (Blueprint $table) {
            $table->dropIndex('import_runs_supplier_source_id_index');
            $table->dropIndex('import_runs_supplier_type_id_index');
            $table->dropConstrainedForeignId('supplier_import_source_id');
            $table->dropConstrainedForeignId('supplier_id');
        });
    }
};
