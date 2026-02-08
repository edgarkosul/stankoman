<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('category_attribute', function (Blueprint $table) {
            $table->unsignedBigInteger('display_unit_id')
                ->nullable()
                ->after('attribute_id');

            $table->foreign('display_unit_id')
                ->references('id')->on('units')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('category_attribute', function (Blueprint $table) {
            $table->dropForeign(['display_unit_id']);
            $table->dropColumn('display_unit_id');
        });
    }
};
