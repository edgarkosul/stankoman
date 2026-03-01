<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('shipping_country', 64)->nullable()->after('remember_token');
            $table->string('shipping_region', 128)->nullable()->after('shipping_country');
            $table->string('shipping_city', 128)->nullable()->after('shipping_region');
            $table->string('shipping_street', 255)->nullable()->after('shipping_city');
            $table->string('shipping_house', 32)->nullable()->after('shipping_street');
            $table->string('shipping_postcode', 16)->nullable()->after('shipping_house');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'shipping_country',
                'shipping_region',
                'shipping_city',
                'shipping_street',
                'shipping_house',
                'shipping_postcode',
            ]);
        });
    }
};
