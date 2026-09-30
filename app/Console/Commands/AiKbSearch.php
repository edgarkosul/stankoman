<?php

namespace App\Console\Commands;

use App\Services\Kb\KbVectorStore;
use Illuminate\Console\Command;

/**
 * Поиск по базе знаний из консоли.
 *
 * Инструмент отладки промпта и качества корпуса: прежде чем спрашивать
 * «почему бот ответил не так», надо понять, что он вообще нашёл. Ту же
 * выдачу админ увидит в песочнице — здесь она в сыром виде.
 */
class AiKbSearch extends Command
{
    protected $signature = 'ai:kb-search
        {query* : Вопрос}
        {--k=5 : Сколько фрагментов показать}
        {--source=* : Ограничить источниками}
        {--full : Показать текст фрагментов целиком}';

    protected $description = 'Найти фрагменты базы знаний по вопросу';

    public function handle(KbVectorStore $store): int
    {
        $query = implode(' ', (array) $this->argument('query'));
        $sources = (array) $this->option('source');

        $started = microtime(true);

        $hits = $store->search(
            $query,
            topK: (int) $this->option('k'),
            sources: $sources !== [] ? $sources : null,
        );

        $elapsed = (microtime(true) - $started) * 1000;

        if ($hits === []) {
            $this->warn('Ничего не найдено. База пуста? Проверьте ai:kb-doctor.');

            return self::SUCCESS;
        }

        $threshold = (float) config('ai_support.knowledge_base.min_score');

        $this->line(sprintf('<comment>%s</comment> — %d за %.0f мс', $query, count($hits), $elapsed));
        $this->newLine();

        foreach ($hits as $i => $hit) {
            // Порог отделяет «нашли ответ» от «нашли хоть что-то». Ниже него
            // фрагмент считается промахом и поднимает флаг kb_miss — это
            // сырьё для экрана «Пробелы в базе знаний».
            $mark = $hit->score >= $threshold ? '<info>✓</info>' : '<fg=yellow>·</>';

            $this->line(sprintf(
                '%s %d. <options=bold>%.4f</> %s',
                $mark,
                $i + 1,
                $hit->score,
                $hit->path(),
            ));

            $text = $this->option('full')
                ? $hit->text
                : mb_strimwidth(str_replace("\n", ' ', $hit->text), 0, 160, '…');

            $this->line('     '.str_replace("\n", "\n     ", $text));

            if ($hit->url !== null) {
                $this->line('     <fg=gray>'.$hit->url.'</>');
            }

            $this->newLine();
        }

        $this->line(sprintf('<fg=gray>порог релевантности %.2f</>', $threshold));

        return self::SUCCESS;
    }
}
