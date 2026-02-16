<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attributes', function (Blueprint $table) {
            $table->enum('value_source', ['free', 'options'])
                ->nullable()
                ->after('data_type');

            $table->enum('filter_ui', ['tiles', 'dropdown'])
                ->nullable()
                ->after('value_source');
        });

        DB::statement(<<<'SQL'
            UPDATE attributes
            SET
                value_source = CASE
                    WHEN input_type IN ('select', 'multiselect') THEN 'options'
                    ELSE 'free'
                END,
                filter_ui = CASE
                    WHEN input_type = 'select' THEN 'dropdown'
                    WHEN input_type = 'multiselect' THEN 'tiles'
                    ELSE NULL
                END
        SQL);
    }

    public function down(): void
    {
        Schema::table('attributes', function (Blueprint $table) {
            $table->dropColumn(['value_source', 'filter_ui']);
        });
    }
};
