<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Таблицы бота переходят к пакету siteko/chatbot (реестр bots 10.3.4).
 *
 * Пакетные миграции (2026_09_19_*) таблицу, которая уже есть, пропускают
 * целиком (`AdoptedTables`), поэтому всё, чем их схема отличается от нашей,
 * докладывается здесь — и раньше них: дата нарочно стоит перед пакетными.
 *
 * Что именно:
 *  - `site_id` у всех таблиц с данными сайта. Магазин — один сайт номер 1
 *    (`chatbot.site_id`), значение по умолчанию то же: строка, вставленная
 *    мимо модели, не потеряется в глобальном scope пакета;
 *  - уникальность фрагмента базы знаний — по (site_id, chunk_id): пакет
 *    делает upsert ровно по этой паре, и sqlite без такого индекса upsert
 *    не принимает вовсе;
 *  - заявка разговора — `lead_ref` без внешнего ключа: пакет не знает, чем
 *    у сайта оформлена заявка;
 *  - источник статей `intertooler-kb` → `articles` и в колонке, и в префиксе
 *    `chunk_id`. Без этого каждая статья нашлась бы в поиске дважды: пакет
 *    проиндексировал бы её заново под своим именем, а старые строки не удалил
 *    бы никто — `--prune` работает внутри источника. Векторы при этом
 *    не пересчитываются: в хэш фрагмента имя источника не входит.
 *
 * `down()` рабочий: откат релиза ставит старый движок, и схема обязана
 * вернуться к его виду, иначе он упадёт на первом же разговоре.
 */
return new class extends Migration
{
    private const SITE_ID = 1;

    private const OLD_ARTICLES = 'intertooler-kb';

    private const NEW_ARTICLES = 'articles';

    /** @var list<string> */
    private const SITE_TABLES = [
        'kb_chunks',
        'kb_categories',
        'kb_articles',
        'chat_conversations',
        'chat_messages',
        'ai_usage_entries',
    ];

    public function up(): void
    {
        foreach (self::SITE_TABLES as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->unsignedBigInteger('site_id')->default(self::SITE_ID)->after('id');
            });
        }

        Schema::table('kb_chunks', function (Blueprint $table): void {
            $table->dropUnique('kb_chunks_chunk_id_unique');
            $table->dropIndex('kb_chunks_source_doc_index');
            $table->unique(['site_id', 'chunk_id']);
            $table->index(['site_id', 'source', 'doc_key'], 'kb_chunks_site_source_doc_index');
        });

        Schema::table('kb_categories', function (Blueprint $table): void {
            $table->index('site_id');
        });

        Schema::table('kb_articles', function (Blueprint $table): void {
            $table->index(['site_id', 'is_published']);
        });

        Schema::table('chat_conversations', function (Blueprint $table): void {
            $table->index(['site_id', 'status']);
            $table->index(['site_id', 'last_message_at']);
            $table->index(['site_id', 'ip_hash']);
            $table->dropForeign(['callback_request_id']);
        });

        // Индекс внешнего ключа MySQL оставляет и после снятия ключа; sqlite
        // своего не заводит вовсе.
        if (Schema::hasIndex('chat_conversations', 'chat_conversations_callback_request_id_foreign')) {
            Schema::table('chat_conversations', function (Blueprint $table): void {
                $table->dropIndex('chat_conversations_callback_request_id_foreign');
            });
        }

        Schema::table('chat_conversations', function (Blueprint $table): void {
            $table->renameColumn('callback_request_id', 'lead_ref');
        });

        Schema::table('chat_conversations', function (Blueprint $table): void {
            // Слушатель заявок ищет разговор по ней: пуш в MAX не дублирует эскалацию.
            $table->index('lead_ref');
        });

        Schema::table('chat_messages', function (Blueprint $table): void {
            $table->index(['site_id', 'kb_miss', 'created_at'], 'chat_messages_site_miss_index');
        });

        Schema::table('ai_usage_entries', function (Blueprint $table): void {
            $table->index(['site_id', 'created_at']);
        });

        $this->renameSource(self::OLD_ARTICLES, self::NEW_ARTICLES);
    }

    public function down(): void
    {
        $this->renameSource(self::NEW_ARTICLES, self::OLD_ARTICLES);

        Schema::table('ai_usage_entries', function (Blueprint $table): void {
            $table->dropIndex(['site_id', 'created_at']);
        });

        Schema::table('chat_messages', function (Blueprint $table): void {
            $table->dropIndex('chat_messages_site_miss_index');
        });

        Schema::table('chat_conversations', function (Blueprint $table): void {
            $table->dropIndex(['lead_ref']);
        });

        Schema::table('chat_conversations', function (Blueprint $table): void {
            $table->renameColumn('lead_ref', 'callback_request_id');
        });

        // Внешний ключ вернётся только на существующие заявки: пакет мог
        // запомнить номер, которого в таблице уже нет.
        DB::table('chat_conversations')
            ->whereNotNull('callback_request_id')
            ->whereNotIn('callback_request_id', DB::table('callback_requests')->select('id'))
            ->update(['callback_request_id' => null]);

        Schema::table('chat_conversations', function (Blueprint $table): void {
            $table->foreign('callback_request_id')->references('id')->on('callback_requests')->nullOnDelete();
            $table->dropIndex(['site_id', 'status']);
            $table->dropIndex(['site_id', 'last_message_at']);
            $table->dropIndex(['site_id', 'ip_hash']);
        });

        Schema::table('kb_articles', function (Blueprint $table): void {
            $table->dropIndex(['site_id', 'is_published']);
        });

        Schema::table('kb_categories', function (Blueprint $table): void {
            $table->dropIndex(['site_id']);
        });

        Schema::table('kb_chunks', function (Blueprint $table): void {
            $table->dropIndex('kb_chunks_site_source_doc_index');
            $table->dropUnique(['site_id', 'chunk_id']);
            $table->unique('chunk_id');
            $table->index(['source', 'doc_key'], 'kb_chunks_source_doc_index');
        });

        foreach (self::SITE_TABLES as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropColumn('site_id');
            });
        }
    }

    /**
     * Имя источника — и в колонке, и в начале `chunk_id` (`источник:ключ#N`).
     * Построчно, а не одним UPDATE с конкатенацией: так одинаково и на
     * MariaDB, и на sqlite тестов, а статей — десятки.
     */
    private function renameSource(string $from, string $to): void
    {
        DB::table('kb_chunks')
            ->where('source', $from)
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($from, $to): void {
                foreach ($rows as $row) {
                    DB::table('kb_chunks')->where('id', $row->id)->update([
                        'source' => $to,
                        'chunk_id' => $to.substr($row->chunk_id, strlen($from)),
                    ]);
                }
            });
    }
};
