<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Фрагменты базы знаний ассистента вместе с их векторами.
 *
 * Вектор лежит в BLOB упакованными float32 (`pack('g*')`) — 4 КБ на фрагмент
 * при 1024 измерениях. Косинусная близость считается перебором в PHP: при
 * десятках, в пределе сотнях фрагментов это единицы миллисекунд и ноль
 * инфраструктуры. Товарный слой сюда не поместится — он живёт в зеркале
 * Meilisearch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kb_chunks', function (Blueprint $table): void {
            $table->id();

            // Стабильный идентификатор куска: source:doc_key#N.
            $table->string('chunk_id', 320)->unique();

            // Откуда фрагмент: страница сайта, реквизиты из настроек, статья
            // базы знаний. Отдельный источник можно переиндексировать или снести
            // целиком.
            $table->string('source', 32);

            // Ключ документа внутри источника — слаг страницы, id статьи.
            // Отдельной колонкой, а не через разбор chunk_id: по ней идут
            // и точечная переиндексация, и удаление осиротевших фрагментов.
            //
            // Важно, что это НЕ url: адрес страницы может поменяться, и тогда
            // старые фрагменты остались бы в базе навсегда, продолжая всплывать
            // в выдаче.
            $table->string('doc_key', 191);

            // Публичный адрес, который бот даёт посетителю. Может отсутствовать:
            // у статьи базы знаний и у реквизитов своей страницы на сайте нет.
            $table->string('url', 512)->nullable();
            $table->string('title');
            $table->json('breadcrumb');
            $table->json('section_path');

            $table->mediumText('text');
            $table->unsignedInteger('chars');

            // sha256 от финального текста фрагмента (уже с префиксом-крошками).
            // Основа инкрементности: не изменился хэш — не тратим вызов
            // эмбеддингов. Без этого ночной проход означал бы повторную оплату
            // всего корпуса каждую ночь.
            $table->char('content_hash', 64)->index();

            $table->binary('embedding')->nullable();

            // Модель и размерность пишем рядом с вектором. Смена любого из
            // двух значений делает старые векторы несравнимыми с новыми,
            // и обнаружиться это должно явной ошибкой, а не тихо плохим
            // поиском через полгода.
            $table->unsignedSmallInteger('dim')->nullable();
            $table->string('embed_model', 64)->nullable();

            $table->timestamps();

            // Основной путь выборки: «дай все фрагменты источника с векторами»
            // и «перезапиши фрагменты вот этого документа».
            $table->index(['source', 'doc_key'], 'kb_chunks_source_doc_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_chunks');
    }
};
