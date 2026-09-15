<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Разговор посетителя с ассистентом.
 *
 * Посетитель анонимен, и удостоверяет его единственный секрет — `token`,
 * лежащий в вечной httpOnly-куке. Отсюда два следствия, определивших схему.
 *
 * Первое: строка создаётся ЛЕНИВО, на первом сообщении. Виджет висит на
 * каждой странице витрины; заводи мы диалог на показ виджета — краулер
 * за ночь наплодил бы по пустой строке на каждую карточку.
 *
 * Второе: ни сессия, ни IP в опознании не участвуют. Мобильный посетитель
 * кочует между Wi-Fi и LTE, и привязка к адресу отняла бы у него переписку
 * на середине разговора. `ip_hash` здесь только для разбора злоупотреблений
 * и хранится хэшем: сам адрес для этого не нужен.
 *
 * Схема донора (kratonshop) сразу в итоговом виде.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_conversations', function (Blueprint $table): void {
            $table->id();

            // Секрет доступа к переписке. Никаких других ключей у анонима нет,
            // поэтому колонка уникальна.
            $table->string('token', 40)->unique();

            // Появляется, если посетитель вошёл в аккаунт по ходу разговора:
            // листенер на Login проставляет его задним числом.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // bot → operator → closed.
            $table->string('status', 16)->default('bot')->index();

            // Тумблер «бот ведёт этот разговор». Оператор, взявший диалог,
            // гасит его, и джоба ответа молча выходит.
            $table->boolean('assistant_enabled')->default(true);

            // «Вопрос ждёт человека» — пометка, а не смена статуса: бот
            // продолжает отвечать, пока за разговор не сел менеджер.
            $table->timestamp('escalated_at')->nullable();
            $table->foreignId('operator_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('last_message_at')->nullable()->index();

            // Когда посетитель последний раз держал панель открытой.
            $table->timestamp('last_seen_at')->nullable();

            $table->unsignedInteger('messages_count')->default(0);

            /*
             * Деньги и токены копятся по диалогу, чтобы расход в админке
             * считался одним запросом, а не перебором сообщений.
             *
             * Рубли — decimal, а не целые, как цены товаров: ответ стоит
             * десятые доли рубля, и округление до рубля потеряло бы всё.
             */
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cached_tokens')->default(0);
            $table->decimal('cost_rub', 10, 4)->default(0);

            // Заявка, оформленная из чата: контакты уходят в CallbackRequest
            // и в переписку с моделью не попадают.
            $table->foreignId('callback_request_id')->nullable()
                ->constrained('callback_requests')->nullOnDelete();

            // Согласие на обработку обращения — ставится в момент первого
            // сообщения, вместе с показанной под полем ввода оговоркой.
            $table->timestamp('consent_at')->nullable();

            $table->char('ip_hash', 64)->nullable()->index();
            $table->string('user_agent', 255)->nullable();
            $table->string('referer_url', 512)->nullable();

            $table->unsignedSmallInteger('unread_for_visitor')->default(0);
            $table->unsignedSmallInteger('unread_for_staff')->default(0);

            $table->timestamps();

            // Мягкое удаление — для админки: переписка ещё и след разговора
            // с клиентом. Кнопка посетителя и `chat:purge` удаляют насовсем.
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_conversations');
    }
};
