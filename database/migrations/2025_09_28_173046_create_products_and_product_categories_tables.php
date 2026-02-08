<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $t) {
            $t->id();

            // Базовые поля
            $t->string('name');
            $t->string('title')->nullable();             // альтернативный заголовок
            $t->string('slug')->unique();                 // ЧПУ
            $t->string('sku')->nullable()->index();       // наш артикул
            $t->string('brand')->nullable()->index();
            $t->string('country')->nullable();

            // Цена (в копейках)
            $t->unsignedInteger('price_amount')->default(0);
            $t->unsignedInteger('discount_price')->nullable();
            $t->char('currency', 3)->default('RUB');

            // Состояние/видимость
            $t->boolean('in_stock')->default(true)->index();
            $t->unsignedInteger('qty')->nullable();
            $t->unsignedInteger('popularity')->default(0)->index();
            $t->boolean('is_active')->default(true)->index();
            $t->boolean('is_in_yml_feed')->default(true)->index();
            $t->string('warranty')->nullable();
            $t->boolean('with_dns')->default(true);

            // Контент
            $t->text('short')->nullable();                // краткое описание
            $t->longText('description')->nullable();      // полное описание (HTML/текст)
            $t->text('extra_description')->nullable();    // доп. описание
            $t->longText('specs')->nullable();            // характеристики (пока текст)
            $t->string('promo_info')->nullable();         // промо-строка

            // Медиа
            $t->string('image')->nullable();              // основное изображение
            $t->string('thumb')->nullable();              // превью
            $t->json('gallery')->nullable();              // массив путей/URL

            // SEO
            $t->string('meta_title')->nullable();
            $t->text('meta_description')->nullable();

            $t->timestamps();

            // (Опционально) полнотекстовый индекс — добавьте отдельной миграцией при необходимости.
            // $t->fullText(['name', 'sku', 'brand', 'short', 'description']);
        });

        Schema::create('product_categories', function (Blueprint $t) {
            $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('category_id');
            $t->boolean('is_primary')->default(false); // основная категория для крошек/SEO

            $t->primary(['product_id', 'category_id']);
            $t->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $t->foreign('category_id')->references('id')->on('categories')->cascadeOnDelete();

            $t->index(['category_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_categories');
        Schema::dropIfExists('products');
    }
};
