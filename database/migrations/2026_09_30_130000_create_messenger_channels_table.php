<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Чаты MAX, куда уходят уведомления менеджерам.
 *
 * Раньше чат был один и задавался в env (`MAX_BOT_CHAT_ID`), причём номер
 * чата приходилось выяснять руками. Теперь чаты подключаются кнопкой
 * из админки, их может быть несколько, и у каждого свой набор событий.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messenger_channels', function (Blueprint $table) {
            $table->id();
            $table->string('label', 190);
            $table->string('chat_id', 64)->unique();
            $table->boolean('notify_orders')->default(true);
            $table->boolean('notify_requests')->default(true);
            $table->boolean('notify_chat')->default(true);
            $table->boolean('enabled')->default(true);
            $table->string('last_error')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messenger_channels');
    }
};
