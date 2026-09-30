<?php

use App\Jobs\ReindexKbDocumentJob;
use App\Models\KbArticle;
use App\Models\Page;
use App\Services\Kb\KbVectorIndexer;
use App\Services\Kb\Sources\KbArticleKbSource;
use App\Shop\PageKbSource;
use Database\Factories\KbArticleFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
});

$delivery = fn (array $attributes = []): Page => Page::factory()->create([
    'slug' => 'dostavka-i-oplata',
    'title' => 'Доставка и оплата',
    'content' => '<p>Везём по всей России, срок 3–5 дней.</p>',
    'is_published' => true,
    ...$attributes,
]);

$reindex = fn (string $source, string $key) => (new ReindexKbDocumentJob($source, $key))->handle(app(KbVectorIndexer::class));

it('ставит правку страницы из базы знаний в очередь ассистента', function () use ($delivery): void {
    $delivery();

    Queue::assertPushed(ReindexKbDocumentJob::class, fn (ReindexKbDocumentJob $job): bool => $job->source === PageKbSource::NAME
        && $job->docKey === 'dostavka-i-oplata'
        && $job->connection === 'redis-assistant'
        && $job->queue === 'assistant'
        && $job->afterCommit === true);
});

it('не будит воркер ради страницы вне базы знаний', function (): void {
    Page::factory()->create(['slug' => 'karera', 'content' => '<p>Ищем менеджера.</p>', 'is_published' => true]);

    Queue::assertNotPushed(ReindexKbDocumentJob::class);
});

it('не переиндексирует страницу, у которой поменялись только SEO-поля', function () use ($delivery): void {
    $page = $delivery();
    Queue::fake();

    $page->fresh()->update(['meta_title' => 'Доставка станков по России']);

    Queue::assertNotPushed(ReindexKbDocumentJob::class);
});

it('после переименования снимает страницу и под прежним адресом', function () use ($delivery): void {
    $page = $delivery();
    Queue::fake();

    $page->fresh()->update(['slug' => 'dostavka']);

    // Новый адрес вне белого списка, старый — в нём: фрагменты под старым
    // ключом должны уйти, иначе бот даст ссылку на 404.
    Queue::assertPushed(ReindexKbDocumentJob::class, 1);
    Queue::assertPushed(ReindexKbDocumentJob::class, fn (ReindexKbDocumentJob $job): bool => $job->docKey === 'dostavka-i-oplata');
});

it('ставит в очередь статью при сохранении и при удалении', function (): void {
    $article = KbArticle::factory()->create();
    $article->delete();

    Queue::assertPushed(ReindexKbDocumentJob::class, 2);
    Queue::assertPushed(ReindexKbDocumentJob::class, fn (ReindexKbDocumentJob $job): bool => $job->source === KbArticleKbSource::NAME
        && $job->docKey === (string) $article->id);
});

it('индексирует страницу и снимает её фрагменты после снятия с публикации', function () use ($delivery, $reindex): void {
    $page = $delivery();
    $chunks = fn (): int => DB::table('kb_chunks')->where('source', PageKbSource::NAME)->where('doc_key', 'dostavka-i-oplata')->count();

    $reindex(PageKbSource::NAME, 'dostavka-i-oplata');
    expect($chunks())->toBe(1);

    $page->update(['is_published' => false]);
    $reindex(PageKbSource::NAME, 'dostavka-i-oplata');
    expect($chunks())->toBe(0);
});

it('отмечает у статьи, что правка доехала до индекса', function () use ($reindex): void {
    $article = KbArticle::factory()->create(['content' => KbArticleFactory::document('Гарантия на станки — 12 месяцев.')]);

    $reindex(KbArticleKbSource::NAME, (string) $article->id);

    expect($article->fresh()->indexed_at)->not->toBeNull()
        ->and($article->fresh()->chunks_count)->toBe(1);

    // Черновик из индекса уходит, и отметка это показывает.
    $article->update(['is_published' => false]);
    $reindex(KbArticleKbSource::NAME, (string) $article->id);

    expect($article->fresh()->chunks_count)->toBe(0)
        ->and(DB::table('kb_chunks')->where('source', KbArticleKbSource::NAME)->count())->toBe(0);
});

it('пропускает неизвестный источник, ничего не трогая', function () use ($reindex): void {
    $reindex('kraton-page', 'dostavka-i-oplata');

    expect(DB::table('kb_chunks')->count())->toBe(0);
});
