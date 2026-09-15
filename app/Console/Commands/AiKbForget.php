<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Удалить из базы знаний все фрагменты источника.
 *
 * Нужна ровно в одном случае: источник убрали из кода, и его фрагменты
 * осиротели. Штатный prune их не тронет — он перебирает зарегистрированные
 * источники и про исчезнувший не знает, — а в выдаче они продолжают
 * участвовать наравне со свежими.
 */
class AiKbForget extends Command
{
    protected $signature = 'ai:kb-forget {source : Имя источника} {--force : Не спрашивать подтверждения}';

    protected $description = 'Удалить фрагменты источника из базы знаний';

    public function handle(): int
    {
        $source = (string) $this->argument('source');
        $table = (string) config('ai_support.knowledge_base.table');

        $count = DB::table($table)->where('source', $source)->count();

        if ($count === 0) {
            $this->warn("Источник «{$source}» в базе не найден.");

            return self::SUCCESS;
        }

        if (! $this->option('force')
            && ! $this->confirm("Удалить {$count} фрагментов источника «{$source}»?")) {
            return self::SUCCESS;
        }

        DB::table($table)->where('source', $source)->delete();

        $this->info("Удалено {$count} фрагментов источника «{$source}».");

        return self::SUCCESS;
    }
}
