<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_attribute_values', function (Blueprint $table) {
            // Нижняя граница диапазона (в базовой единице атрибута)
            $table->decimal('value_min', 18, 6)
                ->nullable()
                ->after('value_number');

            // Верхняя граница диапазона (в базовой единице атрибута)
            $table->decimal('value_max', 18, 6)
                ->nullable()
                ->after('value_min');
        });
    }

    public function down(): void
    {
        Schema::table('product_attribute_values', function (Blueprint $table) {
            $table->dropColumn(['value_min', 'value_max']);
        });
    }
};
