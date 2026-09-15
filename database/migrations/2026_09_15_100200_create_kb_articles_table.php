<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Статьи базы знаний — то, что админ пишет для бота руками.
 *
 * Здесь живут ответы, которым на странице сайта не место или которых на сайте
 * пока нет: гарантия и сервис, возврат, оплата для организаций. Всё, что
 * покупатель должен видеть глазами, лучше держать на сайте — оттуда оно
 * попадёт в базу знаний само.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kb_articles', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('kb_category_id')->nullable()
                ->constrained('kb_categories')->nullOnDelete();

            $table->string('title');

            /*
             * Дерево Tiptap в JSON, а не HTML, как у страниц сайта. Так статья
             * совпадает по формату с kratonshop: черновик статьи от бота
             * собирается сразу деревом, и редактор, разбор и черновики
             * переносятся без правок.
             */
            $table->json('content');

            /*
             * Адрес страницы сайта, если статья её пересказывает или дополняет.
             * Бот даёт эту ссылку покупателю; своей страницы у статьи нет.
             *
             * Поле нужно и как напоминание автору: заполнил url — задумайся,
             * не дублируешь ли ты то, что уже есть на сайте.
             */
            $table->string('public_url', 512)->nullable();

            // Черновик в индекс не попадает: админ дописывает статью неделю,
            // и всё это время бот не должен цитировать полуфразу.
            $table->boolean('is_published')->default(false)->index();

            /*
             * Когда статья последний раз доехала до индекса и сколько дала
             * фрагментов. Без этих двух полей админ правит текст и не знает,
             * применилось ли: переиндексация идёт в очереди и внешне
             * ничем себя не проявляет.
             */
            $table->timestamp('indexed_at')->nullable();
            $table->unsignedSmallInteger('chunks_count')->default(0);

            $table->unsignedSmallInteger('position')->default(0);

            $table->timestamps();

            // Мягкое удаление: снесённая статья уносит с собой ответы бота,
            // и вернуть её из корзины должно быть проще, чем писать заново.
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_articles');
    }
};
