<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_products', function (Blueprint $table) {
            $table->id();
            $table->string('source_site')->default('kratonkuban.ru');
            $table->string('source_path');
            $table->string('name');
            $table->string('sku')->nullable();
            $table->string('manufacturer')->nullable();
            $table->foreignId('matched_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('match_strategy')->nullable();
            $table->boolean('redirect_enabled')->default(false);
            $table->timestamps();

            $table->unique(['source_site', 'source_path'], 'legacy_products_source_path_unique');
            $table->index('sku');
            $table->index('matched_product_id');
            $table->index('redirect_enabled');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_products');
    }
};
