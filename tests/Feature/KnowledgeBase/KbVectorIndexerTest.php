<?php

use App\Services\Ai\Contracts\LlmClient;
use App\Services\Ai\Data\ChatResult;
use App\Services\Ai\Data\EmbeddingBatch;
use App\Services\Ai\Providers\FakeLlmClient;
use App\Services\Kb\Contracts\KbSource;
use App\Services\Kb\Data\KbDocument;
use App\Services\Kb\KbChunker;
use App\Services\Kb\KbVectorIndexer;
use App\Services\Kb\KbVectorStore;
use Illuminate\Support\Facades\DB;

/*
 * Инкрементность — то, за что здесь платят деньгами: ночной проход идёт
 * по всему корпусу, и пересчёт неизменившегося текста оплачивался бы
 * каждую ночь. Поэтому считаем именно вызовы шлюза, а не только строки.
 */

/**
 * Заглушка шлюза, которая помнит, сколько раз и сколько текстов у неё просили.
 */
function countingLlm(): LlmClient
{
    return new class(new FakeLlmClient(32)) implements LlmClient
    {
        public int $calls = 0;

        public int $texts = 0;

        public function __construct(private readonly FakeLlmClient $inner) {}

        public function chat(string $system, array $messages, array $tools = [], ?int $maxTokens = null, ?string $sessionId = null, ?string $toolChoice = null): ChatResult
        {
            return $this->inner->chat($system, $messages, $tools, $maxTokens, $sessionId, $toolChoice);
        }

        public function embed(array $texts, string $mode = 'doc'): EmbeddingBatch
        {
            $this->calls++;
            $this->texts += count($texts);

            return $this->inner->embed($texts, $mode);
        }

        public function chatModel(): string
        {
            return $this->inner->chatModel();
        }

        public function embeddingModel(): string
        {
            return $this->inner->embeddingModel();
        }

        public function embeddingDimensions(): int
        {
            return $this->inner->embeddingDimensions();
        }
    };
}

function deliveryPage(string $payment = 'Платите картой или по счёту.'): KbDocument
{
    return new KbDocument(
        key: 'dostavka-i-oplata',
        title: 'Доставка и оплата',
        breadcrumb: ['InterTooler.ru', 'Доставка и оплата'],
        text: "## Доставка\n\nВезём по всей России.\n\n## Оплата\n\n{$payment}",
        url: 'https://intertooler.ru/page/dostavka-i-oplata',
    );
}

/**
 * @param  list<KbDocument>  $documents
 */
function pagesSource(array $documents): KbSource
{
    return new class($documents) implements KbSource
    {
        /** @param  list<KbDocument>  $documents */
        public function __construct(private readonly array $documents) {}

        public function name(): string
        {
            return 'intertooler-page';
        }

        public function documents(): iterable
        {
            return $this->documents;
        }
    };
}

it('пишет фрагменты вместе с вектором, моделью и размерностью', function (): void {
    $indexer = new KbVectorIndexer(countingLlm(), new KbChunker, 'kb_chunks');

    $stats = $indexer->indexDocument('intertooler-page', deliveryPage());

    $rows = DB::table('kb_chunks')->orderBy('chunk_id')->get();

    expect($stats->chunks)->toBe(2)
        ->and($stats->embedded)->toBe(2)
        ->and($stats->reused)->toBe(0)
        ->and($rows->pluck('chunk_id')->all())->toBe(['intertooler-page:dostavka-i-oplata#0', 'intertooler-page:dostavka-i-oplata#1'])
        ->and($rows->pluck('dim')->unique()->all())->toBe([32])
        ->and($rows->pluck('embed_model')->unique()->all())->toBe(['fake-embedding'])
        ->and(KbVectorStore::unpackVector($rows[0]->embedding))->toHaveCount(32);
});

it('не платит второй раз за неизменившийся текст', function (): void {
    $llm = countingLlm();
    $indexer = new KbVectorIndexer($llm, new KbChunker, 'kb_chunks');

    $indexer->indexDocument('intertooler-page', deliveryPage());
    $again = $indexer->indexDocument('intertooler-page', deliveryPage());

    expect($again->embedded)->toBe(0)
        ->and($again->reused)->toBe(2)
        ->and($llm->calls)->toBe(1);
});

it('пересчитывает только тот раздел, который поменялся', function (): void {
    $llm = countingLlm();
    $indexer = new KbVectorIndexer($llm, new KbChunker, 'kb_chunks');

    $indexer->indexDocument('intertooler-page', deliveryPage());
    $edited = $indexer->indexDocument('intertooler-page', deliveryPage('Только по счёту для организаций.'));

    expect($edited->embedded)->toBe(1)
        ->and($edited->reused)->toBe(1)
        ->and($llm->texts)->toBe(3);
});

it('сносит фрагменты разделов, которых в документе больше нет', function (): void {
    $indexer = new KbVectorIndexer(countingLlm(), new KbChunker, 'kb_chunks');

    $indexer->indexDocument('intertooler-page', deliveryPage());

    $shorter = new KbDocument('dostavka-i-oplata', 'Доставка и оплата', ['InterTooler.ru', 'Доставка и оплата'], "## Доставка\n\nВезём по всей России.");
    $stats = $indexer->indexDocument('intertooler-page', $shorter);

    expect($stats->deleted)->toBe(1)
        ->and(DB::table('kb_chunks')->count())->toBe(1);
});

it('с prune убирает документы, которых источник больше не отдаёт', function (): void {
    $indexer = new KbVectorIndexer(countingLlm(), new KbChunker, 'kb_chunks');
    $contacts = new KbDocument('kontakty', 'Контакты', ['InterTooler.ru', 'Контакты'], 'Краснодар, ул. Андреевская, 2');

    $indexer->indexSource(pagesSource([deliveryPage(), $contacts]));
    $stats = $indexer->indexSource(pagesSource([$contacts]), prune: true);

    expect($stats->deleted)->toBe(2)
        ->and(DB::table('kb_chunks')->pluck('doc_key')->unique()->all())->toBe(['kontakty']);
});

it('забывает документ целиком', function (): void {
    $indexer = new KbVectorIndexer(countingLlm(), new KbChunker, 'kb_chunks');

    $indexer->indexDocument('intertooler-page', deliveryPage());

    expect($indexer->forgetDocument('intertooler-page', 'dostavka-i-oplata'))->toBe(2)
        ->and(DB::table('kb_chunks')->count())->toBe(0);
});

it('находит фрагмент по вопросу и отдаёт его путь и ссылку', function (): void {
    $llm = countingLlm();
    (new KbVectorIndexer($llm, new KbChunker, 'kb_chunks'))->indexDocument('intertooler-page', deliveryPage());
    $store = new KbVectorStore($llm, 'kb_chunks', 5);

    // У заглушки близость осмысленна только для совпадающего текста — поэтому
    // спрашиваем ровно текстом фрагмента: проверяется тракт, а не качество.
    $text = DB::table('kb_chunks')->where('chunk_id', 'intertooler-page:dostavka-i-oplata#1')->value('text');
    $hits = $store->search($text);

    expect($hits)->toHaveCount(2)
        ->and($hits[0]->chunkId)->toBe('intertooler-page:dostavka-i-oplata#1')
        ->and($hits[0]->score)->toBeGreaterThan(0.9999)
        ->and($hits[0]->url)->toBe('https://intertooler.ru/page/dostavka-i-oplata')
        ->and($hits[0]->path())->toBe('InterTooler.ru › Доставка и оплата › Оплата')
        ->and($store->search($text, sources: ['intertooler-kb']))->toBe([]);
});
