<?php

use App\Services\Ai\Contracts\LlmClient;
use App\Services\Ai\Exceptions\LlmException;
use App\Services\Ai\Providers\FakeLlmClient;
use App\Services\Ai\Support\PiiRedactor;
use App\Services\Ai\Tools\SearchKnowledgeBaseTool;
use App\Services\Ai\Tools\ToolContext;
use App\Services\Kb\KbVectorStore;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Таблицу фрагментов поднимаем руками, а не миграциями: проекту нужен
 * ровно этот кусок схемы, и прогон всех миграций на sqlite здесь ни к чему.
 */
function kbTable(): void
{
    Schema::create('kb_chunks', function (Blueprint $table): void {
        $table->id();
        $table->string('chunk_id');
        $table->string('source');
        $table->string('url')->nullable();
        $table->string('title');
        $table->json('breadcrumb')->nullable();
        $table->json('section_path')->nullable();
        $table->text('text');
        $table->binary('embedding')->nullable();
    });
}

function kbChunk(KbVectorStore $store, FakeLlmClient $llm, string $text): void
{
    DB::table('kb_chunks')->insert([
        'chunk_id' => 'kb:'.md5($text),
        'source' => 'intertooler-kb',
        'url' => 'https://intertooler.ru/page/dostavka-i-oplata',
        'title' => 'Доставка и оплата',
        'breadcrumb' => '[]',
        'section_path' => '[]',
        'text' => $text,
        'embedding' => KbVectorStore::packVector($store->normalize($llm->embed([$text])->first())),
    ]);
}

function kbTool(KbVectorStore $store): SearchKnowledgeBaseTool
{
    return new SearchKnowledgeBaseTool($store, new PiiRedactor, minScore: 0.0, topK: 3);
}

it('оставляет в ходе вектор вопроса — он уже посчитан поиском', function (): void {
    kbTable();

    $llm = new FakeLlmClient(16);
    $store = new KbVectorStore($llm, 'kb_chunks', 5);
    kbChunk($store, $llm, 'Оплатить заказ можно картой на сайте или по счёту.');

    $context = new ToolContext;
    kbTool($store)->run(['query' => 'как оплатить заказ'], $context);

    // 16 чисел единичной длины — ровно то, что уйдёт в chat_messages.embedding
    // и по чему «Пробелы» будут группировать вопросы.
    expect($context->questionEmbedding)->toHaveCount(16);

    $norm = sqrt(array_sum(array_map(
        static fn (float $v): float => $v * $v,
        $context->questionEmbedding,
    )));

    expect($norm)->toBeGreaterThan(0.9999)->toBeLessThan(1.0001);
});

it('запоминает первый поиск за ход, а не последний', function (): void {
    kbTable();

    $llm = new FakeLlmClient(16);
    $store = new KbVectorStore($llm, 'kb_chunks', 5);
    kbChunk($store, $llm, 'Оплатить заказ можно картой на сайте или по счёту.');

    $tool = kbTool($store);
    $context = new ToolContext;

    // Первый вызов — вопрос покупателя. Второй в том же ходе — уточнение
    // по ходу мысли модели; считать его отдельным вопросом нельзя, иначе
    // один вопрос попадёт в «Пробелы» дважды.
    $tool->run(['query' => 'как оплатить заказ'], $context);
    $question = $context->questionEmbedding;

    $tool->run(['query' => 'сроки доставки в Крым'], $context);

    expect($context->questionEmbedding)->toBe($question);
});

it('не выдумывает вектор, когда искать нечего', function (): void {
    $store = new KbVectorStore(new FakeLlmClient(16), 'kb_chunks', 5);
    $context = new ToolContext;

    kbTool($store)->run(['query' => '  '], $context);

    expect($context->questionEmbedding)->toBeNull();
});

it('объявляет и советы по ассортименту, и границу с каталогом', function (): void {
    /*
     * Описание инструмента — единственное, по чему модель решает, идти ли
     * сюда. Пока в нём стояли одни условия магазина, на «какая винтовая пара
     * в компрессорах CrossAir» она делала восемь вызовов каталога (потолок
     * max_iterations) и 0.82 ₽ вместо одного вызова сюда: статью с этим
     * ответом ей искать не разрешали.
     *
     * Граница в том же описании не менее важна: без неё модель начнёт брать
     * цену из статьи, которая стареет, вместо живой карточки.
     */
    $description = (new SearchKnowledgeBaseTool(
        store: new KbVectorStore(new FakeLlmClient(4), 'qwen3-embedding-8b', 4),
        redactor: new PiiRedactor,
        minScore: 0.42,
        topK: 5,
    ))->definition()['function']['description'];

    expect($description)
        ->toContain('СОВЕТУЕТ')
        ->toContain('ЦЕНУ, НАЛИЧИЕ И ХАРАКТЕРИСТИКИ конкретной модели здесь не ищи')
        ->toContain('прав каталог');
});

/** Шлюз эмбеддингов, который висит до таймаута. */
function kbDeadGateway(): LlmClient
{
    $llm = Mockery::mock(LlmClient::class);
    $llm->shouldReceive('embed')->andThrow(new LlmException('Шлюз недоступен: cURL error 28'));

    return $llm;
}

function kbWordChunk(string $chunkId, string $title, string $text): void
{
    DB::table('kb_chunks')->insert([
        'chunk_id' => $chunkId,
        'source' => 'intertooler-page',
        'url' => 'https://intertooler.ru/page/'.$chunkId,
        'title' => $title,
        'breadcrumb' => '[]',
        'section_path' => '[]',
        'text' => $text,
        'embedding' => null,
    ]);
}

it('без шлюза эмбеддингов отвечает из базы по словам, а не отправляет к менеджеру', function (): void {
    kbTable();
    kbWordChunk('dostavka', 'Доставка', 'Доставка по Москве — курьером на следующий день, в регионы — транспортной компанией.');
    kbWordChunk('garantiya', 'Гарантия', 'Гарантия на компрессоры — 12 месяцев, на станки — до 24 месяцев.');
    kbWordChunk('oplata', 'Оплата', 'Оплатить заказ можно картой на сайте или по счёту для юрлиц.');

    $store = new KbVectorStore(kbDeadGateway(), 'kb_chunks', 5);
    $context = new ToolContext;

    Log::spy();

    $result = kbTool($store)->run(['query' => 'Как оформить доставку в регионы?'], $context);

    expect($result)->toContain('транспортной компанией')
        ->not->toContain('Гарантия на компрессоры')
        ->and($context->citations[0]['url'])->toBe('https://intertooler.ru/page/dostavka')
        ->and($context->kbDegraded)->toBeTrue()
        // Доля слов — не близость по смыслу: в «Пробелы» сбой шлюза не попадает.
        ->and($context->bestScore)->toBeNull()
        ->and($context->questionEmbedding)->toBeNull();

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'ищу по словам'));
});

it('без шлюза и без совпадений просит не выдумывать', function (): void {
    kbTable();
    kbWordChunk('oplata', 'Оплата', 'Оплатить заказ можно картой на сайте или по счёту.');

    $store = new KbVectorStore(kbDeadGateway(), 'kb_chunks', 5);
    $context = new ToolContext;

    $result = kbTool($store)->run(['query' => 'гарантия на компрессор'], $context);

    expect($result)->toContain('Не выдумывай')
        ->and($context->citations)->toBe([])
        ->and($context->kbDegraded)->toBeTrue();
});

it('выделяет из вопроса основы значимых слов', function (): void {
    expect(KbVectorStore::stems('Как оформить доставку в регионы?'))
        ->toBe(['оформ', 'доста', 'регио'])
        ->and(KbVectorStore::stems('Сколько стоит CrossAir 2008?'))
        ->toBe(['стои', 'crossair', '2008']);
});
