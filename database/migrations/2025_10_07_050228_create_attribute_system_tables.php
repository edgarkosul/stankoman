<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        /**
         * 1) Единицы измерения
         * Храним числа в базовой единице (см. si_factor).
         */
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('name');              // Килограмм
            $table->string('symbol', 16);        // кг
            $table->decimal('si_factor', 24, 12)->default(1); // множитель к базовой ед. (например, см -> м = 0.01)
            $table->unsignedTinyInteger('precision')->default(2); // округление при выводе
            $table->timestamps();

            $table->unique(['name', 'symbol']);
        });

        /**
         * 2) Справочник атрибутов
         * data_type — тип данных (как храним)
         * input_type — тип UI (как отображаем/фильтруем)
         */
        Schema::create('attributes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->enum('data_type', ['text', 'number', 'boolean']);
            $table->enum('input_type', ['text', 'number', 'boolean', 'select', 'multiselect', 'range'])->default('text');
            $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();

            $table->boolean('is_filterable')->default(false);
            $table->boolean('is_visible')->default(true);      // на карточке товара
            $table->boolean('is_comparable')->default(true);   // в таблице сравнения

            $table->string('group')->nullable();               // "Габариты", "Электрика" и т.п.
            $table->string('display_format')->nullable();      // "{value} {unit}"
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();
        });

        /**
         * 3) Опции для select / multiselect
         */
        Schema::create('attribute_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attribute_id')->constrained('attributes')->cascadeOnDelete();
            $table->string('value');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['attribute_id', 'value']); // одно и то же значение в рамках атрибута — один раз
        });

        /**
         * 4) Связь категории и атрибутов (набор атрибутов в категории)
         * можно добавить свои флаги видимости и порядок локально.
         */
        Schema::create('category_attribute', function (Blueprint $table) {
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->foreignId('attribute_id')->constrained('attributes')->cascadeOnDelete();
            $table->boolean('is_required')->default(false);

            $table->unsignedInteger('filter_order')->default(0);
            $table->unsignedInteger('compare_order')->default(0);
            $table->boolean('visible_in_specs')->default(true);     // таблица характеристик на карточке
            $table->boolean('visible_in_compare')->default(true);   // в сравнении

            $table->string('group_override')->nullable(); // локальное имя группы внутри категории
            $table->timestamps();

            $table->primary(['category_id', 'attribute_id']);
        });

        /**
         * 5) Значения атрибутов у товаров
         * одно из полей value_* или ссылка на option — в зависимости от data_type / input_type
         */
        Schema::create('product_attribute_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('attribute_id')->constrained('attributes')->cascadeOnDelete();

            $table->string('value_text')->nullable();
            $table->decimal('value_number', 20, 6)->nullable();    // ВСЕГДА в базовой единице unit_id атрибута
            $table->boolean('value_boolean')->nullable();

            $table->foreignId('attribute_option_id')->nullable()->constrained('attribute_options')->nullOnDelete();

            $table->timestamps();

            $table->unique(['product_id', 'attribute_id']); // по одному значению на товар/атрибут
            $table->index(['attribute_id', 'value_number']); // быстрые срезы по диапазонам
            $table->index(['attribute_id', 'attribute_option_id']);
        });

        /**
         * 6) (Опционально) Pivot для multiselect,
         * если нужно реально хранить несколько опций для одного атрибута у товара.
         * Если в каталоге достаточно single-select — можно не использовать.
         */
        Schema::create('product_attribute_option', function (Blueprint $table) {
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('attribute_id')->constrained('attributes')->cascadeOnDelete();
            $table->foreignId('attribute_option_id')->constrained('attribute_options')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['product_id', 'attribute_id', 'attribute_option_id'], 'pao_unique_triplet');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_attribute_option');
        Schema::dropIfExists('product_attribute_values');
        Schema::dropIfExists('category_attribute');
        Schema::dropIfExists('attribute_options');
        Schema::dropIfExists('attributes');
        Schema::dropIfExists('units');
    }
};
