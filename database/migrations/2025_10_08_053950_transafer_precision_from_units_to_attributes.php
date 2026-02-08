<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('attributes', function (Blueprint $table) {
            $table->unsignedTinyInteger('number_decimals')->nullable(); // 0..6 обычно достаточно
            $table->decimal('number_step', 10, 6)->nullable();          // например 1 / 0.1 / 0.01
            $table->enum('number_rounding', ['round', 'floor', 'ceil'])->nullable(); // поведение при сохранении
        });
        Schema::table('units', function (Blueprint $table) {
            if (Schema::hasColumn('units', 'precision')) {
                $table->dropColumn('precision');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attributes', function (Blueprint $table) {
            $table->dropColumn(['number_decimals', 'number_step', 'number_rounding']);
        });
        Schema::table('units', function (Blueprint $table) {
            $table->unsignedTinyInteger('precision')->default(2); // округление при выводе
        });
    }
};
