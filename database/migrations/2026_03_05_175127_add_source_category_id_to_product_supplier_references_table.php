<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_supplier_references', function (Blueprint $table) {
            $table->unsignedInteger('source_category_id')->nullable()->after('external_id');
            $table->index(
                ['supplier', 'source_category_id'],
                'product_supplier_reference_supplier_source_category_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('product_supplier_references', function (Blueprint $table) {
            $table->dropColumn('source_category_id');
        });
    }
};
