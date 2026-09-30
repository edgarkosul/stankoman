<?php

use App\Services\Ai\Data\AssistantReply;
use App\Services\Chat\ChatAnswerCache;
use Tests\TestCase;

uses(TestCase::class);

/*
 * Кэш ответов проверяется без базы и без сети. Правила отбора важнее самого
 * кэша: лишний вызов модели стоит копейки, а неверный ответ из кэша —
 * покупателя, и заметить его некому.
 */

function chatAnswerCache(int $ttl = 604800, float $minScore = 0.42): ChatAnswerCache
{
    return new ChatAnswerCache(ttl: $ttl, minScore: $minScore);
}

function chatCachedReply(
    string $text = 'Оплатить можно картой или по счёту.',
    string $stopReason = 'stop',
    array $toolCalls = [['name' => 'search_knowledge_base', 'arguments' => [], 'ms' => 12]],
    bool $escalated = false,
    bool $callbackRequested = false,
    ?float $bestScore = 0.54,
): AssistantReply {
    return new AssistantReply(
        text: $text,
        stopReason: $stopReason,
        toolCalls: $toolCalls,
        citations: [['chunk_id' => 'intertooler-page:dostavka-i-oplata:1', 'score' => 0.54]],
        escalated: $escalated,
        callbackRequested: $callbackRequested,
        bestScore: $bestScore,
    );
}

it('отдаёт сохранённый ответ на ту же формулировку с точностью до регистра и знаков', function (): void {
    $cache = chatAnswerCache();
    $fingerprint = ['model' => 'deepseek-v4-flash'];

    $cache->put('Как оплатить заказ?', $fingerprint, chatCachedReply());

    foreach (['Как оплатить заказ?', 'как оплатить заказ', 'КАК ОПЛАТИТЬ ЗАКАЗ!!!', '  Как   оплатить, заказ?  '] as $variant) {
        expect($cache->get($variant, $fingerprint)['text'] ?? null)->toBe('Оплатить можно картой или по счёту.');
    }

    expect($cache->get('Как вернуть заказ?', $fingerprint))->toBeNull();
});

it('считает вопрос с другой страницы или после правки настроек другим вопросом', function (): void {
    $cache = chatAnswerCache();

    $cache->put('Есть в наличии?', ['page' => 'карточка товара', 'settings' => 'a'], chatCachedReply());

    expect($cache->get('Есть в наличии?', ['page' => 'страница оплаты', 'settings' => 'a']))->toBeNull()
        ->and($cache->get('Есть в наличии?', ['page' => 'карточка товара', 'settings' => 'b']))->toBeNull();
});

it('не кэширует ничего при нулевом сроке жизни', function (): void {
    $cache = chatAnswerCache(ttl: 0);

    $cache->put('Как оплатить?', [], chatCachedReply());

    expect($cache->get('Как оплатить?', []))->toBeNull();
});

it('кэширует удачный ответ по базе знаний на первом ходе, и только его', function (): void {
    $cache = chatAnswerCache();

    expect($cache->isCacheable(chatCachedReply(), firstTurn: true))->toBeTrue()
        // «А сколько он стоит?» без предыдущей реплики не значит ничего.
        ->and($cache->isCacheable(chatCachedReply(), firstTurn: false))->toBeFalse()
        // Состояния разговора, а не ответы.
        ->and($cache->isCacheable(chatCachedReply(escalated: true), firstTurn: true))->toBeFalse()
        ->and($cache->isCacheable(chatCachedReply(callbackRequested: true), firstTurn: true))->toBeFalse()
        ->and($cache->isCacheable(chatCachedReply(stopReason: 'max_iterations'), firstTurn: true))->toBeFalse()
        ->and($cache->isCacheable(chatCachedReply(text: '   '), firstTurn: true))->toBeFalse();
});

it('не кэширует ответы с живыми данными о товаре', function (): void {
    // «В наличии, 45 900 руб.» недельной свежести — худший из возможных
    // ответов: он выглядит точным.
    $withProduct = chatCachedReply(toolCalls: [
        ['name' => 'search_knowledge_base', 'arguments' => [], 'ms' => 10],
        ['name' => 'get_product', 'arguments' => [], 'ms' => 24],
    ]);

    expect(chatAnswerCache()->isCacheable($withProduct, firstTurn: true))->toBeFalse();
});

it('не кэширует промах по базе знаний — это сырьё «Пробелов»', function (): void {
    expect(chatAnswerCache()->isCacheable(chatCachedReply(bestScore: 0.21), firstTurn: true))->toBeFalse();
});
