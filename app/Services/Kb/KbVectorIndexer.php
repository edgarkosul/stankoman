<?php

namespace App\Services\Kb;

use App\Services\Ai\Contracts\LlmClient;
use App\Services\Kb\Contracts\KbSource;
use App\Services\Kb\Data\KbDocument;
use App\Services\Kb\Data\KbIndexStats;
use Illuminate\Support\Facades\DB;

/**
 * Синхронизация источника контента с таблицей фрагментов и их векторами.
 *
 * Главное отличие от первоисточника на siteko — инкрементность. Там
 * переиндексация статьи означала «снести все её фрагменты и посчитать
 * эмбеддинги заново», и это было приемлемо: статей десятки, правят их
 * поштучно. Здесь ночной проход идёт по всему корпусу, и пересчёт всего
 * подряд означал бы платить за один и тот же неизменившийся текст каждую ночь.
 *
 * Поэтому сравниваем `content_hash` фрагмента. Хэш берётся от ФИНАЛЬНОГО
 * текста, того самого, что уходит в эмбеддинг, — вместе с префиксом из
 * крошек. Это важно: переименовали раздел — префикс изменился — вектор
 * обязан пересчитаться, хотя тело абзаца осталось прежним.
 *
 * В хэш подмешаны модель и размерность. Иначе смена модели прошла бы
 * незамеченной: хэши совпали бы, пересчёта не случилось, и в одной таблице
 * оказались бы векторы двух разных пространств — а такой поиск не падает,
 * он просто тихо врёт.
 */
final class KbVectorIndexer
{
    public function __construct(
        private readonly LlmClient $llm,
        private readonly KbChunker $chunker,
        private readonly string $table,
    ) {}

    /**
     * Переиндексировать источник целиком.
     *
     * @param  bool  $prune  сносить ли фрагменты документов, которых источник
     *                       больше не отдаёт (страницу убрали из белого списка,
     *                       статью сняли с публикации)
     * @param  callable(KbDocument, KbIndexStats): void|null  $onDocument  прогресс для CLI
     */
    public function indexSource(KbSource $source, bool $prune = false, ?callable $onDocument = null): KbIndexStats
    {
        $stats = new KbIndexStats;
        $seen = [];

        foreach ($source->documents() as $document) {
            $seen[] = $document->key;

            $one = $this->indexDocument($source->name(), $document);
            $stats = $stats->plus($one);

            if ($onDocument !== null) {
                $onDocument($document, $one);
            }
        }

        if ($prune) {
            $stats = $stats->plus(new KbIndexStats(
                deleted: $this->pruneExcept($source->name(), $seen),
            ));
        }

        return $stats;
    }

    /** Переиндексировать один документ. Вызывается и обсервером при сохранении. */
    public function indexDocument(string $source, KbDocument $document): KbIndexStats
    {
        $locator = $source.':'.$document->key;

        $chunks = $document->isEmpty()
            ? []
            : $this->chunker->chunk($locator, $document->title, $document->breadcrumb, $document->text);

        // Что уже лежит в базе по этому документу.
        $existing = DB::table($this->table)
            ->where('source', $source)
            ->where('doc_key', $document->key)
            ->get(['chunk_id', 'content_hash'])
            ->keyBy('chunk_id');

        $fresh = [];
        $stale = [];

        foreach ($chunks as $chunk) {
            $hash = $this->hash($chunk['text']);
            $known = $existing->get($chunk['chunk_id']);

            if ($known !== null && $known->content_hash === $hash) {
                $fresh[] = ['chunk' => $chunk, 'hash' => $hash, 'reuse' => true];
            } else {
                $stale[] = ['chunk' => $chunk, 'hash' => $hash, 'reuse' => false];
            }
        }

        $promptTokens = 0;
        $costRub = 0.0;

        if ($stale !== []) {
            $batch = $this->llm->embed(array_map(
                static fn (array $item): string => $item['chunk']['text'],
                $stale,
            ));

            $promptTokens = $batch->promptTokens;
            $costRub = $batch->costRub;

            foreach ($stale as $i => $item) {
                $stale[$i]['vector'] = $batch->vectors[$i];
            }
        }

        $now = now();
        $rows = [];

        foreach ([...$fresh, ...$stale] as $item) {
            $chunk = $item['chunk'];

            $row = [
                'chunk_id' => $chunk['chunk_id'],
                'source' => $source,
                'doc_key' => $document->key,
                'url' => $document->url,
                'title' => $chunk['title'],
                'breadcrumb' => json_encode($chunk['breadcrumb'], JSON_UNESCAPED_UNICODE),
                'section_path' => json_encode($chunk['section_path'], JSON_UNESCAPED_UNICODE),
                'text' => $chunk['text'],
                'chars' => $chunk['chars'],
                'content_hash' => $item['hash'],
                'updated_at' => $now,
                'created_at' => $now,
            ];

            // У переиспользованных фрагментов вектор в базе уже правильный —
            // не трогаем его, чтобы не переписывать килобайты зря. Но url
            // и заголовок обновляем: адрес страницы мог поменяться, а вектор
            // от этого не зависит.
            if (! $item['reuse']) {
                $row['embedding'] = KbVectorStore::packVector(
                    $this->normalize($item['vector']),
                );
                $row['dim'] = $this->llm->embeddingDimensions();
                $row['embed_model'] = $this->llm->embeddingModel();
            }

            $rows[] = $row;
        }

        $obsolete = $existing->keys()
            ->diff(array_column($chunks, 'chunk_id'))
            ->values()
            ->all();

        DB::transaction(function () use ($rows, $obsolete): void {
            if ($obsolete !== []) {
                DB::table($this->table)->whereIn('chunk_id', $obsolete)->delete();
            }

            // upsert по chunk_id: у переиспользованных фрагментов колонок
            // embedding/dim/embed_model в строке нет, поэтому существующий
            // вектор остаётся нетронутым.
            foreach ($this->groupByColumns($rows) as $group) {
                DB::table($this->table)->upsert(
                    $group,
                    ['chunk_id'],
                    array_values(array_diff(array_keys($group[0]), ['chunk_id', 'created_at'])),
                );
            }
        });

        return new KbIndexStats(
            documents: 1,
            chunks: count($rows),
            embedded: count($stale),
            reused: count($fresh),
            deleted: count($obsolete),
            promptTokens: $promptTokens,
            costRub: $costRub,
        );
    }

    /** Удалить все фрагменты документа — сняли с публикации, убрали из списка. */
    public function forgetDocument(string $source, string $docKey): int
    {
        return DB::table($this->table)
            ->where('source', $source)
            ->where('doc_key', $docKey)
            ->delete();
    }

    /**
     * @param  list<string>  $keepKeys
     */
    public function pruneExcept(string $source, array $keepKeys): int
    {
        $query = DB::table($this->table)->where('source', $source);

        if ($keepKeys !== []) {
            $query->whereNotIn('doc_key', $keepKeys);
        }

        return $query->delete();
    }

    /**
     * upsert требует одинакового набора колонок во всех строках пачки,
     * а у переиспользованных фрагментов колонок вектора нет. Поэтому
     * разбиваем на группы по форме строки.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<list<array<string, mixed>>>
     */
    private function groupByColumns(array $rows): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $groups[implode('|', array_keys($row))][] = $row;
        }

        return array_values($groups);
    }

    /**
     * Модель и размерность — часть хэша. Смена любой из них делает все
     * прежние векторы несравнимыми, и переиндексация должна произойти
     * сама, без отдельной команды и без того, чтобы кто-то помнил о ней.
     */
    private function hash(string $text): string
    {
        return hash('sha256', implode("\0", [
            $this->llm->embeddingModel(),
            (string) $this->llm->embeddingDimensions(),
            $text,
        ]));
    }

    /**
     * @param  list<float>  $vector
     * @return list<float>
     */
    private function normalize(array $vector): array
    {
        $sum = 0.0;

        foreach ($vector as $value) {
            $sum += $value * $value;
        }

        $norm = sqrt($sum);

        return $norm > 0.0
            ? array_map(static fn (float $v): float => $v / $norm, $vector)
            : $vector;
    }
}
