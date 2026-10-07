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
     * Поиск по словам — запасной путь, когда вектор вопроса не получен.
     *
     * Шлюз эмбеддингов иногда зависает целиком (в bots 02.10.2026: две
     * попытки по 15 с без единого байта), и без запасного пути бот на любой
     * вопрос отвечал «не могу уточнить, оставьте контакты» — в том числе
     * на вопрос, ответ на который лежит в базе. Здесь сети нет вовсе.
     *
     * Устроено грубо и намеренно: слова вопроса без служебных, обрезанные
     * до основы (окончания русского слова — это его последние 1–3 буквы),
     * ищутся подстрокой в заголовке и тексте фрагмента. Вес слова — его
     * редкость в базе: «заказ» есть в половине фрагментов и почти ничего
     * не значит, «лизинг» — в паре штук. Оценка — доля веса вопроса,
     * найденная во фрагменте; с косинусом она не сравнима, поэтому порог свой.
     *
     * Сравнение — в PHP, а не LIKE в базе: регистр кириллицы MariaDB и
     * sqlite понимают по-разному, а фрагментов здесь сотни — прочитать их
     * разом на редком пути дешевле, чем об этом думать.
     *
     * @param  list<string>|null  $sources
     * @return list<KbHit>
     */
    public function searchByWords(string $query, ?int $topK = null, ?array $sources = null, float $minCoverage = 0.5): array
    {
        $topK = $topK !== null ? max(1, $topK) : $this->defaultTopK;
        $stems = self::stems($query);

        if ($stems === []) {
            return [];
        }

        $query = DB::table($this->table)
            ->select(['id', 'chunk_id', 'source', 'url', 'title', 'breadcrumb', 'section_path', 'text']);

        if ($sources !== null && $sources !== []) {
            $query->whereIn('source', $sources);
        }

        $rows = [];
        // id фрагмента → основа → [сколько раз в тексте, есть ли в заголовке]
        $found = [];
        $frequency = array_fill_keys($stems, 0);

        foreach ($query->cursor() as $row) {
            $title = str_replace('ё', 'е', mb_strtolower((string) $row->title));
            $text = str_replace('ё', 'е', mb_strtolower((string) $row->text));
            $matched = [];

            foreach ($stems as $stem) {
                $count = substr_count($text, $stem);
                $inTitle = str_contains($title, $stem);

                if ($count > 0 || $inTitle) {
                    $matched[$stem] = [$count, $inTitle];
                    $frequency[$stem]++;
                }
            }

            if ($matched !== []) {
                $rows[$row->id] = $row;
                $found[$row->id] = $matched;
            }
        }

        if ($found === []) {
            return [];
        }

        // Фрагменты без единого совпадения в $rows не попали, но в редкость
        // слова входят: она считается по всей базе.
        $total = $query->count();
        $weights = [];

        foreach ($frequency as $stem => $count) {
            $weights[$stem] = log(1 + $total / max(1, $count));
        }

        $sum = array_sum($weights);
        $scored = [];
        $ranked = [];

        foreach ($found as $id => $matched) {
            $coverage = 0.0;
            $inTitle = 0.0;
            $repeats = 0.0;

            foreach ($matched as $stem => [$count, $titled]) {
                $coverage += $weights[$stem];
                $inTitle += $titled ? $weights[$stem] : 0.0;
                $repeats += $weights[$stem] * min($count, 5) / 5;
            }

            $coverage /= $sum;

            if ($coverage < $minCoverage) {
                continue;
            }

            /*
             * Оценка наружу — доля слов. Порядок — ещё и заголовок, и повторы:
             * на «как оформить доставку в регион» все слова находятся и на
             * странице оплаты, где доставка упомянута вскользь, а первой
             * должна идти страница, которая о доставке.
             */
            $scored[$id] = $coverage;
            $ranked[$id] = $coverage + 0.3 * $inTitle / $sum + 0.1 * $repeats / $sum;
        }

        arsort($ranked);

        $hits = [];

        foreach (array_slice(array_keys($ranked), 0, $topK) as $id) {
            $row = $rows[$id];

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
     * Основы значимых слов вопроса: «Как оформить доставку в регион?» →
     * оформ, достав, регио.
     *
     * @return list<string>
     */
    public static function stems(string $query): array
    {
        $text = str_replace('ё', 'е', mb_strtolower($query));
        preg_match_all('/[\p{L}\p{N}]+/u', $text, $matches);

        $stems = [];

        foreach ($matches[0] as $word) {
            $length = mb_strlen($word);

            if ($length < 3 || in_array($word, self::STOP_WORDS, true)) {
                continue;
            }

            // Числа и латиницу не режем: «2008», «crossair», «ндс20» — это
            // имена, окончаний у них нет.
            $cut = preg_match('/^[а-я]+$/u', $word) === 1
                ? match (true) {
                    $length >= 8 => 3,
                    $length >= 6 => 2,
                    $length >= 4 => 1,
                    default => 0,
                }
            : 0;

            $stems[] = mb_substr($word, 0, $length - $cut);
        }

        return array_values(array_unique($stems));
    }

    /** Слова, которые есть почти в любом вопросе и ничего не различают. */
    private const STOP_WORDS = [
        'как', 'что', 'где', 'когда', 'это', 'эта', 'этот', 'эти', 'для', 'при', 'или', 'есть', 'был', 'была',
        'будет', 'будут', 'можно', 'нужно', 'надо', 'если', 'какой', 'какая', 'какое', 'какие', 'сколько',
        'мой', 'моя', 'мое', 'моем', 'мои', 'мне', 'меня', 'нас', 'нам', 'наш', 'вас', 'вам', 'ваш', 'ваша',
        'ваше', 'ваши', 'они', 'его', 'ее', 'еще', 'уже', 'так', 'там', 'тут', 'все', 'без', 'над', 'под',
        'про', 'чем', 'чтобы', 'and', 'the', 'for',
    ];

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
