<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Заявка «свяжитесь со мной»: с витрины — на звонок, из чата — на ответ письмом.
 *
 * Отдельная таблица, а не `orders`: заказ требует номер, способ доставки
 * и сумму, и человеку, оставившему один телефон, ушло бы «Ваш заказ №…».
 * На неё же потом смотрит внешний ключ `chat_conversations.callback_request_id`.
 *
 * Схема перенесена из kratonshop сразу в том виде, к которому донор пришёл
 * четырьмя миграциями, — со всеми его уроками, чтобы не повторять их ALTER-ами.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('callback_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name', 100)->nullable();

            /*
             * Оба контакта необязательны на уровне БАЗЫ. У донора телефон был
             * NOT NULL, и заявка из чата, где покупатель оставил одну почту,
             * падала на первой же браузерной проверке (08.09.2026).
             * «Хотя бы один контакт» живёт в правилах формы: только она знает,
             * каким каналом магазин ответит.
             *
             * Хэши нужны антифлуду — считать попытки по контакту, не читая сам контакт.
             */
            $table->string('phone', 32)->nullable();
            $table->string('phone_hash', 64)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('email_hash', 64)->nullable();

            $table->string('city', 100)->nullable();
            $table->string('call_time', 100)->nullable();
            $table->text('comments')->nullable();

            // `site` или `chat`: откуда пришла заявка, менеджеру и «Пробелам» чата.
            $table->string('source', 16)->default('site');

            /*
             * Строкой, а не enum: новое значение в ENUM на MariaDB — это
             * перестройка таблицы, а список статусов живёт в модели.
             */
            $table->string('status', 16)->default('pending');

            // Доставка письма менеджеру: видно в админке, дошло ли и почему нет.
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('notified_at')->nullable();
            $table->string('last_error', 255)->nullable();

            $table->ipAddress()->nullable();
            $table->string('user_agent', 255)->nullable();

            $table->timestamps();

            $table->index(['phone_hash', 'status']);
            $table->index('email_hash');
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('callback_requests');
    }
};
