<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Расход на бота отдельной книгой, переживающей удаление переписки.
 *
 * Кнопка «Очистить переписку» удаляет разговор НАСОВСЕМ, вместе
 * с сообщениями, — так и надо: посетитель просит стереть написанное.
 * Но деньги считались бы по сообщениям, и вместе с перепиской исчезала бы
 * запись о потраченном. У донора так и было: за 04.09.2026 счётчики
 * показывали 769 тысяч токенов, а отчёт о расходе — пусто, потому что все
 * разговоры были стёрты кнопкой. Нажимают её чаще после разговоров о личном,
 * то есть после самых долгих и дорогих.
 *
 * ЧЕГО ЗДЕСЬ НЕТ И НЕ БУДЕТ: ни текста вопроса, ни текста ответа, ни
 * внешнего ключа на разговор. Это бухгалтерия, а не переписка, поэтому
 * `chat:purge` её не трогает. `conversation_ref` — номер разговора на момент
 * записи; он никуда не ссылается и переживает сам разговор.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_entries', function (Blueprint $table): void {
            $table->id();

            // Без constrained(): строка обязана пережить удаление разговора.
            $table->unsignedBigInteger('conversation_ref')->nullable()->index();

            $table->string('model', 64)->nullable();
            $table->string('stop_reason', 32)->nullable();

            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('cached_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);

            $table->decimal('cost_rub', 10, 4)->default(0);
            $table->unsignedInteger('latency_ms')->default(0);

            $table->boolean('escalated')->default(false);
            $table->boolean('callback')->default(false);

            // Только created_at: книга дописывается, а не правится.
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_entries');
    }
};
