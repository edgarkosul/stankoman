<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cart_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('price_snapshot', 12, 2)->nullable();
            $table->json('options')->nullable();
            $table->string('options_key', 40)->index();
            $table->timestamps();

            $table->unique(['cart_id', 'product_id', 'options_key'], 'cart_items_unique_line');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
    }
};
