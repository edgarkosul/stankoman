<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        if (Schema::hasColumn('products', 'discount_percent')) {
            return;
        }

        Schema::table('products', function (Blueprint $table): void {
            // Источник истины для процентной скидки.
            // NULL = процентной скидки нет (discount_price, если задан, — «старая цена» поставщика).
            $table->decimal('discount_percent', total: 5, places: 2)
                ->nullable()
                ->after('discount_price');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        if (! Schema::hasColumn('products', 'discount_percent')) {
            return;
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('discount_percent');
        });
    }
};
