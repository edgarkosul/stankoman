<?php

use App\Services\Ai\Providers\FakeLlmClient;
use App\Services\Ai\Support\PiiRedactor;
use App\Services\Ai\Tools\SearchKnowledgeBaseTool;
use App\Services\Ai\Tools\ToolContext;
use App\Services\Kb\KbVectorStore;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
