<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('category_attribute', function (Blueprint $table) {
            $table->unsignedTinyInteger('number_decimals')
                ->nullable()
                ->after('display_unit_id');

            $table->decimal('number_step', 10, 6)
                ->nullable()
                ->after('number_decimals');

            $table->enum('number_rounding', ['round', 'floor', 'ceil'])
                ->nullable()
                ->after('number_step');
        });
    }

    public function down(): void
    {
        Schema::table('category_attribute', function (Blueprint $table) {
            $table->dropColumn([
                'number_decimals',
                'number_step',
                'number_rounding',
            ]);
        });
    }
};

