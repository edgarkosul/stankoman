<?php

namespace App\Services\Catalog;

use Illuminate\Support\Facades\Log;
use Meilisearch\Client;
use Throwable;

/**
 * Зеркало каталога для поиска по смыслу.
 *
 * ЗАЧЕМ ОТДЕЛЬНЫЙ ИНДЕКС, а не векторы в рабочем `stankoman_products` —
 * замер донора 04.09.2026, из-за которого ему пришлось переделывать всю
 * подсистему. Meilisearch с объявленным эмбеддером `userProvided` отвергает
 * документ без вектора ЦЕЛИКОМ: задача индексации падает, товар не находится
 * даже словами. А Scout шлёт документы пачками и о векторах не знает — значит
 * любой новый товар (и вся пачка импорта вместе с ним) выпадал бы из поиска
 * ВИТРИНЫ до ближайшей ночи. У нас путей записи в индекс больше донорских:
 * 31 фид, Excel-импорт, массовый редактор, ремонтные команды.
 *
 * Радиус поражения и решил спор: поиск магазина — это выручка, бот — добавка.
 * Рабочий индекс остаётся без эмбеддера, а смысловой поиск живёт в зеркале,
 * которое целиком в наших руках: сломается — перестанет работать подбор
 * у бота, витрина не заметит.
 *
 * Зеркало держит и слова, и вектор, поэтому гибридный поиск («BSM-115»
 * точным совпадением, «для гаража» по смыслу) работает внутри него одним
 * запросом, вместе с фильтрами по цене, наличию и разделу.
 *
 * ЧТО В НЁМ УСТАРЕВАЕТ. Цена, наличие и разделы в зеркале — снимок на момент
 * последнего прогона команды. Это влияет только на ОТБОР («до 40 тысяч»,
 * «в наличии»), но не на ответ: цену и остаток бот всегда перечитывает
 * из MySQL (App\Shop\EloquentProductLookup), и покупатель видит живые числа.
 * Снимок обновляется без единого вызова шлюза — `ai:catalog-embed --fields-only`
 * в расписании, сразу после ночного пересчёта курсов валют.
 *
 * МОДЕЛЕЙ МАГАЗИНА ЗДЕСЬ НЕТ и быть не может (сторож — tests/Unit/AiServicesSeamTest.php):
 * что считать названием, брендом и разделом товара, знает App\Shop, а сюда
 * приходят готовые документы — ровно те, что уходят в витринный индекс.
 */
final class CatalogSemanticIndex
{
    /**
     * Последняя поставленная задача индексации.
     *
     * Meilisearch индексирует АСИНХРОННО: `addDocuments` кладёт задачу
     * в очередь и возвращается сразу. Без ожидания команда спрашивала бы
     * счётчик документов через миллисекунду после отправки, получала ноль
     * и не объявляла эмбеддер — поиск по смыслу молча не включался бы.
     * Задачи выполняются по порядку, поэтому достаточно дождаться последней.
     */
    private ?int $lastTask = null;

    /**
     * @param  array<string, mixed>  $settings  настройки витринного индекса из config/scout.php:
     *                                          зеркало обязано искать словами не хуже него
     */
    public function __construct(
        private readonly Client $client,
        private readonly string $indexName,
        private readonly string $embedder,
        private readonly int $dimensions,
        private readonly float $semanticRatio,
        private readonly float $modelSemanticRatio,
        private readonly array $settings = [],
    ) {}

    public function name(): string
    {
        return $this->indexName;
    }

    /**
     * Настройки зеркала.
     *
     * Поисковые и фильтруемые поля берутся у витрины и своей копии здесь
     * не заводят: расхождение зеркала с рабочим индексом читается покупателем
     * как «бот и сайт отвечают по-разному на один запрос», и у донора это уже
     * случалось дважды по другим поводам.
     *
     * Эмбеддер объявляется ПОСЛЕДНИМ и отдельным вызовом — Meilisearch
     * проверяет наличие векторов у всех документов в момент объявления,
     * поэтому сначала документы с векторами, потом настройка.
     */
    public function configure(bool $withEmbedder = true): void
    {
        $index = $this->client->index($this->indexName);

        if ($this->settings !== []) {
            $index->updateSettings($this->settings);
        }

        if ($withEmbedder) {
            $index->updateEmbedders([
                $this->embedder => ['source' => 'userProvided', 'dimensions' => $this->dimensions],
            ]);
        }
    }

    /**
     * Положить документы в зеркало вместе с векторами.
     *
     * @param  list<array<string, mixed>>  $documents  документы витринного вида, с ключом `id`
     * @param  array<int, list<float>>  $vectors  вектор по id товара
     */
    public function put(array $documents, array $vectors): void
    {
        $ready = [];

        foreach ($documents as $document) {
            $id = (int) ($document['id'] ?? 0);
            $vector = $vectors[$id] ?? null;

            // Документ без вектора Meilisearch отвергнет, да ещё и всю пачку
            // вместе с ним, — такие просто не отправляем.
            if ($vector === null) {
                continue;
            }

            $document['_vectors'] = [$this->embedder => $vector];
            $ready[] = $document;
        }

        if ($ready !== []) {
            $this->remember($this->client->index($this->indexName)->addDocuments($ready, 'id'));
        }
    }

    /**
     * Обновить поля документов, не трогая векторы.
     *
     * ⚠️ ЗДЕСЬ `updateDocuments`, А НЕ `addDocuments`, и разница дорогая.
     * В Meilisearch POST (`addDocuments`) — это «добавить ИЛИ ЗАМЕНИТЬ»:
     * документ переписывается целиком, и всё, чего нет в присланном теле,
     * пропадает. PUT (`updateDocuments`) — «добавить или дополнить», поля
     * мержатся. Векторы переживают оба варианта, и это ввело донора
     * в заблуждение 04.09.2026: частичная запись через addDocuments сохранила
     * `_vectors`, но стёрла у всех 16 307 товаров название, бренд и артикул —
     * поиск словами в зеркале умер молча, а гибрид продолжал работать
     * на одной семантике.
     *
     * @param  list<array<string, mixed>>  $documents
     */
    public function refreshFields(array $documents): void
    {
        if ($documents !== []) {
            $this->remember($this->client->index($this->indexName)->updateDocuments($documents, 'id'));
        }
    }

    /**
     * @param  list<int>  $ids
     */
    public function forget(array $ids): void
    {
        if ($ids !== []) {
            $this->remember($this->client->index($this->indexName)->deleteDocuments($ids));
        }
    }

    /**
     * Дождаться, пока Meilisearch разберёт очередь индексации.
     *
     * Минута с запасом: партия в тридцать документов разбирается
     * за миллисекунды, но на холодном индексе первая задача может
     * подождать разбора настроек.
     */
    public function awaitPending(int $timeoutMs = 60000): void
    {
        if ($this->lastTask === null) {
            return;
        }

        try {
            $this->client->waitForTask($this->lastTask, $timeoutMs, 200);
        } catch (Throwable) {
            // Не дождались — не повод ронять прогон: документы всё равно
            // будут проиндексированы, просто позже.
        }

        $this->lastTask = null;
    }

    /** Сколько товаров сейчас в зеркале. */
    public function count(): int
    {
        try {
            return (int) $this->client->index($this->indexName)->stats()['numberOfDocuments'];
        } catch (Throwable $e) {
            /*
             * Молчаливый ноль здесь однажды уже спрятал у донора опечатку
             * в имени метода клиента: команда отчитывалась «в зеркале
             * 0 товаров» при шестидесяти на месте и не объявляла эмбеддер.
             * Поэтому причина уходит в лог, а не растворяется.
             */
            Log::warning('Зеркало каталога не ответило', [
                'index' => $this->indexName,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /** Есть ли чем искать по смыслу. */
    public function ready(): bool
    {
        return $this->count() > 0;
    }

    /**
     * Объявлен ли в зеркале эмбеддер.
     *
     * Без него гибридный поиск отвечает ошибкой на каждый запрос с вектором,
     * а документы с `_vectors` кладутся молча — то есть зеркало выглядит
     * собранным и не работает. Спрашивает `ai:kb-doctor`.
     */
    public function embedderDeclared(): bool
    {
        try {
            $embedders = $this->client->index($this->indexName)->getSettings()['embedders'] ?? [];

            return isset($embedders[$this->embedder]);
        } catch (Throwable $e) {
            Log::warning('Настройки зеркала каталога не прочитались', [
                'index' => $this->indexName,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Гибридный поиск: слова и смысл в одном запросе, фильтры к обоим сразу.
     *
     * @param  string  $query  слова запроса в том виде, в каком они уходят
     *                         в витринный индекс (латиница, написание бренда)
     * @param  list<float>  $vector  вектор ОРИГИНАЛЬНОЙ формулировки покупателя
     * @return list<int> id товаров в порядке релевантности
     */
    public function search(string $query, array $vector, string $filter = '', int $limit = 5, bool $designation = false): array
    {
        $params = ['limit' => $limit];

        if ($filter !== '') {
            $params['filter'] = $filter;
        }

        if ($vector !== []) {
            $params['vector'] = $vector;
            $params['hybrid'] = [
                'embedder' => $this->embedder,
                'semanticRatio' => $this->ratioFor($query, $designation),
            ];
        }

        $hits = $this->client->index($this->indexName)->search($query, $params)->getHits();

        return array_values(array_map(static fn (array $hit): int => (int) $hit['id'], $hits));
    }

    /**
     * Доля смысла — по форме запроса, а не одна на все случаи.
     *
     * Замер донора 04.09.2026: на «ВК-J 15/10» доля 0.8 теряет точную модель
     * совсем, 0.5 оставляет её первой, но засоряет хвост ножами и токарным
     * станком; на «компрессор для гаража» 0.3, наоборот, уводит выдачу
     * в промывочные компрессоры и шланги. Артикул — это последовательность
     * знаков, а не смысл, и мерить их одной меркой нельзя.
     *
     * НАЗВАНИЕ БРЕНДА — из той же породы, и это выяснилось 09.09.2026 на
     * «Харсман есть?»: слова находили Hansmann в обоих индексах, но с одной
     * опечаткой попадание стоит 0.494, а смысловая половина отдавала
     * алмазный круг с 0.783 — и при доле 0.5 круг побеждал. Бот отвечал,
     * что бренда в каталоге нет, и предлагал взамен другие. Его 237 товаров.
     *
     * Дело не в пороге, а в несравнимости шкал: чистая бессмыслица
     * «Квазимодо» получает от того же эмбеддера 0.750 против того же круга.
     * 0.78 — это шумовой пол, а не сходство. Выдуманное слово (обозначение,
     * бренд) смысла не несёт вовсе, поэтому вектор на нём — только шум,
     * и доверять ему половину ранга нельзя. Кто именно назван брендом,
     * решает CatalogBrands на входе, а не эта строка: индексу список не нужен.
     */
    private function ratioFor(string $query, bool $designation = false): float
    {
        return $designation || CatalogQueryShape::looksLikeModel($query)
            ? $this->modelSemanticRatio
            : $this->semanticRatio;
    }

    /**
     * Запомнить задачу, чтобы потом её дождаться.
     *
     * @param  array<string, mixed>  $task
     */
    private function remember(array $task): void
    {
        $this->lastTask = (int) ($task['taskUid'] ?? $task['uid'] ?? 0) ?: null;
    }
}
