<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Ai\Contracts\LlmClient;
use App\Services\Catalog\CatalogSemanticIndex;
use App\Shop\ProductEmbeddingText;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Векторы каталога для поиска по смыслу (фаза 8).
 *
 * ИНКРЕМЕНТНОСТЬ ЗДЕСЬ НЕ ОПТИМИЗАЦИЯ, А УСЛОВИЕ РАБОТЫ. Наблюдатель
 * «сохранил товар → переэмбеддь» на импорте по 31 фиду породил бы тысячи
 * вызовов шлюза за один прогон. Поэтому векторы считает только эта команда
 * и только для товаров, у которых изменился ТЕКСТ: цена и остаток в текст
 * не входят, значит их ежедневное движение не стоит ни копейки.
 *
 * ПОРЯДОК ВАЖЕН и выведен из поведения Meilisearch (замер донора 04.09.2026):
 * эмбеддер `userProvided` объявляется ПОСЛЕ того, как у всех документов
 * зеркала есть векторы. Объявишь раньше — индексация упадёт с ошибкой
 * «no vectors provided», причём целой пачкой.
 *
 * Цена полного прогона на нашем каталоге: 3 579 активных товаров по ~1 200
 * знаков — около 1,4 млн токенов, то есть единицы рублей по прайсу
 * qwen3-embedding-8b. Дальше платим только за изменившееся, а правок карточек
 * у нас много: 786 за август, 2 281 за первые пять дней сентября 2026.
 * Сотни товаров за ночь — это нормальный режим, а не авария.
 */
class AiCatalogEmbed extends Command
{
    protected $signature = 'ai:catalog-embed
        {--limit= : Обработать не больше стольких товаров}
        {--force : Переэмбеддить всё, даже неизменившееся}
        {--dry-run : Только посчитать, что изменилось, без вызовов модели}
        {--fields-only : Обновить в зеркале поля (цену, наличие, разделы) без эмбеддингов}';

    protected $description = 'Посчитать векторы товаров для поиска по смыслу';

    public function handle(
        LlmClient $llm,
        ProductEmbeddingText $builder,
        CatalogSemanticIndex $index,
    ): int {
        $model = $llm->embeddingModel();
        $dimensions = (int) config('ai_support.embedding.dimensions');
        $batchSize = (int) config('ai_support.catalog_search.batch');
        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;
        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');

        // Настройки зеркала — без эмбеддера: он объявляется в конце,
        // когда векторы уже на месте.
        if (! $dryRun) {
            $index->configure(withEmbedder: false);
        }

        if ((bool) $this->option('fields-only')) {
            return $this->refreshFields($index);
        }

        $known = $this->knownHashes($model, $dimensions);

        $query = Product::query()->where('is_active', true)->with('categories')->orderBy('id');
        $total = (clone $query)->count();

        $this->info(sprintf(
            'Товаров активных: %d, уже посчитано: %d, модель %s (%d измерений).',
            $total, count($known), $model, $dimensions,
        ));

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $changed = 0;
        $embedded = 0;
        $tokens = 0;
        $cost = 0.0;
        $pending = [];

        foreach ($query->lazyById(200) as $product) {
            $bar->advance();

            $text = $builder->for($product);

            // Товар без единого слова о себе вектору не нужен: искать
            // по нему нечего, а место в зеркале он занял бы.
            if (mb_strlen($text) < 40) {
                continue;
            }

            $hash = $builder->hash($text);

            if (! $force && ($known[$product->getKey()] ?? null) === $hash) {
                continue;
            }

            $changed++;
            $pending[] = ['product' => $product, 'text' => $text, 'hash' => $hash];

            if ($limit !== null && $changed >= $limit) {
                break;
            }

            if (count($pending) >= $batchSize && ! $dryRun) {
                [$done, $t, $c] = $this->flush($pending, $llm, $index, $model, $dimensions);
                $embedded += $done;
                $tokens += $t;
                $cost += $c;
                $pending = [];
            }
        }

        if ($pending !== [] && ! $dryRun) {
            [$done, $t, $c] = $this->flush($pending, $llm, $index, $model, $dimensions);
            $embedded += $done;
            $tokens += $t;
            $cost += $c;
        }

        $bar->finish();
        $this->newLine(2);

        if ($dryRun) {
            $this->info("[сухой прогон] Переэмбеддить нужно: {$changed} товаров.");

            return self::SUCCESS;
        }

        // Meilisearch индексирует асинхронно, а дальше мы спрашиваем счётчик
        // и объявляем эмбеддер — оба вопроса требуют, чтобы очередь разошлась.
        $index->awaitPending();

        $this->prune($index);
        $index->awaitPending();

        /*
         * Эмбеддер объявляем последним и только когда в зеркале что-то есть:
         * на пустом индексе объявление проходит, но следующий же прогон
         * получит отказ на первом документе без вектора.
         */
        if ($index->ready()) {
            $index->configure(withEmbedder: true);
            $this->info('Эмбеддер объявлен, поиск по смыслу включён.');
        }

        $this->info(sprintf(
            'Посчитано векторов: %d, токенов %s, примерно %.2f ₽. В зеркале «%s»: %d товаров.',
            $embedded,
            number_format($tokens, 0, ',', ' '),
            $cost,
            $index->name(),
            $index->count(),
        ));

        return self::SUCCESS;
    }

    /**
     * Обновить поля документов, не трогая векторы.
     *
     * Ни одного вызова шлюза: Meilisearch мержит документ по ключу и оставляет
     * `_vectors` на месте. Нужно, когда в документ добавилось новое поле или
     * когда цена с наличием разъехались с зеркалом. У нас второе — ежедневно:
     * `products:sync-currency-rates` в полночь пересчитывает цены пачками,
     * и без этого прохода фильтр «до 40 тысяч» работал бы по вчерашнему снимку.
     */
    private function refreshFields(CatalogSemanticIndex $index): int
    {
        $query = Product::query()->where('is_active', true)->with('categories')->orderBy('id');

        $total = (clone $query)->count();
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $chunk = [];
        $done = 0;

        foreach ($query->lazyById(200) as $product) {
            $bar->advance();
            $chunk[] = $product->toSearchableArray();

            if (count($chunk) >= 500) {
                $index->refreshFields($chunk);
                $done += count($chunk);
                $chunk = [];
            }
        }

        if ($chunk !== []) {
            $index->refreshFields($chunk);
            $done += count($chunk);
        }

        $index->awaitPending(120000);
        $bar->finish();
        $this->newLine(2);

        // Эмбеддер мог быть ещё не объявлен, если это первый прогон вообще.
        if ($index->ready()) {
            $index->configure(withEmbedder: true);
        }

        $this->info("Обновлено полей у {$done} товаров, без вызовов модели.");

        return self::SUCCESS;
    }

    /**
     * Партия текстов → векторы → зеркало и отметки о посчитанном.
     *
     * @param  list<array{product: Product, text: string, hash: string}>  $pending
     * @return array{0: int, 1: int, 2: float}
     */
    private function flush(
        array $pending,
        LlmClient $llm,
        CatalogSemanticIndex $index,
        string $model,
        int $dimensions,
    ): array {
        $texts = array_map(static fn (array $row): string => $row['text'], $pending);

        try {
            $batch = $llm->embed($texts, 'doc');
        } catch (Throwable $e) {
            /*
             * Партия не должна ронять весь прогон: каталог большой, и терять
             * работу целого часа из-за одной сетевой ошибки обидно.
             * Незаписанные товары просто попадут в следующий прогон — их хэши
             * остались прежними.
             */
            $this->newLine();
            $this->warn('Партия не посчиталась: '.$e->getMessage());

            return [0, 0, 0.0];
        }

        $vectors = [];
        $documents = [];
        $rows = [];
        $now = now();

        foreach ($pending as $position => $row) {
            $vector = $batch->vectors[$position] ?? null;

            if ($vector === null) {
                continue;
            }

            $product = $row['product'];
            $vectors[$product->getKey()] = $vector;
            $documents[] = $product->toSearchableArray();

            $rows[] = [
                'product_id' => $product->getKey(),
                'content_hash' => $row['hash'],
                'model' => $model,
                'dimensions' => $dimensions,
                'embedded_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $index->put($documents, $vectors);

        if ($rows !== []) {
            DB::table('product_embeddings')->upsert(
                $rows,
                ['product_id'],
                ['content_hash', 'model', 'dimensions', 'embedded_at', 'updated_at'],
            );
        }

        return [count($rows), $batch->promptTokens, $batch->costRub];
    }

    /**
     * Хэши уже посчитанного — одной выборкой.
     *
     * Модель и размерность в условии не формальность: их смена делает старые
     * векторы несравнимыми с новыми, и переэмбеддить надо всё.
     *
     * @return array<int, string>
     */
    private function knownHashes(string $model, int $dimensions): array
    {
        return DB::table('product_embeddings')
            ->where('model', $model)
            ->where('dimensions', $dimensions)
            ->pluck('content_hash', 'product_id')
            ->map(static fn ($hash): string => (string) $hash)
            ->all();
    }

    /**
     * Убрать из зеркала то, чего больше нет на витрине.
     *
     * Товар сняли с публикации или удалили — в зеркале он останется навсегда,
     * и бот будет советовать то, чего покупатель не купит. У нас это не
     * редкость: YML-деактивация снимает товары пачками, когда поставщик убрал
     * их из фида.
     */
    private function prune(CatalogSemanticIndex $index): void
    {
        $gone = DB::table('product_embeddings')
            ->leftJoin('products', 'products.id', '=', 'product_embeddings.product_id')
            ->where(function ($query): void {
                $query->whereNull('products.id')->orWhere('products.is_active', false);
            })
            ->pluck('product_embeddings.product_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($gone === []) {
            return;
        }

        $index->forget($gone);
        DB::table('product_embeddings')->whereIn('product_id', $gone)->delete();

        $this->info('Убрано из зеркала снятых с публикации: '.count($gone).'.');
    }
}
