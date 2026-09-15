<?php

namespace App\Services\Kb;

use App\Services\Ai\Contracts\LlmClient;
use App\Services\Kb\Data\KbHit;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Семантический поиск по базе знаний: вопрос → вектор → перебор в PHP.
 *
 * Почему перебор, а не векторная СУБД. Фрагментов здесь десятки, в пределе
 * сотни, вектор — 1024 числа. Полный проход это порядка миллиона умножений:
 * единицы миллисекунд, дешевле, чем round-trip до отдельного сервиса.
 * Каталог с его тысячами товаров сюда не помещается — он ищется гибридно
 * в зеркале Meilisearch.
 *
 * Векторы хранятся нормированными, поэтому косинус вырождается в скалярное
 * произведение: делить на длины не нужно. Все проверенные модели шлюза
 * отдают единичные векторы, но на вставке мы всё равно нормируем сами —
 * стоит это ничего, а молчаливо кривые оценки близости стоят дорого.
 */
final class KbVectorStore
{
    /** @var array<string, list<array{id: int, vector: list<float>}>> */
    private array $matrixCache = [];

    public function __construct(
        private readonly LlmClient $llm,
        private readonly string $table,
        private readonly int $defaultTopK,
    ) {}

    /**
     * @param  list<string>|null  $sources  ограничить поиск источниками
     * @return list<KbHit>
     */
    public function search(string $query, ?int $topK = null, ?array $sources = null): array
    {
        $query = trim($query);

        if ($query === '') {
            return [];
        }

        $vector = $this->embedQuery($query);

        if ($vector === []) {
            return [];
        }

        return $this->searchByVector($vector, $topK, $sources);
    }

    /**
     * Вектор вопроса, нормированный — как и всё, что лежит в базе.
     *
     * Отдельным методом, потому что вектор вопроса нужен не только поиску:
     * его же пишут рядом с репликой покупателя как сырьё для группировки
     * «Пробелов» в базе знаний.
     *
     * @return list<float> пустой массив, если шлюз ничего не вернул
     */
    public function embedQuery(string $query): array
    {
        $query = trim($query);

        if ($query === '') {
            return [];
        }

        $vector = $this->llm->embed([$query], mode: 'query')->first();

        return $vector === [] ? [] : $this->normalize($vector);
    }

    /**
     * Поиск по готовому вектору. Отдельным методом — экран «Пробелы» кластеризует
     * уже сохранённые эмбеддинги вопросов и не должен платить за них повторно.
     *
     * @param  list<float>  $vector
     * @param  list<string>|null  $sources
     * @return list<KbHit>
     */
    public function searchByVector(array $vector, ?int $topK = null, ?array $sources = null): array
    {
        $topK = $topK !== null ? max(1, $topK) : $this->defaultTopK;
        $rows = $this->matrix($sources);

        if ($rows === []) {
            return [];
        }

        $vector = $this->normalize($vector);
        $dimensions = count($vector);

        $scored = [];

        foreach ($rows as $row) {
            if (count($row['vector']) !== $dimensions) {
                // Фрагмент от другой модели или размерности — сравнивать нельзя.
                // Молча пропускаем: ai:kb-doctor покажет такие отдельной строкой
                // и подскажет переиндексацию.
                continue;
            }

            $dot = 0.0;

            foreach ($row['vector'] as $i => $value) {
                $dot += $value * $vector[$i];
            }

            $scored[$row['id']] = $dot;
        }

        if ($scored === []) {
            return [];
        }

        arsort($scored);
        $ids = array_slice(array_keys($scored), 0, $topK, preserve_keys: true);

        $records = DB::table($this->table)
            ->whereIn('id', $ids)
            ->get(['id', 'chunk_id', 'source', 'url', 'title', 'breadcrumb', 'section_path', 'text'])
            ->keyBy('id');

        $hits = [];

        // Идём по $ids, а не по $records: порядок задаёт близость, а не БД.
        foreach ($ids as $id) {
            $row = $records->get($id);

            if ($row === null) {
                continue;
            }

            $hits[] = new KbHit(
                chunkId: (string) $row->chunk_id,
                source: (string) $row->source,
                url: $row->url,
                title: (string) $row->title,
                breadcrumb: json_decode((string) $row->breadcrumb, true) ?: [],
                sectionPath: json_decode((string) $row->section_path, true) ?: [],
                text: (string) $row->text,
                score: round($scored[$id], 6),
            );
        }

        return $hits;
    }

    /**
     * Векторы всех фрагментов, распакованные из BLOB.
     *
     * Мемоизация — на время жизни процесса. В веб-запросе это один поиск,
     * в CLI-переборе (замер, кластеризация) — сотни, и повторное чтение
     * с распаковкой каждый раз было бы основной статьёй расходов.
     *
     * @param  list<string>|null  $sources
     * @return list<array{id: int, vector: list<float>}>
     */
    private function matrix(?array $sources): array
    {
        $key = $sources === null ? '*' : implode(',', $sources);

        if (isset($this->matrixCache[$key])) {
            return $this->matrixCache[$key];
        }

        $query = DB::table($this->table)
            ->whereNotNull('embedding')
            ->select(['id', 'embedding']);

        if ($sources !== null && $sources !== []) {
            $query->whereIn('source', $sources);
        }

        $rows = [];

        foreach ($query->cursor() as $row) {
            $rows[] = [
                'id' => (int) $row->id,
                'vector' => self::unpackVector($row->embedding),
            ];
        }

        return $this->matrixCache[$key] = $rows;
    }

    /** Сбросить мемоизацию — после переиндексации в том же процессе. */
    public function forgetMatrix(): void
    {
        $this->matrixCache = [];
    }

    /**
     * Вектор → BLOB. float32 вместо float64: вдвое меньше места, а потеря
     * точности (около 1e-7) на порядки ниже разницы между осмысленными
     * оценками близости.
     *
     * @param  list<float>  $vector
     */
    public static function packVector(array $vector): string
    {
        return pack('g*', ...$vector);
    }

    /**
     * @return list<float>
     */
    public static function unpackVector(string $blob): array
    {
        $unpacked = unpack('g*', $blob);

        if ($unpacked === false) {
            throw new RuntimeException('Не удалось распаковать вектор из BLOB.');
        }

        return array_values($unpacked);
    }

    /**
     * @param  list<float>  $vector
     * @return list<float>
     */
    public function normalize(array $vector): array
    {
        $sum = 0.0;

        foreach ($vector as $value) {
            $sum += $value * $value;
        }

        $norm = sqrt($sum);

        if ($norm <= 0.0) {
            return $vector;
        }

        return array_map(static fn (float $v): float => $v / $norm, $vector);
    }
}
