<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Support\Products\ProductSearchSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Laravel\Scout\EngineManager;
use Meilisearch\Contracts\DocumentsQuery;

/**
 * Сверка каталога с поисковым индексом.
 *
 * Индекс держится на событиях модели, а половина записей в каталог идёт
 * запросами: импорт восемнадцати поставщиков, массовый редактор, ремонтные
 * команды, правки руками в mysql. Живые места зовут ProductSearchSync сами,
 * но это дисциплина, а не гарантия — команда существует, чтобы дисциплина
 * была проверяемой.
 *
 * Сначала считает расхождение и докладывает, и только потом чинит: молча
 * самозалечивающаяся сверка — способ никогда не узнать, что появилось новое
 * место, пишущее мимо индекса. В норме дрейф нулевой, и любая ненулевая
 * строка в логе означает «иди чинить путь записи».
 *
 * Пришла на смену ночному `products:search-reindex`, который начинался
 * с `removeAllFromSearch()`: пока индекс собирался заново, поиск на сайте
 * отдавал пустоту. Полная пересборка осталась ручной командой.
 */
class SearchAudit extends Command
{
    protected $signature = 'search:audit
        {--fix : Починить: полный проход по каталогу и удаление лишних документов}
        {--show=10 : Сколько id показывать в отчёте}';

    protected $description = 'Сверить каталог с поисковым индексом Meilisearch и (по --fix) починить расхождения';

    /** Сколько документов забирается из индекса за один запрос. */
    private const FETCH_CHUNK = 1000;

    public function handle(EngineManager $engines, ProductSearchSync $searchSync): int
    {
        if (config('scout.driver') !== 'meilisearch') {
            $this->warn('SCOUT_DRIVER не meilisearch — сверять нечего.');

            return self::SUCCESS;
        }

        $indexName = (new Product)->searchableAs();

        $expected = $this->expectedIds();
        $indexed = $this->indexedIds($engines, $indexName);

        $missing = array_values(array_diff($expected, $indexed));
        $ghosts = array_values(array_diff($indexed, $expected));

        $this->line(sprintf(
            'Индекс %s: документов %d, товаров к индексации %d',
            $indexName,
            count($indexed),
            count($expected),
        ));
        $this->line('  потеряно (есть в базе, нет в индексе): '.$this->describe($missing));
        $this->line('  лишних (есть в индексе, нет в базе): '.$this->describe($ghosts));

        $context = [
            'index' => $indexName,
            'expected' => count($expected),
            'indexed' => count($indexed),
            'missing' => count($missing),
            'ghosts' => count($ghosts),
            'missing_sample' => array_slice($missing, 0, 20),
            'ghosts_sample' => array_slice($ghosts, 0, 20),
        ];

        if ($missing === [] && $ghosts === []) {
            Log::info('search:audit — расхождений нет', $context);
        } else {
            Log::warning('search:audit — каталог и индекс разошлись', $context);
        }

        if (! $this->option('fix')) {
            // Без --fix команда работает как проверка: ненулевой код возврата,
            // чтобы её можно было звать руками и из мониторинга.
            return ($missing === [] && $ghosts === []) ? self::SUCCESS : self::FAILURE;
        }

        /*
         * Полный проход, а не только по потерянным id: он же чинит документы,
         * разошедшиеся по содержимому. Такое расхождение по множествам id
         * не видно — ремонтные команды правят products через query builder,
         * иногда даже не двигая updated_at, и товар остаётся в индексе
         * со старым названием.
         *
         * Очередь на время прогона выключаем намеренно: это ночная работа
         * на полминуты, ей незачем забивать воркер десятком джоб.
         */
        config(['scout.queue' => false]);

        $this->info('Полная переиндексация каталога…');
        Product::makeAllSearchable();

        if ($ghosts !== []) {
            $this->info('Удаляю лишние документы: '.count($ghosts));
            $searchSync->removeIds($ghosts);
        }

        $this->info('Готово.');

        return self::SUCCESS;
    }

    /**
     * Id товаров, которые обязаны быть в индексе.
     *
     * Правило не дублируем условием в запросе, а спрашиваем у самой модели:
     * shouldBeSearchable() — единственное место, где оно записано.
     *
     * @return array<int, int>
     */
    private function expectedIds(): array
    {
        $ids = [];

        Product::query()
            ->select(['id', 'is_active'])
            ->orderBy('id')
            ->chunk(2000, function ($chunk) use (&$ids): void {
                foreach ($chunk->filter->shouldBeSearchable() as $product) {
                    $ids[] = (int) $product->getKey();
                }
            });

        return $ids;
    }

    /**
     * Id документов, лежащих в индексе.
     *
     * @return array<int, int>
     */
    private function indexedIds(EngineManager $engines, string $indexName): array
    {
        $index = $engines->engine()->index($indexName);

        $ids = [];
        $offset = 0;

        while (true) {
            $page = $index
                ->getDocuments(
                    (new DocumentsQuery)
                        ->setLimit(self::FETCH_CHUNK)
                        ->setOffset($offset)
                        ->setFields(['id']),
                )
                ->getResults();

            if ($page === []) {
                break;
            }

            foreach ($page as $document) {
                $ids[] = (int) $document['id'];
            }

            $offset += self::FETCH_CHUNK;
        }

        return $ids;
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function describe(array $ids): string
    {
        if ($ids === []) {
            return '0';
        }

        $show = max(0, (int) $this->option('show'));
        $head = array_slice($ids, 0, $show);
        $rest = count($ids) - count($head);

        return count($ids).($head === [] ? '' : ' — '.implode(', ', $head).($rest > 0 ? " и ещё {$rest}" : ''));
    }
}
