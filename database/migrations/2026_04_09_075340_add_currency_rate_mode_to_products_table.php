<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        if (Schema::hasColumn('products', 'wholesale_currency')) {
            DB::table('products')
                ->select(['id', 'wholesale_currency'])
                ->orderBy('id')
                ->chunkById(200, function ($products): void {
                    foreach ($products as $product) {
                        $currency = strtoupper(trim((string) ($product->wholesale_currency ?? '')));
                        $currency = $currency === 'CHY' ? 'CNY' : $currency;

                        if ($currency === '') {
                            $currency = null;
                        }

                        if ($currency !== null && ! in_array($currency, ['USD', 'CNY', 'EUR', 'RUR'], true)) {
                            $currency = null;
                        }

                        DB::table('products')
                            ->where('id', $product->id)
                            ->update(['wholesale_currency' => $currency]);
                    }
                });

            if (DB::getDriverName() !== 'sqlite') {
                DB::statement("ALTER TABLE products MODIFY wholesale_currency ENUM('USD','CNY','EUR','RUR') NULL");
            }
        }

        if (! Schema::hasColumn('products', 'auto_update_exchange_rate')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->boolean('auto_update_exchange_rate')
                    ->default(false)
                    ->after('exchange_rate');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        if (Schema::hasColumn('products', 'auto_update_exchange_rate')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->dropColumn('auto_update_exchange_rate');
            });
        }

        if (Schema::hasColumn('products', 'wholesale_currency')) {
            if (DB::getDriverName() !== 'sqlite') {
                DB::statement('ALTER TABLE products MODIFY wholesale_currency CHAR(3) NULL');
            }
        }
    }
};
