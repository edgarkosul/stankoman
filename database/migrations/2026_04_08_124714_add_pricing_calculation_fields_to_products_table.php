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

        $hasWholesalePrice = Schema::hasColumn('products', 'wholesale_price');
        $hasWholesaleCurrency = Schema::hasColumn('products', 'wholesale_currency');
        $hasExchangeRate = Schema::hasColumn('products', 'exchange_rate');
        $hasWholesalePriceRub = Schema::hasColumn('products', 'wholesale_price_rub');
        $hasMarkupMultiplier = Schema::hasColumn('products', 'markup_multiplier');
        $hasMarginAmountRub = Schema::hasColumn('products', 'margin_amount_rub');

        if (
            $hasWholesalePrice
            && $hasWholesaleCurrency
            && $hasExchangeRate
            && $hasWholesalePriceRub
            && $hasMarkupMultiplier
            && $hasMarginAmountRub
        ) {
            return;
        }

        Schema::table('products', function (Blueprint $table) use (
            $hasWholesalePrice,
            $hasWholesaleCurrency,
            $hasExchangeRate,
            $hasWholesalePriceRub,
            $hasMarkupMultiplier,
            $hasMarginAmountRub,
        ): void {
            if (! $hasWholesalePrice) {
                $table->decimal('wholesale_price', total: 14, places: 4)
                    ->nullable()
                    ->after('currency');
            }

            if (! $hasWholesaleCurrency) {
                $table->char('wholesale_currency', 3)
                    ->nullable()
                    ->after('wholesale_price');
            }

            if (! $hasExchangeRate) {
                $table->decimal('exchange_rate', total: 14, places: 6)
                    ->nullable()
                    ->after('wholesale_currency');
            }

            if (! $hasWholesalePriceRub) {
                $table->decimal('wholesale_price_rub', total: 14, places: 2)
                    ->nullable()
                    ->after('exchange_rate');
            }

            if (! $hasMarkupMultiplier) {
                $table->decimal('markup_multiplier', total: 8, places: 4)
                    ->nullable()
                    ->after('wholesale_price_rub');
            }

            if (! $hasMarginAmountRub) {
                $table->decimal('margin_amount_rub', total: 14, places: 2)
                    ->nullable()
                    ->after('markup_multiplier');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        $columns = array_values(array_filter([
            Schema::hasColumn('products', 'margin_amount_rub') ? 'margin_amount_rub' : null,
            Schema::hasColumn('products', 'markup_multiplier') ? 'markup_multiplier' : null,
            Schema::hasColumn('products', 'wholesale_price_rub') ? 'wholesale_price_rub' : null,
            Schema::hasColumn('products', 'exchange_rate') ? 'exchange_rate' : null,
            Schema::hasColumn('products', 'wholesale_currency') ? 'wholesale_currency' : null,
            Schema::hasColumn('products', 'wholesale_price') ? 'wholesale_price' : null,
        ]));

        if ($columns === []) {
            return;
        }

        Schema::table('products', function (Blueprint $table) use ($columns): void {
            $table->dropColumn($columns);
        });
    }
};
