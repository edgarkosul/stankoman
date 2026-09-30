<?php

namespace App\Jobs;

use App\Models\KbArticle;
use App\Providers\AiSupportServiceProvider;
use App\Services\Kb\Contracts\KbSource;
use App\Services\Kb\Data\KbDocument;
use App\Services\Kb\KbVectorIndexer;
use App\Services\Kb\Sources\KbArticleKbSource;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Переиндексация одного документа после правки в админке.
 *
 * Асинхронно, потому что внутри вызов эмбеддингов по сети: админ нажал
 * «Сохранить» и должен увидеть сохранённую страницу, а не крутящийся
 * индикатор на полторы секунды. Инкрементность делает своё — фрагменты
 * с прежним содержимым переиспользуются, и правка одного абзаца стоит
 * одного вызова, а не целой статьи.
 *
 * Документ мог исчезнуть из источника: статью сняли с публикации, удалили,
 * очистили текст, страницу переименовали. Это НЕ ошибка, а штатный исход —
 * в этом случае удаляем его фрагменты, иначе бот продолжит цитировать снятое.
 */
class ReindexKbDocumentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Повтор безопасен: индексация идемпотентна по content_hash. */
    public int $tries = 3;

    /** Меньше таймаута воркера ассистента — см. AssistantQueueConnectionTest. */
    public int $timeout = 120;

    public function __construct(
        public readonly string $source,
        public readonly string $docKey,
    ) {
        // Та же очередь, что у ответов бота: там воркер с большим таймаутом,
        // а сетевой вызов эмбеддингов ведёт себя так же непредсказуемо.
        //
        // После коммита — потому что ставят её наблюдатели, а сохранение может
        // идти в транзакции: воркер берёт задачу за миллисекунды и прочёл бы
        // документ ДО коммита, то есть проиндексировал бы прежний текст до ночи.
        $this->onConnection('redis-assistant')->onQueue('assistant')->afterCommit();
    }

    public function handle(KbVectorIndexer $indexer): void
    {
        $source = $this->resolveSource();

        if ($source === null) {
            Log::warning('Kb reindex skipped: unknown source', ['source' => $this->source]);

            return;
        }

        try {
            $document = $this->findDocument($source);
        } catch (Throwable $e) {
            Log::warning('Kb reindex failed to read source', [
                'source' => $this->source,
                'doc_key' => $this->docKey,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        if ($document === null) {
            $deleted = $indexer->forgetDocument($this->source, $this->docKey);
            $this->stamp(0);

            Log::info('Kb document removed from index', [
                'source' => $this->source,
                'doc_key' => $this->docKey,
                'chunks_deleted' => $deleted,
            ]);

            return;
        }

        $stats = $indexer->indexDocument($this->source, $document);
        $this->stamp($stats->chunks);

        Log::info('Kb document reindexed', [
            'source' => $this->source,
            'doc_key' => $this->docKey,
            'chunks' => $stats->chunks,
            'embedded' => $stats->embedded,
            'reused' => $stats->reused,
            'cost_rub' => $stats->costRub,
        ]);
    }

    private function resolveSource(): ?KbSource
    {
        foreach (AiSupportServiceProvider::sources() as $source) {
            if ($source->name() === $this->source) {
                return $source;
            }
        }

        return null;
    }

    /**
     * Ищем документ перебором генератора источника с ранним выходом.
     *
     * Отдельного «найди по ключу» в интерфейсе KbSource нет намеренно: он
     * держит границу между «откуда контент» и «как индексируется», и чем
     * этот интерфейс беднее, тем дешевле завести следующий источник.
     * Цена перебора — половина корпуса в среднем, а корпус базы знаний
     * это десятки документов, не десятки тысяч.
     */
    private function findDocument(KbSource $source): ?KbDocument
    {
        foreach ($source->documents() as $document) {
            if ($document->key === $this->docKey) {
                return $document;
            }
        }

        return null;
    }

    /**
     * Отметка «доехало до индекса» в самой статье. Без неё админ правит текст
     * и не знает, применилось ли: очередь работает молча.
     *
     * saveQuietly — иначе обсервер увидит сохранение и поставит в очередь
     * новую переиндексацию, и так по кругу.
     */
    private function stamp(int $chunks): void
    {
        if ($this->source !== KbArticleKbSource::NAME) {
            return;
        }

        $article = KbArticle::query()->withTrashed()->find((int) $this->docKey);

        $article?->forceFill([
            'indexed_at' => now(),
            'chunks_count' => $chunks,
        ])->saveQuietly();
    }
}
