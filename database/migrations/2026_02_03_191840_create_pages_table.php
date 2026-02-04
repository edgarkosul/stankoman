<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('menu_id')
                ->constrained('menus')
                ->cascadeOnDelete();

            // 2-й уровень: parent_id ссылается на menu_items.id
            // (глубже 2 уровней запретим уже на уровне валидации в модели/Filament)
            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('menu_items')
                ->cascadeOnDelete();

            $table->string('label', 200);

            // тип ссылки
            $table->enum('type', ['url', 'route', 'page'])->index();

            // url
            $table->string('url', 2048)->nullable();

            // route
            $table->string('route_name', 120)->nullable();
            $table->json('route_params')->nullable();

            // page
            $table->foreignId('page_id')
                ->nullable()
                ->constrained('pages')
                ->nullOnDelete();

            // порядок среди "соседей" (в рамках menu_id + parent_id)
            $table->unsignedInteger('sort')->default(0)->index();

            $table->boolean('is_active')->default(true)->index();

            $table->string('target', 20)->nullable(); // _blank, _self...
            $table->string('rel', 120)->nullable();   // noopener, nofollow...

            $table->timestamps();

            // частые выборки: активные элементы меню + сортировка
            $table->index(['menu_id', 'parent_id', 'is_active', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_items');
    }
};
