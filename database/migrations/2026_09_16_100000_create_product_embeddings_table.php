<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Состояние векторов каталога: что уже посчитано и по какому тексту.
 *
 * Отдельная таблица, а не колонки в `products`, по двум причинам. Первая:
 * `products` — горячая таблица, её пишет импорт по 31 фиду, и каждая лишняя
 * колонка в ней дорога. Вторая важнее: сохранение товара НЕ должно трогать
 * состояние вектора вообще, иначе наблюдатель Scout начнёт переписывать хэш,
 * а ночная команда решит, что переэмбеддивать нечего.
 *
 * Сам вектор здесь НЕ лежит. Он живёт в зеркале Meilisearch рядом с товаром
 * (App\Services\Catalog\CatalogSemanticIndex): держать его ещё и в MySQL
 * значило бы хранить ~15 МБ ради данных, которые нужны одному только поиску.
 *
 * `content_hash` считается по ТОМУ ЖЕ тексту, который уходит в модель.
 * Отсюда инкрементность: изменилась цена или остаток — текст прежний, хэш
 * прежний, вызова эмбеддинга нет. Здесь это не оптимизация, а условие работы:
 * курсы валют пересчитываются каждую ночь и двигают цены пачками по всему
 * каталогу, а карточки правятся сотнями (2 281 правка за первые пять дней
 * сентября 2026). Считать «что изменилось» по `updated_at` значило бы
 * оплачивать весь каталог заново после каждого массового импорта.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_embeddings', function (Blueprint $table): void {
            $table->foreignId('product_id')->primary()->constrained()->cascadeOnDelete();

            // sha256 в hex. Считается по эмбеддируемому тексту целиком.
            $table->char('content_hash', 64);

            /*
             * Модель и размерность лежат рядом с каждой строкой, а не в конфиге,
             * по той же причине, что и у базы знаний: смена модели ИЛИ
             * размерности обязывает пересчитать всё, и узнать об этом надо
             * из данных, а не из памяти разработчика.
             */
            $table->string('model', 64);
            $table->unsignedSmallInteger('dimensions');

            $table->timestamp('embedded_at');
            $table->timestamps();

            // Ночная команда выбирает «что переэмбеддить» по модели и хэшу.
            $table->index(['model', 'dimensions']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_embeddings');
    }
};
