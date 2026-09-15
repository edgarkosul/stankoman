<?php

use App\Filament\Pages\AssistantSandbox;
use App\Models\ChatMessage;
use App\Services\Chat\ChatMessageTelemetry;
use Tests\TestCase;

// Песочница показывает разбор тем же кодом, что и лента диалога.
// Здесь проверяется стык: ход, сохранённый простыми типами, снова
// становится сообщением, которое телеметрия понимает.
uses(TestCase::class);

it('собирает из хода сообщение, понятное телеметрии', function (): void {
    $turn = [
        'role' => 'assistant',
        'text' => 'Оплатить можно по счёту.',
        'stop_reason' => 'stop',
        'tool_calls' => [['name' => 'search_knowledge_base', 'arguments' => ['query' => 'оплата по счёту'], 'ms' => 820]],
        'citations' => [['chunk_id' => 'intertooler-page:dostavka-i-oplata#1', 'score' => 0.61, 'title' => 'Доставка и оплата', 'url' => null]],
        'input_tokens' => 3200,
        'output_tokens' => 180,
        'cached_tokens' => 2816,
        'cost_rub' => 0.062,
        'latency_ms' => 8400,
        'kb_miss' => false,
    ];

    $message = (new AssistantSandbox)->messageFor($turn);

    expect($message)->toBeInstanceOf(ChatMessage::class)
        ->and($message->role)->toBe(ChatMessage::ROLE_ASSISTANT)
        // Не сохранена и сохранена не будет: разбор не должен оседать
        // в переписке и попадать потом в «Пробелы» как живой вопрос.
        ->and($message->exists)->toBeFalse()
        ->and(ChatMessageTelemetry::for($message)->toolPhrases)->toBe(['искал в базе знаний'])
        ->and(ChatMessageTelemetry::for($message)->sources[0]['title'])->toBe('Доставка и оплата');
});

it('переживает ход без инструментов и без цитат', function (): void {
    // Ответ, собранный без поиска, — штатный случай: так бот отвечает
    // на «здравствуйте» и на посторонние вопросы.
    $message = (new AssistantSandbox)->messageFor(['text' => 'Я консультирую только по вопросам магазина.']);

    expect(ChatMessageTelemetry::for($message)->toolPhrases)->toBe([])
        ->and(ChatMessageTelemetry::for($message)->sources)->toBe([])
        ->and($message->cost_rub)->toBe(0.0);
});

it('складывает стоимость всех ходов разбора', function (): void {
    $page = new AssistantSandbox;
    $page->turns = [
        ['role' => 'visitor', 'text' => 'вопрос'],
        ['role' => 'assistant', 'text' => 'ответ', 'cost_rub' => 0.062],
        ['role' => 'visitor', 'text' => 'ещё вопрос'],
        ['role' => 'assistant', 'text' => 'ответ', 'cost_rub' => 0.038],
    ];

    expect($page->totalCost())->toBe(0.1);
});
