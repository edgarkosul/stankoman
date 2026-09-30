<?php

namespace App\Console\Commands;

use App\Providers\AiSupportServiceProvider;
use App\Services\Kb\Contracts\KbSource;
use App\Services\Kb\Data\KbDocument;
use App\Services\Kb\Data\KbIndexStats;
use App\Services\Kb\KbVectorIndexer;
use Illuminate\Console\Command;
use Throwable;

/**
 * Переиндексация базы знаний ассистента.
 *
 * Три уровня свежести, из которых это второй: обсерверы гоняют одиночные
 * документы при сохранении, ночной проход этой командой страхует на случай
 * пропущенного обсервера (и единственный обновляет реквизиты из настроек),
 * а --prune убирает то, что источник больше не отдаёт.
 */
class AiKbReindex extends Command
{
    protected $signature = 'ai:kb-reindex
        {--source=* : Ограничить источниками (по умолчанию все)}
        {--prune : Удалить фрагменты документов, которых источник больше не отдаёт}
        {--dry-run : Показать, что изменилось бы, ничего не записывая}';

    protected $description = 'Переиндексировать базу знаний ИИ-ассистента';

    public function handle(KbVectorIndexer $indexer): int
    {
        $only = (array) $this->option('source');
        $sources = AiSupportServiceProvider::sources();

        if ($only !== []) {
            $sources = array_values(array_filter(
                $sources,
                static fn (KbSource $source): bool => in_array($source->name(), $only, true),
            ));

            if ($sources === []) {
                $this->error('Источники не найдены: '.implode(', ', $only));

                return self::FAILURE;
            }
        }

        // Сухой прогон показывает объём работы до того, как за неё заплатили.
        if ($this->option('dry-run')) {
            return $this->dryRun($sources);
        }

        $total = new KbIndexStats;
        $started = microtime(true);

        foreach ($sources as $source) {
            $this->line("<comment>{$source->name()}</comment>");

            try {
                $stats = $indexer->indexSource(
                    $source,
                    prune: (bool) $this->option('prune'),
                    onDocument: function (KbDocument $document, KbIndexStats $one): void {
                        $this->line(sprintf(
                            '  %-28s %2d фрагм.  %s',
                            mb_strimwidth($document->key, 0, 28, '…'),
                            $one->chunks,
                            $one->embedded === 0
                                ? '<fg=gray>без изменений</>'
                                : "<info>пересчитано {$one->embedded}</info>",
                        ));
                    },
                );
            } catch (Throwable $e) {
                $this->error("  {$source->name()}: {$e->getMessage()}");

                return self::FAILURE;
            }

            $total = $total->plus($stats);
        }

        $this->newLine();
        $this->line(sprintf(
            'Документов %d, фрагментов %d: пересчитано %d, переиспользовано %d, удалено %d.',
            $total->documents,
            $total->chunks,
            $total->embedded,
            $total->reused,
            $total->deleted,
        ));
        $this->line(sprintf(
            'Токенов %d, стоимость %.2f ₽, время %.1f с.',
            $total->promptTokens,
            $total->costRub,
            microtime(true) - $started,
        ));

        return self::SUCCESS;
    }

    /**
     * @param  list<KbSource>  $sources
     */
    private function dryRun(array $sources): int
    {
        $rows = [];

        foreach ($sources as $source) {
            foreach ($source->documents() as $document) {
                $rows[] = [
                    $source->name(),
                    mb_strimwidth($document->key, 0, 30, '…'),
                    mb_strlen($document->text),
                    $document->url ?? '—',
                ];
            }
        }

        if ($rows === []) {
            $this->warn('Источники не отдали ни одного документа.');

            return self::SUCCESS;
        }

        $this->table(['источник', 'документ', 'знаков', 'адрес'], $rows);
        $this->line('Сухой прогон: ничего не записано и не оплачено.');

        return self::SUCCESS;
    }
}
