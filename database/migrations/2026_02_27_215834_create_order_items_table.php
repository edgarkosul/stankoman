<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();

            $table->string('sku', 64)->nullable();
            $table->string('name');
            $table->unsignedInteger('quantity');
            $table->string('unit', 16)->nullable();

            $table->decimal('price_amount', 12, 2);
            $table->decimal('total_amount', 12, 2);

            $table->string('thumbnail_url', 512)->nullable();
            $table->json('meta')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
