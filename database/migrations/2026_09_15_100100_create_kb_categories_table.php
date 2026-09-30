<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Разделы базы знаний — папки для админа, и только.
 *
 * В эмбеддинг раздел попадает лишь словом в крошках статьи: бот ищет по
 * смыслу фрагмента, а не по дереву разделов. Нужны они там, где статей
 * станет несколько десятков и человеку понадобится их разложить.
 *
 * Отсюда и бедность таблицы: ни вложенности, ни описаний, ни slug'ов
 * для публичных адресов. У раздела нет своей страницы на сайте, и заводить
 * её значило бы делать вторую витрину рядом с настоящей.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kb_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name');

            // Порядок в списке админки. Целое, а не дробное: перетаскивать
            // разделы будут редко.
            $table->unsignedSmallInteger('position')->default(0)->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_categories');
    }
};
