<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('slug');
            $table->string('img')->nullable();
            $table->boolean('is_active')->default(true);

            // adjacency list
            $table->integer('parent_id')->default(-1)->index(); // root = -1
            $table->integer('order')->default(0)->index();      // порядок среди братьев

            $table->json('meta_json')->nullable();
            $table->timestamps();

            // уникальность внутри уровня
            $table->unique(['parent_id', 'slug'],  'categories_parent_slug_unique');
            $table->unique(['parent_id', 'order'], 'categories_parent_order_unique'); // гарантируем уникальные позиции
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
