<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Сообщения одного разговора вместе с телеметрией ответа.
 *
 * Телеметрия лежит В СТРОКЕ СООБЩЕНИЯ, а не в отдельном журнале: вопросы
 * «почему он так ответил», «сколько это стоило» и «нашёл ли он вообще
 * что-нибудь» задаются об одном конкретном ответе, и ответ на них должен
 * быть виден рядом с ним в админке — без джойнов и без похода в логи.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('chat_conversation_id')->constrained()->cascadeOnDelete();

            // visitor | assistant | operator | system.
            $table->string('role', 16);
            $table->mediumText('body');

            $table->foreignId('operator_id')->nullable()->constrained('users')->nullOnDelete();

            /*
             * Чем закончился ход агента: stop, max_iterations, empty,
             * contaminated, error, pii_blocked — или заглушка мимо агента
             * (stalled, not_queued). Без этой колонки в админке видна только
             * человеческая фраза, и «модель зациклилась» не отличить
             * от «воркер не поднят».
             */
            $table->string('stop_reason', 32)->nullable();

            // Вызванные инструменты по порядку и найденные фрагменты базы
            // знаний с их релевантностью — то, из чего собран ответ.
            $table->json('tool_calls')->nullable();
            $table->json('citations')->nullable();

            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cached_tokens')->default(0);
            $table->decimal('cost_rub', 10, 4)->default(0);
            $table->unsignedInteger('latency_ms')->default(0);

            /*
             * Какая модель ответила на самом деле — из тела ответа шлюза,
             * а не из конфига. Шлюз — перепродавец, и у донора был случай
             * подмены модели; пока имя не лежало рядом с ответом, отличить
             * «модель стала хуже» от «подсунули другую» было нечем.
             */
            $table->string('model', 64)->nullable();

            // 👍/👎 под ответом бота.
            $table->tinyInteger('rating')->nullable();

            /*
             * Поиск не нашёл ничего выше порога релевантности. Сигнал СЛАБЫЙ:
             * по нему не отличить «в базе дыра» от «спросили не про магазин».
             * Годится как один вход из нескольких на экране «Пробелы».
             */
            $table->boolean('kb_miss')->default(false)->index();

            // Вектор вопроса покупателя — сырьё для группировки «Пробелов».
            $table->binary('embedding')->nullable();

            /*
             * Где стоял посетитель В МОМЕНТ ОТПРАВКИ этого сообщения, а не
             * когда начал разговор: «а этот дешевле?», набранное после
             * перехода с карточки в раздел, иначе уехало бы на прошлый товар.
             *
             * Хранится разрешённая на сервере структура (тип страницы,
             * название товара, цена с политикой скидки), а не URL: по адресу
             * модель гадала бы.
             */
            $table->json('page_context')->nullable();

            $table->json('meta')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            // Основной путь выборки — «покажи переписку по порядку».
            $table->index(['chat_conversation_id', 'id'], 'chat_messages_conversation_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
